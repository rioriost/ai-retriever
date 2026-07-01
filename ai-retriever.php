<?php
/**
 * Plugin Name:       AI Retriever
 * Plugin URI:        https://github.com/rioriost/ai-retriever
 * Description:       Native-vector RAG search for WordPress using MariaDB 11.7+ or compatible MySQL 9.x vector indexes. Embeds posts on publish/update and blends vector retrieval with standard WordPress search.
 * Version:           0.2.0
 * Requires at least: 6.6
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            Rio Fujita
 * Author URI:        https://rio.st/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       ai-retriever
 * Domain Path:       /languages
 * @package WPRetriever
 */

declare(strict_types=1);

if (!defined("ABSPATH")) {
    exit();
}

if (version_compare(PHP_VERSION, "8.1.0", "<")) {
    add_action("admin_notices", static function (): void {
        echo '<div class="notice notice-error"><p>' .
            esc_html__(
                "AI Retriever requires PHP 8.1 or higher.",
                "ai-retriever",
            ) .
            "</p></div>";
    });
    return;
}

const WP_RETRIEVER_VERSION = "0.2.0";
const WP_RETRIEVER_PLUGIN_FILE = __FILE__;
const WP_RETRIEVER_OPTION_KEY = "wp_retriever_settings";
const WP_RETRIEVER_POSTMETA_CONTENT_HASH = "_wp_retriever_content_hash";
const WP_RETRIEVER_POSTMETA_INDEXED_AT = "_wp_retriever_indexed_at";
const WP_RETRIEVER_POSTMETA_LAST_ERROR = "_wp_retriever_last_error";

define("WP_RETRIEVER_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("WP_RETRIEVER_PLUGIN_URL", plugin_dir_url(__FILE__));

if (!defined("WP_RETRIEVER_ADMIN_CAPABILITY")) {
    define("WP_RETRIEVER_ADMIN_CAPABILITY", "manage_options");
}

spl_autoload_register(static function (string $class): void {
    $prefix = "WPRetriever\\";
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path =
        WP_RETRIEVER_PLUGIN_DIR .
        "includes/" .
        str_replace("\\", "/", $relative) .
        ".php";
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(WP_RETRIEVER_PLUGIN_FILE, static function (): void {
    \WPRetriever\Settings::install_or_upgrade();
    \WPRetriever\Database\VectorSchema::install_or_upgrade();
    \WPRetriever\Database\BackfillQueueSchema::install_or_upgrade();
});

register_deactivation_hook(WP_RETRIEVER_PLUGIN_FILE, static function (): void {
    \WPRetriever\BackfillRunner::clear_queue();
});

add_action(
    "plugins_loaded",
    static function (): void {
        \WPRetriever\Plugin::instance()->boot();
    },
    5,
);
