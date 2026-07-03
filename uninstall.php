<?php
/**
 * Uninstall cleanup for RiTriever.
 *
 * @package RiTriever
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall cleanup must remove plugin-owned options, metadata, transients, and custom tables across sites without caching.

if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}

/**
 * Remove all RiTriever data for the current site.
 */
function ritriever_uninstall_site(): void
{
    global $wpdb;

    ritriever_unschedule_site_cron();

    delete_option("ritriever_settings");
    delete_option("ritriever_backfill_queue");
    delete_option("ritriever_query_cache_keys");
    delete_option("ritriever_log");

    ritriever_delete_transients("ritriever_q_");
    ritriever_delete_transients("ritriever_live_query_");

    $postmeta_keys = [
        "_ritriever_content_hash",
        "_ritriever_indexed_at",
        "_ritriever_last_error",
    ];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM %i WHERE meta_key IN (%s, %s, %s)",
            $wpdb->postmeta,
            $postmeta_keys[0],
            $postmeta_keys[1],
            $postmeta_keys[2],
        ),
    );

    $table = $wpdb->prefix . "ritriever_chunks";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));

    $queue_items_table = $wpdb->prefix . "ritriever_backfill_items";
    $queue_jobs_table = $wpdb->prefix . "ritriever_backfill_jobs";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $queue_items_table));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $queue_jobs_table));
}

/**
 * Remove RiTriever transients for the current site.
 */
function ritriever_delete_transients(string $prefix): void
{
    global $wpdb;

    $transient_like = $wpdb->esc_like("_transient_" . $prefix) . "%";
    $timeout_like = $wpdb->esc_like("_transient_timeout_" . $prefix) . "%";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->options,
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
 * Remove pending RiTriever cron events for the current site.
 */
function ritriever_unschedule_site_cron(): void
{
    $hook = "ritriever_process_backfill_queue";
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
        ritriever_uninstall_site();
        restore_current_blog();
    }
} else {
    ritriever_uninstall_site();
}
