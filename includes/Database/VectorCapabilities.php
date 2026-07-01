<?php
/**
 * Runtime detection for native vector support.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Capability probes intentionally create and query a temporary VECTOR table with internally controlled SQL fragments.

final class VectorCapabilities
{
    private function __construct() {}

    /** @return array{raw:string, family:string, version:string, native_vector:bool, vector_index:bool, reason:string} */
    public static function detect(): array
    {
        global $wpdb;
        $raw = is_object($wpdb)
            ? (string) $wpdb->get_var("SELECT VERSION()")
            : "";
        $family = stripos($raw, "mariadb") !== false ? "mariadb" : "mysql";
        $version = self::extract_version($raw);

        $native = false;
        $index = false;
        $reason = "unsupported";
        if (
            $family === "mariadb" &&
            version_compare($version, "11.7.0", ">=")
        ) {
            $native = true;
            $index = true;
            $reason = "MariaDB 11.7+ VECTOR/VECTOR INDEX";
        } elseif (
            $family === "mysql" &&
            version_compare($version, "9.0.0", ">=")
        ) {
            // MySQL 9.x vector support is intentionally opt-in until the target
            // server's type, index, distance functions, and optimizer behavior are
            // verified. This keeps MySQL installs safe while preserving an extension
            // point for dialect experiments.
            $mysql_enabled = function_exists("apply_filters")
                ? (bool) apply_filters(
                    "ritriever_mysql_vector_enabled",
                    false,
                    $raw,
                    $version,
                )
                : false;
            $native = $mysql_enabled;
            $index = $mysql_enabled;
            $reason = $mysql_enabled
                ? "MySQL 9.x vector support enabled by ritriever_mysql_vector_enabled filter; verify exact index syntax"
                : "MySQL 9.x vector capability candidate; disabled until dialect is verified";
        }

        return [
            "raw" => $raw,
            "family" => $family,
            "version" => $version,
            "native_vector" => $native,
            "vector_index" => $index,
            "reason" => $reason,
        ];
    }

    public static function supports_dimensions(int $dimensions): bool
    {
        $cap = self::detect();
        return $cap["native_vector"] &&
            $dimensions > 0 &&
            $dimensions <= self::max_supported_dimensions($cap);
    }

    /** @return array{ok:bool, message:string, version:string, family:string, index_used:bool, nearest:string, distance:float} */
    public static function run_probe(): array
    {
        global $wpdb;
        $cap = self::detect();
        $result = [
            "ok" => false,
            "message" => (string) $cap["reason"],
            "version" => (string) $cap["raw"],
            "family" => (string) $cap["family"],
            "index_used" => false,
            "nearest" => "",
            "distance" => 0.0,
        ];

        if (!$cap["native_vector"] || !$cap["vector_index"]) {
            $result["message"] = (string) $cap["reason"];
            return $result;
        }

        $table = $wpdb->prefix . "ritriever_vector_probe";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        if ($cap["family"] !== "mariadb") {
            $result["message"] =
                "DB probe is only implemented for MariaDB native vector syntax.";
            return $result;
        }

        $charset = $wpdb->get_charset_collate();
        $sql =
            "CREATE TABLE {$table} (" .
            " id INT NOT NULL AUTO_INCREMENT," .
            " label VARCHAR(32) NOT NULL," .
            " embedding VECTOR(3) NOT NULL," .
            " PRIMARY KEY (id)," .
            " VECTOR INDEX (embedding) M=3 DISTANCE=cosine" .
            ") {$charset}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Probe DDL is built from constants and charset only.
        if ($wpdb->query($sql) === false) {
            $result["message"] =
                "CREATE probe table failed: " . $wpdb->last_error;
            return $result;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->query(
            "INSERT INTO {$table} (label, embedding) VALUES " .
                "('x-axis', VEC_FromText('[1,0,0]'))," .
                "('y-axis', VEC_FromText('[0,1,0]'))," .
                "('near-x', VEC_FromText('[0.9,0.1,0]'))",
        );
        if ($inserted === false) {
            $result["message"] =
                "INSERT probe rows failed: " . $wpdb->last_error;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            return $result;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $explain = $wpdb->get_row(
            "EXPLAIN SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance FROM {$table} ORDER BY distance ASC LIMIT 1",
            ARRAY_A,
        );
        $index_used =
            is_array($explain) &&
            (string) ($explain["key"] ?? "") === "embedding";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $nearest = $wpdb->get_row(
            "SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance FROM {$table} ORDER BY distance ASC LIMIT 1",
            ARRAY_A,
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        if (!is_array($nearest) || (string) ($nearest["label"] ?? "") === "") {
            $result["message"] = "Vector query returned no nearest row.";
            return $result;
        }

        $result["ok"] = true;
        $result["message"] = "Native vector probe passed.";
        $result["index_used"] = $index_used;
        $result["nearest"] = (string) $nearest["label"];
        $result["distance"] = (float) $nearest["distance"];
        return $result;
    }

    /** @param array{family:string, native_vector:bool} $cap */
    private static function max_supported_dimensions(array $cap): int
    {
        if (!$cap["native_vector"]) {
            return 0;
        }
        // RiTriever currently caps vectors at 4096 dimensions. That covers
        // OpenAI text-embedding-3-large (3072) while keeping schema changes
        // conservative for native database vector indexes.
        return $cap["family"] === "mariadb" ? 4096 : 0;
    }

    private static function extract_version(string $raw): string
    {
        if (preg_match("/(\d+\.\d+\.\d+)/", $raw, $m)) {
            return $m[1];
        }
        return "0.0.0";
    }
}
