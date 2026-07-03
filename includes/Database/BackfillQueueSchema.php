<?php
/**
 * Dedicated tables for initial backfill jobs and queue items.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom queue table schema lifecycle requires explicit DDL with internally controlled table names.

use RiTriever\Logger;

final class BackfillQueueSchema
{
    private function __construct() {}

    public static function jobs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_backfill_jobs";
    }

    public static function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_backfill_items";
    }

    public static function install_or_upgrade(): void
    {
        global $wpdb;

        $charset = self::charset_collate_sql();
        $jobs = self::jobs_table();
        $items = self::items_table();

        $jobs_sql = $wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
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
            ")",
            $jobs,
        );
        if ($charset !== "") {
            $jobs_sql .= " " . $charset;
        }

        $items_sql = $wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
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
            ")",
            $items,
        );
        if ($charset !== "") {
            $items_sql .= " " . $charset;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed schema DDL.
        if ($wpdb->query($jobs_sql) === false) {
            Logger::error("queue_schema", "failed to create jobs table", [
                "error" => $wpdb->last_error,
            ]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed schema DDL.
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
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $items));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup.
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $jobs));
    }

    private static function charset_collate_sql(): string
    {
        global $wpdb;
        $charset = preg_replace(
            "/[^a-zA-Z0-9_ =-]/",
            "",
            $wpdb->get_charset_collate(),
        );
        return is_string($charset) ? trim($charset) : "";
    }
}
