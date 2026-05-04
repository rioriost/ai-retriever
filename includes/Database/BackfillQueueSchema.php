<?php
/**
 * Dedicated tables for initial backfill jobs and queue items.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever\Database;

use WPRetriever\Logger;

final class BackfillQueueSchema
{
    private function __construct() {}

    public static function jobs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "retriever_backfill_jobs";
    }

    public static function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "retriever_backfill_items";
    }

    public static function install_or_upgrade(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $jobs = self::jobs_table();
        $items = self::items_table();

        $jobs_sql =
            "CREATE TABLE IF NOT EXISTS {$jobs} (" .
            " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT," .
            " status VARCHAR(20) NOT NULL DEFAULT 'queued'," .
            " phase VARCHAR(40) NOT NULL DEFAULT 'queued'," .
            " total_posts BIGINT UNSIGNED NOT NULL DEFAULT 0," .
            " created_at DATETIME NOT NULL," .
            " updated_at DATETIME NOT NULL," .
            " completed_at DATETIME NULL DEFAULT NULL," .
            " last_error TEXT NULL," .
            " PRIMARY KEY (id)," .
            " KEY status_updated (status, updated_at)" .
            ") {$charset}";

        $items_sql =
            "CREATE TABLE IF NOT EXISTS {$items} (" .
            " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT," .
            " job_id BIGINT UNSIGNED NOT NULL," .
            " post_id BIGINT UNSIGNED NOT NULL," .
            " status VARCHAR(20) NOT NULL DEFAULT 'pending'," .
            " attempts INT UNSIGNED NOT NULL DEFAULT 0," .
            " locked_at DATETIME NULL DEFAULT NULL," .
            " locked_by VARCHAR(64) NOT NULL DEFAULT ''," .
            " last_error TEXT NULL," .
            " created_at DATETIME NOT NULL," .
            " updated_at DATETIME NOT NULL," .
            " PRIMARY KEY (id)," .
            " UNIQUE KEY job_post (job_id, post_id)," .
            " KEY job_status_id (job_id, status, id)," .
            " KEY job_lock (job_id, locked_by)," .
            " KEY stale_processing (status, locked_at)" .
            ") {$charset}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed schema DDL.
        if ($wpdb->query($jobs_sql) === false) {
            Logger::error("queue_schema", "failed to create jobs table", [
                "error" => $wpdb->last_error,
            ]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed schema DDL.
        if ($wpdb->query($items_sql) === false) {
            Logger::error("queue_schema", "failed to create items table", [
                "error" => $wpdb->last_error,
            ]);
        }
    }

    public static function drop(): void
    {
        global $wpdb;
        $items = self::items_table();
        $jobs = self::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup.
        $wpdb->query("DROP TABLE IF EXISTS {$items}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup.
        $wpdb->query("DROP TABLE IF EXISTS {$jobs}");
    }
}
