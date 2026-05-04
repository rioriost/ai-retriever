<?php
/**
 * Bulk indexing pipeline for initial backfill jobs.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

use WPRetriever\Database\LocalVectorRepository;
use WPRetriever\Embedding\EmbeddingProviderFactory;
use WPRetriever\Embedding\EmbeddingProviderInterface;

final class BulkBackfillIndexer
{
    private const EMBEDDING_CHUNK_BATCH_SIZE = 96;

    private function __construct() {}

    /**
     * @param int[] $post_ids
     * @return array{errors:int,failed_ids:int[]}
     */
    public static function process_posts(array $post_ids): array
    {
        $post_ids = array_values(
            array_filter(
                array_map("intval", $post_ids),
                static fn(int $post_id): bool => $post_id > 0,
            ),
        );
        if ($post_ids === []) {
            return ["errors" => 0, "failed_ids" => []];
        }

        $embedder = EmbeddingProviderFactory::make();
        $model = $embedder->model();
        $repository = new LocalVectorRepository();
        $items = [];
        $chunk_jobs = [];
        $failed = [];

        foreach ($post_ids as $post_id) {
            if (!PostFilter::is_eligible($post_id)) {
                $repository->delete_post($post_id);
                delete_post_meta($post_id, WP_RETRIEVER_POSTMETA_LAST_ERROR);
                continue;
            }

            $text = PostSync::post_text($post_id);
            $hash = hash(
                "sha256",
                $text .
                    "|" .
                    $model .
                    "|" .
                    (string) Settings::get("embedding_dimensions"),
            );
            if (
                get_post_meta(
                    $post_id,
                    WP_RETRIEVER_POSTMETA_CONTENT_HASH,
                    true,
                ) === $hash
            ) {
                delete_post_meta($post_id, WP_RETRIEVER_POSTMETA_LAST_ERROR);
                continue;
            }

            $chunks = PostSync::chunk_text($text);
            if ($chunks === []) {
                $repository->delete_post($post_id);
                update_post_meta(
                    $post_id,
                    WP_RETRIEVER_POSTMETA_CONTENT_HASH,
                    $hash,
                );
                update_post_meta(
                    $post_id,
                    WP_RETRIEVER_POSTMETA_INDEXED_AT,
                    time(),
                );
                delete_post_meta($post_id, WP_RETRIEVER_POSTMETA_LAST_ERROR);
                continue;
            }

            $items[$post_id] = [
                "post_id" => $post_id,
                "model" => $model,
                "content_hash" => $hash,
                "chunks" => $chunks,
                "embeddings" => [],
            ];

            foreach ($chunks as $chunk_index => $chunk) {
                $chunk_jobs[] = [
                    "post_id" => $post_id,
                    "chunk_index" => (int) $chunk_index,
                    "text" => $chunk,
                ];
            }
        }

        foreach (
            array_chunk($chunk_jobs, self::EMBEDDING_CHUNK_BATCH_SIZE)
            as $job_batch
        ) {
            self::embed_chunk_batch($embedder, $job_batch, $items, $failed);
        }

        $ready_items = [];
        foreach ($items as $post_id => $item) {
            $post_id = (int) $post_id;
            if (isset($failed[$post_id])) {
                update_post_meta(
                    $post_id,
                    WP_RETRIEVER_POSTMETA_LAST_ERROR,
                    (string) $failed[$post_id],
                );
                continue;
            }

            $chunks = is_array($item["chunks"] ?? null) ? $item["chunks"] : [];
            $embeddings = is_array($item["embeddings"] ?? null)
                ? $item["embeddings"]
                : [];
            ksort($embeddings);
            if (count($embeddings) !== count($chunks)) {
                $failed[$post_id] =
                    "Embedding response count did not match chunk count.";
                update_post_meta(
                    $post_id,
                    WP_RETRIEVER_POSTMETA_LAST_ERROR,
                    (string) $failed[$post_id],
                );
                continue;
            }

            $ready_items[$post_id] = [
                "post_id" => $post_id,
                "model" => (string) $item["model"],
                "content_hash" => (string) $item["content_hash"],
                "chunks" => $chunks,
                "embeddings" => array_values($embeddings),
            ];
        }

        if ($ready_items !== []) {
            $repository->replace_many_post_embeddings(
                array_values($ready_items),
            );
        }

        foreach ($ready_items as $post_id => $item) {
            update_post_meta(
                $post_id,
                WP_RETRIEVER_POSTMETA_CONTENT_HASH,
                (string) $item["content_hash"],
            );
            update_post_meta(
                $post_id,
                WP_RETRIEVER_POSTMETA_INDEXED_AT,
                time(),
            );
            delete_post_meta($post_id, WP_RETRIEVER_POSTMETA_LAST_ERROR);
        }

        if ($ready_items !== []) {
            SearchInterceptor::purge_query_cache();
        }

        $failed_ids = array_values(array_map("intval", array_keys($failed)));
        return ["errors" => count($failed_ids), "failed_ids" => $failed_ids];
    }

    /**
     * @param array<int,array{post_id:int,chunk_index:int,text:string}> $jobs
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string> $failed
     */
    private static function embed_chunk_batch(
        EmbeddingProviderInterface $embedder,
        array $jobs,
        array &$items,
        array &$failed,
    ): void {
        if ($jobs === []) {
            return;
        }

        try {
            $texts = array_map(
                static fn(array $job): string => (string) $job["text"],
                $jobs,
            );
            $embeddings = $embedder->embed_many($texts);
            if (count($embeddings) !== count($jobs)) {
                throw new \RuntimeException(
                    "Embedding response count did not match request count.",
                );
            }

            foreach ($jobs as $offset => $job) {
                $post_id = (int) $job["post_id"];
                $chunk_index = (int) $job["chunk_index"];
                if (isset($failed[$post_id]) || !isset($items[$post_id])) {
                    continue;
                }
                $embedding = $embeddings[$offset] ?? null;
                if (!is_array($embedding) || $embedding === []) {
                    throw new \RuntimeException(
                        "Embedding response was empty.",
                    );
                }
                $items[$post_id]["embeddings"][$chunk_index] = array_map(
                    "floatval",
                    $embedding,
                );
            }
        } catch (\Throwable $e) {
            if (count($jobs) > 1 && self::should_split_failed_batch($e)) {
                $halves = array_chunk($jobs, (int) ceil(count($jobs) / 2));
                foreach ($halves as $half) {
                    self::embed_chunk_batch($embedder, $half, $items, $failed);
                }
                return;
            }

            if (count($jobs) > 1) {
                throw $e;
            }

            $post_id = (int) ($jobs[0]["post_id"] ?? 0);
            if ($post_id > 0) {
                $failed[$post_id] = $e->getMessage();
            }
        }
    }

    private static function should_split_failed_batch(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        foreach (
            [
                "429",
                "401",
                "403",
                "500",
                "502",
                "503",
                "504",
                "rate limit",
                "timeout",
                "timed out",
                "connection",
                "could not resolve",
                "api key",
            ]
            as $transient_or_auth_error
        ) {
            if (str_contains($message, $transient_or_auth_error)) {
                return false;
            }
        }

        foreach (
            [
                "count",
                "too large",
                "maximum",
                "context",
                "token",
                "payload",
                "input",
                "empty",
            ]
            as $batch_shape_error
        ) {
            if (str_contains($message, $batch_shape_error)) {
                return true;
            }
        }

        return false;
    }
}
