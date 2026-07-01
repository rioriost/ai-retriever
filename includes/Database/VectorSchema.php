<?php
/**
 * Native vector table schema.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Native VECTOR DDL and index maintenance require explicit database statements with internally controlled identifiers/settings.

use WPRetriever\Logger;
use WPRetriever\Settings;

final class VectorSchema
{
    private function __construct() {}

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . "retriever_chunks";
    }

    public static function recreate(): void
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        self::install_or_upgrade();
    }

    public static function drop_vector_index(): void
    {
        global $wpdb;
        $table = self::table_name();
        if (!self::has_vector_index()) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed index name for controlled bulk loading.
        $wpdb->query("ALTER TABLE {$table} DROP INDEX embedding");
    }

    public static function create_vector_index(): void
    {
        global $wpdb;
        $cap = VectorCapabilities::detect();
        if (
            $cap["family"] !== "mariadb" ||
            !$cap["native_vector"] ||
            !$cap["vector_index"] ||
            self::has_vector_index()
        ) {
            return;
        }

        $table = self::table_name();
        $distance = (string) Settings::get("vector_distance");
        $m = (int) Settings::get("vector_index_m");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed index DDL with sanitized settings.
        $ok = $wpdb->query(
            "CREATE VECTOR INDEX embedding ON {$table} (embedding) M={$m} DISTANCE={$distance}",
        );
        if ($ok === false) {
            Logger::error("schema", "failed to create vector index", [
                "error" => $wpdb->last_error,
            ]);
        }
    }

    public static function has_vector_index(): bool
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table/index names.
        $rows = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'embedding'",
            ARRAY_A,
        );
        return is_array($rows) && $rows !== [];
    }

    public static function install_or_upgrade(): void
    {
        global $wpdb;
        $cap = VectorCapabilities::detect();
        if (!$cap["native_vector"] || !$cap["vector_index"]) {
            Logger::warn("schema", "native vector support not available", $cap);
            return;
        }

        $table = self::table_name();
        $dim = (int) Settings::get("embedding_dimensions");
        $distance = (string) Settings::get("vector_distance");
        $m = (int) Settings::get("vector_index_m");
        $charset = $wpdb->get_charset_collate();

        if ($cap["family"] === "mariadb") {
            $sql =
                "CREATE TABLE IF NOT EXISTS {$table} (\n" .
                "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
                "  post_id BIGINT UNSIGNED NOT NULL,\n" .
                "  chunk_index INT UNSIGNED NOT NULL,\n" .
                "  chunk_text LONGTEXT NOT NULL,\n" .
                "  content_hash CHAR(64) NOT NULL,\n" .
                "  embedding_model VARCHAR(191) NOT NULL,\n" .
                "  embedding VECTOR({$dim}) NOT NULL,\n" .
                "  updated_at DATETIME NOT NULL,\n" .
                "  PRIMARY KEY (id),\n" .
                "  UNIQUE KEY post_chunk_model (post_id, chunk_index, embedding_model),\n" .
                "  KEY post_lookup (post_id),\n" .
                "  KEY model_lookup (embedding_model),\n" .
                "  VECTOR INDEX (embedding) M={$m} DISTANCE={$distance}\n" .
                ") {$charset}";
        } else {
            // MySQL 9.x vector DDL is intentionally filterable until the exact target
            // server/index syntax is validated. The default mirrors the logical schema.
            $sql = apply_filters(
                "wp_retriever_mysql_vector_create_table_sql",
                "",
                $table,
                $dim,
                $distance,
                $m,
                $charset,
            );
            if (!is_string($sql) || trim($sql) === "") {
                Logger::warn(
                    "schema",
                    "MySQL vector DDL filter not provided; table not created",
                    $cap,
                );
                return;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Native VECTOR DDL is assembled from sanitized settings and fixed schema fragments.
        $ok = $wpdb->query($sql);
        if ($ok === false) {
            Logger::error("schema", "failed to create vector table", [
                "error" => $wpdb->last_error,
                "sql" => $sql,
            ]);
        }
    }
}
