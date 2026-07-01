<?php
/**
 * Shared backfill queue runner for admin and WP-CLI initialization flows.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,Squiz.PHP.DiscouragedFunctions.Discouraged -- Backfill jobs are stored in custom queue tables and require atomic SQL updates that are not covered by WordPress cache APIs.

use WPRetriever\Database\BackfillQueueSchema;
use WPRetriever\Database\VectorSchema;

final class BackfillRunner
{
    public const OPTION_KEY = "wp_retriever_backfill_queue";
    public const CRON_HOOK = "wp_retriever_process_backfill_queue";
    public const DEFAULT_BATCH_SIZE = 20;
    private const STALE_LOCK_MINUTES = 15;
    private const PROCESS_LOCK_OPTION = "wp_retriever_backfill_process_lock";
    private const PROCESS_LOCK_TTL_SECONDS = 120;

    private function __construct() {}

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, "process_scheduled"]);
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        BackfillQueueSchema::install_or_upgrade();
        $job = self::latest_job(false);
        if (!is_array($job)) {
            return self::idle_state();
        }
        return self::job_state($job);
    }

    /** @return array<string,mixed> */
    public static function create_queue(): array
    {
        BackfillQueueSchema::install_or_upgrade();
        self::clear_queue_rows();

        $ids = self::eligible_post_ids();
        $now = gmdate("Y-m-d H:i:s");
        $status = $ids === [] ? "complete" : "queued";
        $phase = $ids === [] ? "complete" : "queued";

        VectorSchema::recreate();
        if ($ids !== []) {
            VectorSchema::drop_vector_index();
        }

        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        if ($ids === []) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name from schema helper.
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$jobs} (status, phase, total_posts, created_at, updated_at, completed_at, last_error) VALUES (%s, %s, %d, %s, %s, %s, '')",
                    $status,
                    $phase,
                    0,
                    $now,
                    $now,
                    $now,
                ),
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name from schema helper.
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$jobs} (status, phase, total_posts, created_at, updated_at, completed_at, last_error) VALUES (%s, %s, %d, %s, %s, NULL, '')",
                    $status,
                    $phase,
                    count($ids),
                    $now,
                    $now,
                ),
            );
        }
        if ($inserted === false) {
            throw new \RuntimeException(
                "Failed to create backfill job: " . esc_html($wpdb->last_error),
            );
        }

        $job_id = (int) $wpdb->insert_id;
        self::insert_queue_items($job_id, $ids, $now);

        if ($ids === []) {
            Settings::mark_initial_backfill_complete([
                "processed" => 0,
                "errors" => 0,
                "completed_at" => time(),
            ]);
            VectorSchema::create_vector_index();
        } else {
            self::schedule_next_batch();
        }

        return self::status();
    }

    /** @return array<string,mixed> */
    public static function process_scheduled(): array
    {
        return self::process_batch(self::DEFAULT_BATCH_SIZE);
    }

    /** @return array<string,mixed> */
    public static function process_batch(
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }

        $process_lock_token = self::worker_token();
        if (!self::acquire_process_lock($process_lock_token)) {
            return self::status();
        }

        try {
            BackfillQueueSchema::install_or_upgrade();
            $limit = max(1, min(200, $limit));
            $job = self::latest_job(true);
            if (!is_array($job)) {
                return self::status();
            }

            $job_id = (int) $job["id"];
            $status = (string) $job["status"];
            if (!in_array($status, ["queued", "running"], true)) {
                return self::job_state($job);
            }

            self::reset_stale_processing_items($job_id);
            self::set_job_status($job_id, "running", "embedding", "");

            $token = self::worker_token();
            $claimed = self::claim_items($job_id, $token, $limit);
            if ($claimed === 0) {
                return self::finish_or_continue($job_id);
            }

            $post_ids = self::claimed_post_ids($job_id, $token);
            if ($post_ids === []) {
                return self::finish_or_continue($job_id);
            }

            try {
                $batch_result = BulkBackfillIndexer::process_posts($post_ids);
            } catch (\Throwable $e) {
                self::release_claimed_items($job_id, $token, $e->getMessage());
                self::set_job_error($job_id, $e->getMessage());
                throw $e;
            }

            $failed_ids = is_array($batch_result["failed_ids"] ?? null)
                ? array_values(array_map("intval", $batch_result["failed_ids"]))
                : [];
            $failed_ids = array_values(array_intersect($post_ids, $failed_ids));
            $done_ids = array_values(array_diff($post_ids, $failed_ids));

            self::mark_claimed_items($job_id, $token, $done_ids, "done");
            self::mark_claimed_items($job_id, $token, $failed_ids, "failed");

            return self::finish_or_continue($job_id);
        } finally {
            self::release_process_lock($process_lock_token);
        }
    }

    /** @return array{processed:int, errors:int, completed_at:int} */
    public static function run(?callable $progress = null): array
    {
        $state = self::create_queue();
        while (in_array($state["status"], ["queued", "running"], true)) {
            $before = (int) $state["processed"];
            $state = self::process_batch(self::DEFAULT_BATCH_SIZE);
            $after = (int) $state["processed"];
            if ($progress !== null) {
                for ($i = $before + 1; $i <= $after; $i++) {
                    $progress(0, $i, (int) $state["errors"]);
                }
            }
        }

        return [
            "processed" => (int) $state["processed"],
            "errors" => (int) $state["errors"],
            "completed_at" => (int) $state["completed_at"],
        ];
    }

    /** @return array<string,mixed> */
    public static function pause(): array
    {
        $job = self::latest_job(true);
        if (
            is_array($job) &&
            in_array((string) $job["status"], ["queued", "running"], true)
        ) {
            self::set_job_status((int) $job["id"], "paused", "paused", "");
        }
        return self::status();
    }

    /** @return array<string,mixed> */
    public static function resume(): array
    {
        $job = self::latest_job(false);
        if (is_array($job) && (string) $job["status"] === "paused") {
            self::reset_stale_processing_items((int) $job["id"]);
            self::set_job_status((int) $job["id"], "queued", "queued", "");
            self::schedule_next_batch();
        }
        return self::status();
    }

    /** @return array<string,mixed> */
    public static function cancel(): array
    {
        $job = self::latest_job(false);
        if (
            is_array($job) &&
            in_array(
                (string) $job["status"],
                ["queued", "running", "paused"],
                true,
            )
        ) {
            $job_id = (int) $job["id"];
            self::cancel_items($job_id);
            self::set_job_status($job_id, "cancelled", "cancelled", "");
            self::set_job_completed_at($job_id);
            Settings::clear_initial_backfill_state(
                "Initialization was cancelled.",
            );
            VectorSchema::create_vector_index();
        }
        return self::status();
    }

    public static function schedule_next_batch(): void
    {
        if (
            function_exists("wp_next_scheduled") &&
            !wp_next_scheduled(self::CRON_HOOK)
        ) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
    }

    public static function clear_queue(): void
    {
        self::unschedule();
        delete_option(self::OPTION_KEY);
        self::clear_queue_rows();
        VectorSchema::create_vector_index();
    }

    public static function unschedule(): void
    {
        if (!function_exists("wp_next_scheduled")) {
            return;
        }
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
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

    /** @param int[] $ids */
    private static function insert_queue_items(
        int $job_id,
        array $ids,
        string $now,
    ): void {
        if ($ids === []) {
            return;
        }

        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        foreach (array_chunk($ids, 500) as $batch) {
            $values = [];
            $args = [];
            foreach ($batch as $post_id) {
                $values[] = "(%d, %d, 'pending', 0, '', %s, %s)";
                $args[] = $job_id;
                $args[] = (int) $post_id;
                $args[] = $now;
                $args[] = $now;
            }
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholder fragments are generated internally and values are passed separately.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $ok = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$items} (job_id, post_id, status, attempts, locked_by, created_at, updated_at) VALUES " .
                        implode(",", $values),
                    ...$args,
                ),
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            if ($ok === false) {
                throw new \RuntimeException(
                    "Failed to create backfill queue items: " .
                        esc_html($wpdb->last_error),
                );
            }
        }
    }

    /** @return array<string,mixed>|null */
    private static function latest_job(bool $active_only): ?array
    {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        $where = $active_only
            ? "WHERE status IN ('queued', 'running', 'paused')"
            : "";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table and status list are fixed.
        $job = $wpdb->get_row(
            "SELECT * FROM {$jobs} {$where} ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        return is_array($job) ? $job : null;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private static function job_state(array $job): array
    {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        $job_id = (int) $job["id"];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name from schema helper.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS count FROM {$items} WHERE job_id = %d GROUP BY status",
                $job_id,
            ),
            ARRAY_A,
        );
        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row["status"]] = (int) $row["count"];
        }

        $total = (int) ($job["total_posts"] ?? 0);
        $processed =
            ($counts["done"] ?? 0) +
            ($counts["failed"] ?? 0) +
            ($counts["skipped"] ?? 0) +
            ($counts["cancelled"] ?? 0);
        $errors = $counts["failed"] ?? 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table name from schema helper.
        $failed_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$items} WHERE job_id = %d AND status = 'failed' ORDER BY id ASC LIMIT 200",
                $job_id,
            ),
        );

        return [
            "job_id" => $job_id,
            "status" => (string) ($job["status"] ?? "idle"),
            "phase" => (string) ($job["phase"] ?? ""),
            "ids" => [],
            "total" => $total,
            "processed" => min($total, max(0, $processed)),
            "errors" => max(0, $errors),
            "failed_ids" => array_values(
                array_map("intval", is_array($failed_ids) ? $failed_ids : []),
            ),
            "created_at" => self::db_datetime_to_timestamp(
                (string) ($job["created_at"] ?? ""),
            ),
            "updated_at" => self::db_datetime_to_timestamp(
                (string) ($job["updated_at"] ?? ""),
            ),
            "completed_at" => self::db_datetime_to_timestamp(
                (string) ($job["completed_at"] ?? ""),
            ),
            "last_error" => (string) ($job["last_error"] ?? ""),
            "counts" => $counts,
        ];
    }

    /** @return array<string,mixed> */
    private static function idle_state(): array
    {
        return [
            "job_id" => 0,
            "status" => "idle",
            "phase" => "idle",
            "ids" => [],
            "total" => 0,
            "processed" => 0,
            "errors" => 0,
            "failed_ids" => [],
            "created_at" => 0,
            "updated_at" => 0,
            "completed_at" => 0,
            "last_error" => "",
            "counts" => [],
        ];
    }

    private static function claim_items(
        int $job_id,
        string $token,
        int $limit,
    ): int {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = 'processing', locked_by = %s, locked_at = UTC_TIMESTAMP(), attempts = attempts + 1, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
                $token,
                $job_id,
                $limit,
            ),
        );
        return max(0, (int) $claimed);
    }

    /** @return int[] */
    private static function claimed_post_ids(int $job_id, string $token): array
    {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$items} WHERE job_id = %d AND locked_by = %s AND status = 'processing' ORDER BY id ASC",
                $job_id,
                $token,
            ),
        );
        return array_values(array_map("intval", is_array($ids) ? $ids : []));
    }

    /** @param int[] $post_ids */
    private static function mark_claimed_items(
        int $job_id,
        string $token,
        array $post_ids,
        string $status,
    ): void {
        if ($post_ids === []) {
            return;
        }

        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        $post_ids = array_values(array_map("intval", $post_ids));
        $placeholders = implode(",", array_fill(0, count($post_ids), "%d"));
        $args = array_merge([$status, $job_id, $token], $post_ids);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list is generated from integer IDs.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = %s, locked_by = '', locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND locked_by = %s AND post_id IN ({$placeholders})",
                ...$args,
            ),
        );
    }

    private static function release_claimed_items(
        int $job_id,
        string $token,
        string $error,
    ): void {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = 'pending', locked_by = '', locked_at = NULL, last_error = %s, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND locked_by = %s",
                $error,
                $job_id,
                $token,
            ),
        );
    }

    private static function reset_stale_processing_items(int $job_id): void
    {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = 'pending', locked_by = '', locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
                $job_id,
                self::STALE_LOCK_MINUTES,
            ),
        );
    }

    private static function reset_processing_items(int $job_id): void
    {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = 'pending', locked_by = '', locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status = 'processing'",
                $job_id,
            ),
        );
    }

    private static function cancel_items(int $job_id): void
    {
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$items} SET status = 'cancelled', locked_by = '', locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status IN ('pending', 'processing')",
                $job_id,
            ),
        );
    }

    /** @return array<string,mixed> */
    private static function finish_or_continue(int $job_id): array
    {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        $items = BackfillQueueSchema::items_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$jobs} WHERE id = %d", $job_id),
            ARRAY_A,
        );
        if (!is_array($job)) {
            return self::idle_state();
        }
        $current_status = (string) $job["status"];
        if ($current_status === "paused" || $current_status === "cancelled") {
            return self::job_state($job);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $pending = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items} WHERE job_id = %d AND status = 'pending'",
                $job_id,
            ),
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $processing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items} WHERE job_id = %d AND status = 'processing'",
                $job_id,
            ),
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $failed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items} WHERE job_id = %d AND status = 'failed'",
                $job_id,
            ),
        );
        $processed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$items} WHERE job_id = %d AND status IN ('done', 'failed', 'skipped', 'cancelled')",
                $job_id,
            ),
        );

        if ($pending === 0 && $processing === 0) {
            self::set_job_status($job_id, "running", "building_index", "");
            VectorSchema::create_vector_index();
            $final_status = $failed === 0 ? "complete" : "failed";
            self::set_job_status($job_id, $final_status, $final_status, "");
            self::set_job_completed_at($job_id);
            if ($failed === 0) {
                Settings::mark_initial_backfill_complete([
                    "processed" => $processed,
                    "errors" => 0,
                    "completed_at" => time(),
                ]);
            } else {
                Settings::clear_initial_backfill_state(
                    "Initialization completed with indexing errors.",
                );
            }
        } elseif ($processing > 0) {
            self::set_job_status($job_id, "running", "embedding", "");
        } else {
            self::set_job_status($job_id, "queued", "queued", "");
            self::schedule_next_batch();
        }

        $updated_job = self::job_by_id($job_id);
        return is_array($updated_job)
            ? self::job_state($updated_job)
            : self::idle_state();
    }

    /** @return array<string,mixed>|null */
    private static function job_by_id(int $job_id): ?array
    {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$jobs} WHERE id = %d", $job_id),
            ARRAY_A,
        );
        return is_array($job) ? $job : null;
    }

    private static function set_job_status(
        int $job_id,
        string $status,
        string $phase,
        string $error,
    ): void {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$jobs} SET status = %s, phase = %s, last_error = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d",
                $status,
                $phase,
                $error,
                $job_id,
            ),
        );
    }

    private static function set_job_error(int $job_id, string $error): void
    {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$jobs} SET last_error = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d",
                $error,
                $job_id,
            ),
        );
    }

    private static function set_job_completed_at(int $job_id): void
    {
        global $wpdb;
        $jobs = BackfillQueueSchema::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared below.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$jobs} SET completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = %d",
                $job_id,
            ),
        );
    }

    private static function clear_queue_rows(): void
    {
        BackfillQueueSchema::install_or_upgrade();
        global $wpdb;
        $items = BackfillQueueSchema::items_table();
        $jobs = BackfillQueueSchema::jobs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Queue reset.
        $wpdb->query("DELETE FROM {$items}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Queue reset.
        $wpdb->query("DELETE FROM {$jobs}");
        delete_option(self::OPTION_KEY);
    }

    private static function acquire_process_lock(string $token): bool
    {
        $payload = [
            "token" => $token,
            "expires" => time() + self::PROCESS_LOCK_TTL_SECONDS,
        ];
        if (add_option(self::PROCESS_LOCK_OPTION, $payload, "", "no")) {
            return true;
        }

        $lock = get_option(self::PROCESS_LOCK_OPTION, []);
        $expires = is_array($lock) ? (int) ($lock["expires"] ?? 0) : 0;
        if ($expires > 0 && $expires < time()) {
            delete_option(self::PROCESS_LOCK_OPTION);
            return add_option(self::PROCESS_LOCK_OPTION, $payload, "", "no");
        }

        return false;
    }

    private static function release_process_lock(string $token): void
    {
        $lock = get_option(self::PROCESS_LOCK_OPTION, []);
        if (is_array($lock) && (string) ($lock["token"] ?? "") === $token) {
            delete_option(self::PROCESS_LOCK_OPTION);
        }
    }

    private static function worker_token(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid("wp-retriever-", true);
        }
    }

    private static function db_datetime_to_timestamp(string $datetime): int
    {
        if ($datetime === "" || $datetime === "0000-00-00 00:00:00") {
            return 0;
        }
        $timestamp = strtotime($datetime . " UTC");
        return $timestamp === false ? 0 : (int) $timestamp;
    }
}
