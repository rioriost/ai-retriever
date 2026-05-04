<?php
/**
 * Index coverage and failure diagnostics.
 *
 * @package WPRetliever
 */

declare(strict_types=1);

namespace WPRetliever;

use WPRetliever\Database\VectorSchema;

final class IndexDiagnostics
{
    private function __construct() {}

    /** @return array{eligible_posts:int,indexed_posts:int,chunk_count:int,coverage_percent:float,failed_count:int,queue_status:string,queue_processed:int,queue_total:int,queue_errors:int,failed_posts:array<int,array{post_id:int,title:string,status:string,error:string,edit_url:string}>} */
    public static function summary(int $failed_limit = 20): array
    {
        $eligible_ids = self::eligible_post_ids();
        $indexed_ids = self::indexed_post_ids();
        $eligible_lookup = array_fill_keys($eligible_ids, true);
        $indexed_eligible = 0;
        foreach ($indexed_ids as $post_id) {
            if (isset($eligible_lookup[$post_id])) {
                $indexed_eligible++;
            }
        }

        $eligible_count = count($eligible_ids);
        $queue = BackfillRunner::status();

        return [
            "eligible_posts" => $eligible_count,
            "indexed_posts" => $indexed_eligible,
            "chunk_count" => self::chunk_count(),
            "coverage_percent" =>
                $eligible_count > 0
                    ? round(($indexed_eligible / $eligible_count) * 100, 1)
                    : 0.0,
            "failed_count" => self::failed_count(),
            "queue_status" => (string) $queue["status"],
            "queue_processed" => (int) $queue["processed"],
            "queue_total" => (int) $queue["total"],
            "queue_errors" => (int) $queue["errors"],
            "failed_posts" => self::failed_posts($failed_limit),
        ];
    }

    /** @return int[] */
    private static function eligible_post_ids(): array
    {
        $post_types = Settings::get("post_types");
        $post_statuses = Settings::get("post_statuses");
        $query = new \WP_Query([
            "post_type" =>
                is_array($post_types) && $post_types !== []
                    ? $post_types
                    : "any",
            "post_status" =>
                is_array($post_statuses) && $post_statuses !== []
                    ? $post_statuses
                    : ["publish"],
            "posts_per_page" => -1,
            "fields" => "ids",
            "orderby" => "ID",
            "order" => "ASC",
            "ignore_sticky_posts" => true,
            "no_found_rows" => true,
            "update_post_meta_cache" => false,
            "update_post_term_cache" => false,
        ]);
        $ids = array_map(
            "intval",
            is_array($query->posts) ? $query->posts : [],
        );
        wp_reset_postdata();
        return array_values(
            array_filter(
                $ids,
                static fn(int $post_id): bool => PostFilter::is_eligible(
                    $post_id,
                ),
            ),
        );
    }

    /** @return int[] */
    private static function indexed_post_ids(): array
    {
        global $wpdb;
        if (!self::vector_table_exists()) {
            return [];
        }
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_col("SELECT DISTINCT post_id FROM {$table}");
        return array_values(array_map("intval", is_array($rows) ? $rows : []));
    }

    private static function chunk_count(): int
    {
        global $wpdb;
        if (!self::vector_table_exists()) {
            return 0;
        }
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private static function failed_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
                WP_RETLIEVER_POSTMETA_LAST_ERROR,
            ),
        );
    }

    /** @return int[] */
    public static function failed_post_ids(int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id DESC LIMIT %d",
                WP_RETLIEVER_POSTMETA_LAST_ERROR,
                $limit,
            ),
        );
        return array_values(array_map("intval", is_array($rows) ? $rows : []));
    }

    /** @return array<int,array{post_id:int,title:string,status:string,error:string,edit_url:string}> */
    private static function failed_posts(int $limit): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id DESC LIMIT %d",
                WP_RETLIEVER_POSTMETA_LAST_ERROR,
                $limit,
            ),
            ARRAY_A,
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $post_id = (int) ($row["post_id"] ?? 0);
            $post = get_post($post_id);
            $out[] = [
                "post_id" => $post_id,
                "title" =>
                    $post instanceof \WP_Post ? get_the_title($post) : "",
                "status" =>
                    $post instanceof \WP_Post
                        ? (string) $post->post_status
                        : "",
                "error" => is_scalar($row["meta_value"] ?? "")
                    ? (string) $row["meta_value"]
                    : "",
                "edit_url" => get_edit_post_link($post_id, "raw") ?: "",
            ];
        }
        return $out;
    }

    private static function vector_table_exists(): bool
    {
        global $wpdb;
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (string) $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table),
        ) === $table;
    }
}
