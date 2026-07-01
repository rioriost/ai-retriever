<?php
/**
 * Database access for native vector chunks.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Native VECTOR inserts/searches need explicit SQL for VEC_FromText and distance functions with vetted identifiers.

use RiTriever\Settings;

final class LocalVectorRepository
{
    /**
     * Replace all chunks for one post/model atomically enough for WordPress use.
     *
     * @param int                  $post_id
     * @param string               $model
     * @param string               $content_hash
     * @param array<int, string>   $chunks
     * @param array<int, float[]>  $embeddings
     */
    public function replace_post_embeddings(
        int $post_id,
        string $model,
        string $content_hash,
        array $chunks,
        array $embeddings,
    ): void {
        global $wpdb;
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            $table,
            ["post_id" => $post_id, "embedding_model" => $model],
            ["%d", "%s"],
        );
        foreach ($chunks as $i => $chunk) {
            $embedding = $embeddings[$i] ?? null;
            if (!is_array($embedding)) {
                continue;
            }
            $sql = $wpdb->prepare(
                "INSERT INTO {$table} (post_id, chunk_index, chunk_text, content_hash, embedding_model, embedding, updated_at) VALUES (%d, %d, %s, %s, %s, VEC_FromText(%s), UTC_TIMESTAMP())",
                $post_id,
                $i,
                self::clean_text((string) $chunk),
                $content_hash,
                self::clean_text($model),
                self::vector_text($embedding),
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above; VECTOR expression cannot be represented via wpdb insert.
            $wpdb->query($sql);
        }
    }

    /**
     * @param array<int,array{post_id:int,model:string,content_hash:string,chunks:array<int,string>,embeddings:array<int,array<int,float>>}> $items
     */
    public function replace_many_post_embeddings(array $items): void
    {
        global $wpdb;
        $table = VectorSchema::table_name();

        for ($attempts = 1; $attempts <= 3; ++$attempts) {
            $rows = [];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Transaction control.
            $wpdb->query("START TRANSACTION");
            try {
                foreach ($items as $item) {
                    $post_id = (int) ($item["post_id"] ?? 0);
                    $model = (string) ($item["model"] ?? "");
                    if ($post_id <= 0 || $model === "") {
                        continue;
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $deleted = $wpdb->delete(
                        $table,
                        ["post_id" => $post_id, "embedding_model" => $model],
                        ["%d", "%s"],
                    );
                    if ($deleted === false) {
                        throw new \RuntimeException(
                            "Failed to delete existing vector chunks: " .
                                $wpdb->last_error,
                        );
                    }

                    $chunks = is_array($item["chunks"] ?? null)
                        ? $item["chunks"]
                        : [];
                    $embeddings = is_array($item["embeddings"] ?? null)
                        ? $item["embeddings"]
                        : [];
                    foreach ($chunks as $i => $chunk) {
                        $embedding = $embeddings[$i] ?? null;
                        if (!is_array($embedding)) {
                            continue;
                        }
                        $rows[] = [
                            "post_id" => $post_id,
                            "chunk_index" => (int) $i,
                            "chunk_text" => self::clean_text((string) $chunk),
                            "content_hash" =>
                                (string) ($item["content_hash"] ?? ""),
                            "embedding_model" => self::clean_text($model),
                            "embedding" => self::vector_text($embedding),
                        ];
                    }
                }

                foreach (array_chunk($rows, 100) as $row_batch) {
                    self::insert_rows($table, $row_batch);
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Transaction control.
                $wpdb->query("COMMIT");
                return;
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Transaction control.
                $wpdb->query("ROLLBACK");
                if ($attempts < 3 && self::is_transient_database_error($e)) {
                    usleep(250000 * $attempts);
                    continue;
                }
                throw $e;
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function insert_rows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        global $wpdb;
        $values = [];
        $args = [];
        foreach ($rows as $row) {
            $values[] =
                "(%d, %d, %s, %s, %s, VEC_FromText(%s), UTC_TIMESTAMP())";
            $args[] = (int) $row["post_id"];
            $args[] = (int) $row["chunk_index"];
            $args[] = (string) $row["chunk_text"];
            $args[] = (string) $row["content_hash"];
            $args[] = (string) $row["embedding_model"];
            $args[] = (string) $row["embedding"];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholder fragments are generated internally and values are passed separately.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (post_id, chunk_index, chunk_text, content_hash, embedding_model, embedding, updated_at) VALUES " .
                implode(",", $values),
            ...$args,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above; VECTOR expression cannot be represented via wpdb insert.
        $inserted = $wpdb->query($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        if ($inserted === false) {
            throw new \RuntimeException(
                "Failed to insert vector chunks: " .
                    esc_html($wpdb->last_error),
            );
        }
    }

    public function delete_post(int $post_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            VectorSchema::table_name(),
            ["post_id" => $post_id],
            ["%d"],
        );
    }

    /** @param float[] $query_embedding @return array<int, float> post_id => normalized score */
    public function search(
        array $query_embedding,
        string $model,
        int $top_k,
    ): array {
        $hits = $this->search_with_chunks($query_embedding, $model, $top_k);
        $out = [];
        foreach ($hits as $post_id => $hit) {
            $out[(int) $post_id] = (float) ($hit["score"] ?? 0.0);
        }
        return $out;
    }

    /**
     * @param float[] $query_embedding
     * @return array<int,array{score:float,distance:float,chunk_text:string}> post_id => hit metadata
     */
    public function search_with_chunks(
        array $query_embedding,
        string $model,
        int $top_k,
    ): array {
        global $wpdb;
        $table = VectorSchema::table_name();
        $distance_fn =
            Settings::get("vector_distance") === "euclidean"
                ? "VEC_DISTANCE_EUCLIDEAN"
                : "VEC_DISTANCE_COSINE";
        $chunk_limit = max($top_k, $top_k * 5);
        $sql = $wpdb->prepare(
            "SELECT post_id, chunk_text, {$distance_fn}(embedding, VEC_FromText(%s)) AS distance FROM {$table} WHERE embedding_model = %s ORDER BY distance ASC LIMIT %d",
            self::vector_text($query_embedding),
            $model,
            $chunk_limit,
        );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above and includes a vetted distance function name.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $best_by_post = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $post_id = (int) ($row["post_id"] ?? 0);
            $distance = (float) ($row["distance"] ?? 999.0);
            if ($post_id <= 0) {
                continue;
            }
            if (
                !isset($best_by_post[$post_id]) ||
                $distance < (float) $best_by_post[$post_id]["distance"]
            ) {
                $best_by_post[$post_id] = [
                    "distance" => $distance,
                    "chunk_text" => is_scalar($row["chunk_text"] ?? "")
                        ? (string) $row["chunk_text"]
                        : "",
                ];
            }
        }

        uasort(
            $best_by_post,
            static fn(array $a, array $b): int => (float) $a["distance"] <=>
                (float) $b["distance"],
        );
        $out = [];
        foreach (
            array_slice($best_by_post, 0, $top_k, true)
            as $post_id => $hit
        ) {
            $distance = (float) $hit["distance"];
            $out[(int) $post_id] = [
                "score" => 1.0 / (1.0 + max(0.0, $distance)),
                "distance" => $distance,
                "chunk_text" => (string) $hit["chunk_text"],
            ];
        }
        return $out;
    }

    private static function is_transient_database_error(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, "deadlock") ||
            str_contains($message, "try restarting transaction") ||
            str_contains($message, "lock wait timeout");
    }

    private static function clean_text(string $value): string
    {
        $value = str_replace("\0", "", $value);
        if (function_exists("wp_check_invalid_utf8")) {
            $checked = wp_check_invalid_utf8($value, true);
            $value = is_string($checked) ? $checked : "";
        }

        $cleaned = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            "",
            $value,
        );
        return is_string($cleaned) ? $cleaned : "";
    }

    /** @param float[] $embedding */
    private static function vector_text(array $embedding): string
    {
        return "[" .
            implode(
                ",",
                array_map(
                    static fn($v): string => sprintf("%.8F", (float) $v),
                    $embedding,
                ),
            ) .
            "]";
    }
}
