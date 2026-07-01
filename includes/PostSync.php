<?php
/**
 * Post indexing hooks.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

use WPRetriever\Database\LocalVectorRepository;
use WPRetriever\Embedding\EmbeddingProviderFactory;

final class PostSync
{
    private function __construct() {}

    public static function register(): void
    {
        add_action("save_post", [self::class, "on_save_post"], 20, 2);
        add_action(
            "before_delete_post",
            [self::class, "on_delete_post"],
            10,
            1,
        );
    }

    public static function on_save_post(int $post_id, $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (
            !(bool) Settings::get("sync_enabled") ||
            (bool) Settings::get("kill_switch_global")
        ) {
            return;
        }
        if (!PostFilter::is_eligible($post_id)) {
            $repository = new LocalVectorRepository();
            $repository->delete_post($post_id);
            return;
        }

        try {
            $text = self::post_text($post_id);
            $embedder = EmbeddingProviderFactory::make();
            $hash = hash(
                "sha256",
                $text .
                    "|" .
                LanguageOptions::selected_locale() .
                    "|" .
                $embedder->model() .
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
                return;
            }
            $chunks = self::chunk_text($text);
            $embeddings = $embedder->embed_many(
                self::embedding_texts_for_chunks($chunks),
            );
            $repository = new LocalVectorRepository();
            $repository->replace_post_embeddings(
                $post_id,
                $embedder->model(),
                $hash,
                $chunks,
                $embeddings,
            );
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
            SearchInterceptor::purge_query_cache();
        } catch (\Throwable $e) {
            update_post_meta(
                $post_id,
                WP_RETRIEVER_POSTMETA_LAST_ERROR,
                $e->getMessage(),
            );
            Logger::error("sync", "post embedding failed", [
                "post_id" => $post_id,
                "error" => $e->getMessage(),
            ]);
        }
    }

    public static function on_delete_post(int $post_id): void
    {
        $repository = new LocalVectorRepository();
        $repository->delete_post($post_id);
        SearchInterceptor::purge_query_cache();
    }

    public static function post_text(int $post_id): string
    {
        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return "";
        }
        $content = wp_strip_all_tags(
            strip_shortcodes((string) $post->post_content),
        );
        $parts = [
            (string) $post->post_title,
            (string) $post->post_excerpt,
            $content,
        ];

        foreach ((array) Settings::get("indexed_custom_fields") as $field_key) {
            $field_key = trim((string) $field_key);
            if ($field_key === "") {
                continue;
            }
            $values = get_post_meta($post_id, $field_key, false);
            foreach ($values as $value) {
                $text = self::value_to_text($value);
                if ($text !== "") {
                    $parts[] = $field_key . ": " . $text;
                }
            }
        }

        foreach ((array) Settings::get("indexed_taxonomies") as $taxonomy) {
            $taxonomy = trim((string) $taxonomy);
            if ($taxonomy === "" || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($post_id, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            $names = [];
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term) {
                    $names[] = $term->name;
                }
            }
            if ($names !== []) {
                $parts[] = $taxonomy . ": " . implode(", ", $names);
            }
        }

        return trim(
            implode(
                "\n\n",
                array_filter(
                    $parts,
                    static fn(string $part): bool => trim($part) !== "",
                ),
            ),
        );
    }

    private static function value_to_text($value): string
    {
        if (is_scalar($value)) {
            return trim(wp_strip_all_tags((string) $value));
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = self::value_to_text($item);
                if ($text !== "") {
                    $parts[] = $text;
                }
            }
            return implode(" ", $parts);
        }
        return "";
    }

    /** @return string[] */
    public static function chunk_text(string $text): array
    {
        $max = max(500, (int) Settings::get("chunk_max_chars"));
        $overlap = min(
            (int) Settings::get("chunk_overlap_chars"),
            (int) floor($max / 3),
        );
        $text = preg_replace("/\s+/", " ", trim($text)) ?? "";
        $chunks = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $chunks[] = substr($text, $offset, $max);
            $offset += max(1, $max - $overlap);
        }
        return array_values(
            array_filter(
                $chunks,
                static fn(string $chunk): bool => trim($chunk) !== "",
            ),
        );
    }

    /** @param string[] $chunks @return string[] */
    public static function embedding_texts_for_chunks(array $chunks): array
    {
        return array_map(
            static fn(string $chunk): string => LanguageOptions::with_embedding_context(
                $chunk,
            ),
            $chunks,
        );
    }
}
