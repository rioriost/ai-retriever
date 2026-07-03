<?php
/**
 * Native vector table schema.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Native VECTOR DDL and index maintenance require explicit database statements with internally controlled identifiers/settings.

use RiTriever\Logger;
use RiTriever\Settings;

final class VectorSchema
{
    private function __construct() {}

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_chunks";
    }

    public static function recreate(): void
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
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
        $wpdb->query($wpdb->prepare("ALTER TABLE %i DROP INDEX embedding", $table));
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $ok =
            $distance === "euclidean"
                ? $wpdb->query(
                    $wpdb->prepare(
                        "CREATE VECTOR INDEX embedding ON %i (embedding) M=%d DISTANCE=euclidean",
                        $table,
                        $m,
                    ),
                )
                : $wpdb->query(
                    $wpdb->prepare(
                        "CREATE VECTOR INDEX embedding ON %i (embedding) M=%d DISTANCE=cosine",
                        $table,
                        $m,
                    ),
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
            $wpdb->prepare(
                "SHOW INDEX FROM %i WHERE Key_name = %s",
                $table,
                "embedding",
            ),
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
        $charset = self::charset_collate_sql();

        if ($cap["family"] === "mariadb") {
            $sql =
                $distance === "euclidean"
                    ? $wpdb->prepare(
                        "CREATE TABLE IF NOT EXISTS %i (\n" .
                        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
                        "  post_id BIGINT UNSIGNED NOT NULL,\n" .
                        "  chunk_index INT UNSIGNED NOT NULL,\n" .
                        "  chunk_text LONGTEXT NOT NULL,\n" .
                        "  content_hash CHAR(64) NOT NULL,\n" .
                        "  embedding_model VARCHAR(191) NOT NULL,\n" .
                        "  embedding VECTOR(%d) NOT NULL,\n" .
                        "  updated_at DATETIME NOT NULL,\n" .
                        "  PRIMARY KEY (id),\n" .
                        "  UNIQUE KEY post_chunk_model (post_id, chunk_index, embedding_model),\n" .
                        "  KEY post_lookup (post_id),\n" .
                        "  KEY model_lookup (embedding_model),\n" .
                        "  VECTOR INDEX (embedding) M=%d DISTANCE=euclidean\n" .
                        ")",
                        $table,
                        $dim,
                        $m,
                    )
                    : $wpdb->prepare(
                        "CREATE TABLE IF NOT EXISTS %i (\n" .
                        "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
                        "  post_id BIGINT UNSIGNED NOT NULL,\n" .
                        "  chunk_index INT UNSIGNED NOT NULL,\n" .
                        "  chunk_text LONGTEXT NOT NULL,\n" .
                        "  content_hash CHAR(64) NOT NULL,\n" .
                        "  embedding_model VARCHAR(191) NOT NULL,\n" .
                        "  embedding VECTOR(%d) NOT NULL,\n" .
                        "  updated_at DATETIME NOT NULL,\n" .
                        "  PRIMARY KEY (id),\n" .
                        "  UNIQUE KEY post_chunk_model (post_id, chunk_index, embedding_model),\n" .
                        "  KEY post_lookup (post_id),\n" .
                        "  KEY model_lookup (embedding_model),\n" .
                        "  VECTOR INDEX (embedding) M=%d DISTANCE=cosine\n" .
                        ")",
                        $table,
                        $dim,
                        $m,
                    );
            if ($charset !== "") {
                $sql .= " " . $charset;
            }
        } else {
            // MySQL 9.x vector DDL is intentionally filterable until the exact target
            // server/index syntax is validated. The default mirrors the logical schema.
            $sql = apply_filters(
                "ritriever_mysql_vector_create_table_sql",
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Native VECTOR DDL is assembled from sanitized settings and fixed schema fragments.
        $ok = $wpdb->query($sql);
        if ($ok === false) {
            Logger::error("schema", "failed to create vector table", [
                "error" => $wpdb->last_error,
                "sql" => $sql,
            ]);
        }
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
