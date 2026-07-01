<?php
/**
 * Uninstall cleanup for AI Retriever.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall cleanup must remove plugin-owned options, metadata, transients, and custom tables across sites without caching.

if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}

/**
 * Remove all AI Retriever data for the current site.
 */
function wp_retriever_uninstall_site(): void
{
    global $wpdb;

    wp_retriever_unschedule_site_cron();

    delete_option("wp_retriever_settings");
    delete_option("wp_retriever_backfill_queue");
    delete_option("wp_retriever_log");

    wp_retriever_delete_transients("wp_retriever_q_");
    wp_retriever_delete_transients("wp_retriever_live_query_");

    $postmeta_keys = [
        "_wp_retriever_content_hash",
        "_wp_retriever_indexed_at",
        "_wp_retriever_last_error",
    ];
    $placeholders = implode(",", array_fill(0, count($postmeta_keys), "%s"));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
            ...$postmeta_keys,
        ),
    );

    $table = $wpdb->prefix . "retriever_chunks";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$table}");

    $queue_items_table = $wpdb->prefix . "retriever_backfill_items";
    $queue_jobs_table = $wpdb->prefix . "retriever_backfill_jobs";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$queue_items_table}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$queue_jobs_table}");
}

/**
 * Remove AI Retriever transients for the current site.
 */
function wp_retriever_delete_transients(string $prefix): void
{
    global $wpdb;

    $transient_like = $wpdb->esc_like("_transient_" . $prefix) . "%";
    $timeout_like = $wpdb->esc_like("_transient_timeout_" . $prefix) . "%";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $transient_like,
            $timeout_like,
        ),
    );

    $keys = [];
    foreach (is_array($option_names) ? $option_names : [] as $option_name) {
        $option_name = (string) $option_name;
        if (str_starts_with($option_name, "_transient_timeout_")) {
            $keys[] = substr($option_name, strlen("_transient_timeout_"));
        } elseif (str_starts_with($option_name, "_transient_")) {
            $keys[] = substr($option_name, strlen("_transient_"));
        }
    }

    foreach (array_unique($keys) as $key) {
        if (str_starts_with((string) $key, $prefix)) {
            delete_transient((string) $key);
        }
    }
}

/**
 * Remove pending AI Retriever cron events for the current site.
 */
function wp_retriever_unschedule_site_cron(): void
{
    $hook = "wp_retriever_process_backfill_queue";
    $timestamp = wp_next_scheduled($hook);
    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }
}

if (is_multisite()) {
    $site_ids = get_sites([
        "fields" => "ids",
        "number" => 0,
    ]);
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        wp_retriever_uninstall_site();
        restore_current_blog();
    }
} else {
    wp_retriever_uninstall_site();
}
