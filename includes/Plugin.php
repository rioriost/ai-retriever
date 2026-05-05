<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

use WPRetriever\Admin\SettingsPage;
use WPRetriever\CLI\BackfillCommand;
use WPRetriever\Database\BackfillQueueSchema;
use WPRetriever\Database\VectorSchema;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        Settings::install_or_upgrade();
        VectorSchema::install_or_upgrade();
        BackfillQueueSchema::install_or_upgrade();
        $this->load_textdomain();

        PostSync::register();
        SearchInterceptor::register();
        BackfillRunner::register();

        if (is_admin()) {
            SettingsPage::register();
        }

        if (defined("WP_CLI") && WP_CLI) {
            BackfillCommand::register();
        }

        add_action(
            "update_option_" . WP_RETRIEVER_OPTION_KEY,
            [self::class, "on_settings_updated"],
            10,
            2,
        );

        $this->booted = true;
        Logger::debug("plugin", "boot complete", [
            "version" => WP_RETRIEVER_VERSION,
        ]);
    }

    public static function on_settings_updated($old_value, $value): void
    {
        Settings::_flush_cache_for_tests();
        if (!is_array($old_value) || !is_array($value)) {
            return;
        }

        $schema_keys = [
            "embedding_provider",
            "openai_embedding_model",
            "custom_embedding_model",
            "custom_embedding_preset",
            "embedding_dimensions",
            "vector_distance",
        ];
        foreach ($schema_keys as $key) {
            if (($old_value[$key] ?? null) !== ($value[$key] ?? null)) {
                BackfillRunner::clear_queue();
                VectorSchema::recreate();
                Settings::clear_initial_backfill_state(
                    "Embedding settings changed. Run initialization again.",
                );
                return;
            }
        }

        foreach (["indexed_custom_fields", "indexed_taxonomies"] as $key) {
            if (($old_value[$key] ?? null) !== ($value[$key] ?? null)) {
                BackfillRunner::clear_queue();
                SearchInterceptor::purge_query_cache();
                Settings::clear_initial_backfill_state(
                    "Content extraction settings changed. Run initialization again.",
                );
                return;
            }
        }

        foreach (["top_k", "min_score", "cache_ttl_seconds"] as $key) {
            if (($old_value[$key] ?? null) !== ($value[$key] ?? null)) {
                SearchInterceptor::purge_query_cache();
                break;
            }
        }

        if (
            ($old_value["japanese_normalization_enabled"] ?? null) !==
            ($value["japanese_normalization_enabled"] ?? null)
        ) {
            SearchInterceptor::purge_query_cache();
        }
    }

    private function load_textdomain(): void
    {
        if (function_exists("load_plugin_textdomain")) {
            load_plugin_textdomain(
                "wp-retriever",
                false,
                dirname(plugin_basename(WP_RETRIEVER_PLUGIN_FILE)) .
                    "/languages",
            );
        }
    }
}
