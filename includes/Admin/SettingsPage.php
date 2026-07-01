<?php
/**
 * Admin settings page.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever\Admin;

use WPRetriever\BackfillRunner;
use WPRetriever\Database\VectorCapabilities;
use WPRetriever\Embedding\EmbeddingProviderFactory;
use WPRetriever\IndexDiagnostics;
use WPRetriever\LanguageOptions;
use WPRetriever\Logger;
use WPRetriever\PostSync;
use WPRetriever\Provider\LocalVectorProvider;
use WPRetriever\Settings;

final class SettingsPage
{
    public const PAGE_SLUG = "wp-retriever-settings";

    private function __construct() {}

    public static function register(): void
    {
        add_action("admin_menu", [self::class, "menu"]);
        add_action("admin_init", [self::class, "settings"]);
        add_action("admin_enqueue_scripts", [self::class, "enqueue_assets"]);
        add_action("admin_post_wp_retriever_initialize", [
            self::class,
            "handle_initialize",
        ]);
        add_action("wp_ajax_wp_retriever_backfill_status", [
            self::class,
            "handle_ajax_backfill_status",
        ]);
        add_action("wp_ajax_wp_retriever_backfill_run", [
            self::class,
            "handle_ajax_backfill_run",
        ]);
        add_action("wp_ajax_wp_retriever_backfill_pause", [
            self::class,
            "handle_ajax_backfill_pause",
        ]);
        add_action("wp_ajax_wp_retriever_backfill_resume", [
            self::class,
            "handle_ajax_backfill_resume",
        ]);
        add_action("wp_ajax_wp_retriever_backfill_cancel", [
            self::class,
            "handle_ajax_backfill_cancel",
        ]);
        add_action("admin_post_wp_retriever_test_embedding", [
            self::class,
            "handle_test_embedding",
        ]);
        add_action("admin_post_wp_retriever_test_db", [
            self::class,
            "handle_test_db",
        ]);
        add_action("admin_post_wp_retriever_live_vector_query", [
            self::class,
            "handle_live_vector_query",
        ]);
        add_action("admin_post_wp_retriever_retry_failed", [
            self::class,
            "handle_retry_failed",
        ]);
    }

    public static function menu(): void
    {
        add_options_page(
            "AI Retriever",
            "AI Retriever",
            (string) WP_RETRIEVER_ADMIN_CAPABILITY,
            self::PAGE_SLUG,
            [self::class, "render"],
        );
    }

    public static function settings(): void
    {
        register_setting("wp_retriever", WP_RETRIEVER_OPTION_KEY, [
            "sanitize_callback" => [Settings::class, "sanitize"],
        ]);
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== "settings_page_" . self::PAGE_SLUG) {
            return;
        }

        $queue = BackfillRunner::status();
        $auto_start = in_array(
            (string) $queue["status"],
            ["queued", "running"],
            true,
        );

        wp_enqueue_script(
            "wp-retriever-admin-backfill",
            WP_RETRIEVER_PLUGIN_URL . "assets/admin-backfill.js",
            [],
            WP_RETRIEVER_VERSION,
            true,
        );
        wp_add_inline_script(
            "wp-retriever-admin-backfill",
            "window.wpRetrieverBackfill = " .
                wp_json_encode([
                    "ajaxUrl" => admin_url("admin-ajax.php"),
                    "nonce" => wp_create_nonce("wp_retriever_backfill"),
                    "autoStart" => $auto_start,
                    "delayMs" => 250,
                    "errorDelayMs" => 5000,
                    "maxConsecutiveErrors" => 5,
                    "concurrency" => 1,
                    "i18n" => [
                        "progress" => self::text("init_progress"),
                        "running" => self::text("init_auto_running"),
                        "complete" => self::text("init_auto_complete"),
                        "failed" => self::text("init_auto_failed"),
                        "idle" => self::text("init_auto_idle"),
                        "retrying" => self::text("init_auto_retrying"),
                        "confirmCancel" => self::text("init_cancel_confirm"),
                    ],
                ]) .
                ";",
            "before",
        );
    }

    public static function handle_initialize(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }
        check_admin_referer("wp_retriever_initialize");

        $cap = VectorCapabilities::detect();
        if (!$cap["native_vector"] || !$cap["vector_index"]) {
            self::redirect_with_notice("init_unsupported");
        }

        try {
            $state = BackfillRunner::create_queue();
            if ((string) $state["status"] === "complete") {
                self::redirect_with_notice("init_done", [
                    "processed" => (string) (int) $state["processed"],
                ]);
            }
            self::redirect_with_notice("init_queued", [
                "total" => (string) (int) $state["total"],
            ]);
        } catch (\Throwable $e) {
            Logger::error("admin", "initialization queue creation failed", [
                "error" => $e->getMessage(),
            ]);
            self::redirect_with_notice("init_failed");
        }
    }

    public static function handle_ajax_backfill_status(): void
    {
        self::assert_ajax_backfill_access();
        wp_send_json_success(self::queue_payload(BackfillRunner::status()));
    }

    public static function handle_ajax_backfill_run(): void
    {
        self::assert_ajax_backfill_access();

        try {
            $state = BackfillRunner::process_batch(50);
            wp_send_json_success(self::queue_payload($state));
        } catch (\Throwable $e) {
            Logger::error("admin", "AJAX initialization batch failed", [
                "error" => $e->getMessage(),
            ]);
            wp_send_json_error(["message" => self::text("init_failed")], 500);
        }
    }

    public static function handle_ajax_backfill_pause(): void
    {
        self::assert_ajax_backfill_access();
        wp_send_json_success(self::queue_payload(BackfillRunner::pause()));
    }

    public static function handle_ajax_backfill_resume(): void
    {
        self::assert_ajax_backfill_access();
        wp_send_json_success(self::queue_payload(BackfillRunner::resume()));
    }

    public static function handle_ajax_backfill_cancel(): void
    {
        self::assert_ajax_backfill_access();
        wp_send_json_success(self::queue_payload(BackfillRunner::cancel()));
    }

    private static function assert_ajax_backfill_access(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_send_json_error(["message" => self::text("forbidden")], 403);
        }
        check_ajax_referer("wp_retriever_backfill", "nonce");
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function queue_payload(array $state): array
    {
        $total = max(0, (int) ($state["total"] ?? 0));
        $processed = min($total, max(0, (int) ($state["processed"] ?? 0)));
        $errors = max(0, (int) ($state["errors"] ?? 0));
        $status = (string) ($state["status"] ?? "idle");
        return [
            "status" => $status,
            "phase" => (string) ($state["phase"] ?? ""),
            "total" => $total,
            "processed" => $processed,
            "errors" => $errors,
            "percent" =>
                $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
            "last_error" => (string) ($state["last_error"] ?? ""),
            "message" => self::queue_message($status),
        ];
    }

    private static function queue_message(string $status): string
    {
        if ($status === "complete") {
            return self::text("init_auto_complete");
        }
        if ($status === "failed") {
            return self::text("init_auto_failed");
        }
        if ($status === "paused") {
            return self::text("init_auto_paused");
        }
        if ($status === "cancelled") {
            return self::text("init_auto_cancelled");
        }
        if ($status === "queued" || $status === "running") {
            return self::text("init_auto_running");
        }
        return self::text("init_auto_idle");
    }

    public static function handle_test_embedding(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }
        check_admin_referer("wp_retriever_test_embedding");

        try {
            $started = microtime(true);
            $provider = EmbeddingProviderFactory::make();
            $embedding = $provider->embed(
                "AI Retriever embedding provider test",
            );
            $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
            if ($embedding === []) {
                throw new \RuntimeException("Embedding response was empty.");
            }
            self::redirect_with_notice("embedding_test_ok", [
                "provider" => (string) Settings::get("embedding_provider"),
                "model" => $provider->model(),
                "dimensions" => (string) count($embedding),
                "elapsed_ms" => (string) $elapsed_ms,
            ]);
        } catch (\Throwable $e) {
            Logger::error("admin", "embedding provider test failed", [
                "provider" => (string) Settings::get("embedding_provider"),
                "error" => $e->getMessage(),
            ]);
            self::redirect_with_notice("embedding_test_failed", [
                "error" => rawurlencode($e->getMessage()),
            ]);
        }
    }

    public static function handle_test_db(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }
        check_admin_referer("wp_retriever_test_db");

        try {
            $probe = VectorCapabilities::run_probe();
            self::redirect_with_notice(
                $probe["ok"] ? "db_test_ok" : "db_test_failed",
                [
                    "family" => (string) $probe["family"],
                    "index_used" => !empty($probe["index_used"]) ? "1" : "0",
                    "nearest" => (string) $probe["nearest"],
                    "distance" => (string) $probe["distance"],
                    "message" => rawurlencode((string) $probe["message"]),
                ],
            );
        } catch (\Throwable $e) {
            Logger::error("admin", "DB capability test failed", [
                "error" => $e->getMessage(),
            ]);
            self::redirect_with_notice("db_test_failed", [
                "message" => rawurlencode($e->getMessage()),
            ]);
        }
    }

    public static function handle_live_vector_query(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }
        check_admin_referer("wp_retriever_live_vector_query");

        $query = isset($_POST["wp_retriever_live_query"])
            ? sanitize_text_field(
                (string) wp_unslash($_POST["wp_retriever_live_query"]),
            )
            : "";
        if ($query === "") {
            self::redirect_with_notice("live_query_failed", [
                "error" => rawurlencode(self::text("live_query_empty")),
            ]);
        }

        $started = microtime(true);
        $provider = new LocalVectorProvider();
        $result = $provider->retrieve($query);
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
        $payload = [
            "query" => $query,
            "ok" => $result->ok,
            "error" => $result->error,
            "provider" => (string) Settings::get("embedding_provider"),
            "model" =>
                (string) Settings::get("embedding_provider") === "openai"
                    ? (string) Settings::get("openai_embedding_model")
                    : (string) Settings::get("custom_embedding_model"),
            "elapsed_ms" => $elapsed_ms,
            "hits" => [],
        ];

        foreach (array_slice($result->hits, 0, 10) as $hit) {
            $post = get_post($hit->post_id);
            $payload["hits"][] = [
                "post_id" => $hit->post_id,
                "title" =>
                    $post instanceof \WP_Post ? get_the_title($post) : "",
                "score" => $hit->score,
                "snippet" => $hit->snippet,
                "edit_url" => get_edit_post_link($hit->post_id, "raw") ?: "",
            ];
        }

        set_transient(
            self::live_query_result_key(),
            $payload,
            10 * MINUTE_IN_SECONDS,
        );
        self::redirect_with_notice(
            $result->ok ? "live_query_ok" : "live_query_failed",
            [
                "hits" => (string) count($payload["hits"]),
                "elapsed_ms" => (string) $elapsed_ms,
                "error" => rawurlencode((string) ($result->error ?? "")),
            ],
        );
    }

    public static function handle_retry_failed(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }
        check_admin_referer("wp_retriever_retry_failed");

        $post_id = isset($_POST["post_id"]) ? (int) $_POST["post_id"] : 0;
        $ids =
            $post_id > 0 ? [$post_id] : IndexDiagnostics::failed_post_ids(200);
        $processed = 0;
        $errors = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            PostSync::on_save_post($id, get_post($id));
            $processed++;
            if (
                get_post_meta($id, WP_RETRIEVER_POSTMETA_LAST_ERROR, true) !==
                ""
            ) {
                $errors++;
            }
        }

        self::redirect_with_notice(
            $errors > 0 ? "retry_failed_done_with_errors" : "retry_failed_done",
            [
                "processed" => (string) $processed,
                "errors" => (string) $errors,
            ],
        );
    }

    public static function render(): void
    {
        if (!current_user_can((string) WP_RETRIEVER_ADMIN_CAPABILITY)) {
            wp_die(esc_html(self::text("forbidden")));
        }

        $opts = Settings::all();
        $cap = VectorCapabilities::detect();
        $large_supported = VectorCapabilities::supports_dimensions(3072);
        $provider = (string) $opts["embedding_provider"];
        $custom_presets = Settings::custom_embedding_presets();
        $custom_preset =
            (string) ($opts["custom_embedding_preset"] ?? "custom");
        $model = Settings::normalize_openai_embedding_model(
            (string) $opts["openai_embedding_model"],
        );
        $initialized = (int) $opts["initial_backfill_completed_at"] > 0;
        ?>
		<div class="wrap">
			<h1>AI Retriever</h1>
			<?php self::render_notice(); ?>
			<p><strong><?php echo esc_html(
       self::text("database"),
   ); ?>:</strong> <?php echo esc_html(
    $cap["raw"] . " — " . $cap["reason"],
); ?></p>

			<h2><?php echo esc_html("1. " . self::text("db_capability_test")); ?></h2>
			<?php self::render_db_test(); ?>

			<hr>
			<h2><?php echo esc_html("2. " . self::text("rag_search_settings")); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields("wp_retriever"); ?>
				<?php self::hidden_state_fields($opts); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wp-retriever-search-mode"><?php echo esc_html(
          self::text("rag_search_mode"),
      ); ?></label></th>
						<td>
							<select id="wp-retriever-search-mode" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[search_mode]">
								<?php foreach (["off", "a_b_admin", "full"] as $mode): ?>
									<option value="<?php echo esc_attr($mode); ?>" <?php selected(
    $opts["search_mode"],
    $mode,
); ?>><?php echo esc_html(self::text("search_mode_" . $mode)); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html(self::text("display_badges")); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[display_source_badges]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[display_source_badges]" value="1" <?php checked(
    $opts["display_source_badges"],
); ?>> [RAG] / [<?php echo esc_html(
    self::text("standard_search"),
); ?>]</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-retriever-target-locale"><?php echo esc_html(
          self::text("target_language"),
      ); ?></label></th>
						<td>
							<select id="wp-retriever-target-locale" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[target_locale]">
								<?php foreach (LanguageOptions::options() as $locale => $label): ?>
									<option value="<?php echo esc_attr($locale); ?>" <?php selected(
    (string) $opts["target_locale"],
    $locale,
); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html(
           self::text("target_language_note"),
       ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wp-retriever-provider"><?php echo esc_html(
          self::text("embedding_provider"),
      ); ?></label></th>
						<td>
							<select id="wp-retriever-provider" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[embedding_provider]">
								<?php foreach (
            [
                "openai" => "OpenAI",
                "azure_openai" => "Azure OpenAI",
                "ollama" => "Ollama",
                "lmstudio" => "LM Studio",
                "infinity" => "Infinity",
                "tei" => "TEI",
                "custom_http" => "Custom HTTP (local/self-hosted)",
            ]
            as $provider_key => $provider_label
        ): ?>
									<option value="<?php echo esc_attr($provider_key); ?>" <?php selected(
    $provider,
    $provider_key,
); ?>><?php echo esc_html($provider_label); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr data-provider-row="openai">
						<th scope="row"><label for="wp-retriever-openai-key"><?php echo esc_html(
          self::text("openai_api_key"),
      ); ?></label></th>
						<td>
							<input id="wp-retriever-openai-key" type="password" class="regular-text" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[openai_api_key]" value="" placeholder="<?php echo esc_attr(
    !empty($opts["openai_api_key"]) ? self::text("api_key_configured") : "",
); ?>" autocomplete="off">
							<p class="description"><?php echo esc_html(
           self::text("api_key_blank_keeps_existing"),
       ); ?></p>
						</td>
					</tr>
					<tr data-provider-row="openai">
						<th scope="row"><label for="wp-retriever-openai-model"><?php echo esc_html(
          self::text("embedding_model"),
      ); ?></label></th>
						<td>
							<select id="wp-retriever-openai-model" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[openai_embedding_model]">
								<option value="text-embedding-3-small" data-dimensions="1536" <?php selected(
            $model,
            "text-embedding-3-small",
        ); ?>>text-embedding-3-small (1536)</option>
								<option value="text-embedding-3-large" data-dimensions="3072" <?php selected(
            $model,
            "text-embedding-3-large",
        ); ?> <?php disabled(
     !$large_supported && $model !== "text-embedding-3-large",
 ); ?>>text-embedding-3-large (3072)</option>
							</select>
							<?php if (!$large_supported): ?>
								<p class="description"><?php echo esc_html(
            self::text("large_not_supported"),
        ); ?></p>
							<?php endif; ?>
							<p class="description"><?php echo esc_html(
           self::text("model_change_note"),
       ); ?></p>
						</td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-custom-preset"><?php echo esc_html(
          self::text("custom_embedding_preset"),
      ); ?></label></th>
						<td>
							<select id="wp-retriever-custom-preset" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[custom_embedding_preset]">
								<option value="custom" <?php selected(
            $custom_preset,
            "custom",
        ); ?>><?php echo esc_html(
    self::text("custom_preset_manual"),
); ?></option>
								<?php foreach ($custom_presets as $preset_key => $preset): ?>
									<option value="<?php echo esc_attr(
             $preset_key,
         ); ?>" data-provider="<?php echo esc_attr(
    $preset["provider"],
); ?>" data-endpoint="<?php echo esc_attr(
    $preset["endpoint"],
); ?>" data-model="<?php echo esc_attr(
    $preset["model"],
); ?>" data-dimensions="<?php echo esc_attr(
    (string) $preset["dimensions"],
); ?>" data-format="<?php echo esc_attr($preset["format"]); ?>" <?php selected(
    $custom_preset,
    $preset_key,
); ?>><?php echo esc_html($preset["label"]); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html(
           self::text("custom_embedding_preset_note"),
       ); ?></p>
						</td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-custom-format"><?php echo esc_html(
          self::text("custom_embedding_format"),
      ); ?></label></th>
						<td><input id="wp-retriever-custom-format" type="text" class="regular-text" name="<?php echo esc_attr(
          WP_RETRIEVER_OPTION_KEY,
      ); ?>[custom_embedding_format]" value="<?php echo esc_attr(
    (string) $opts["custom_embedding_format"],
); ?>" readonly></td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-custom-endpoint"><?php echo esc_html(
          self::text("custom_endpoint"),
      ); ?></label></th>
						<td><input id="wp-retriever-custom-endpoint" type="url" class="regular-text" name="<?php echo esc_attr(
          WP_RETRIEVER_OPTION_KEY,
      ); ?>[custom_embedding_endpoint]" value="<?php echo esc_attr(
    (string) $opts["custom_embedding_endpoint"],
); ?>"></td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-custom-key"><?php echo esc_html(
          self::text("custom_api_key"),
      ); ?></label></th>
						<td>
							<input id="wp-retriever-custom-key" type="password" class="regular-text" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[custom_embedding_api_key]" value="" placeholder="<?php echo esc_attr(
    !empty($opts["custom_embedding_api_key"])
        ? self::text("api_key_configured")
        : "",
); ?>" autocomplete="off">
							<p class="description"><?php echo esc_html(
           self::text("api_key_blank_keeps_existing"),
       ); ?></p>
						</td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-custom-model"><?php echo esc_html(
          self::text("embedding_model"),
      ); ?></label></th>
						<td><input id="wp-retriever-custom-model" type="text" class="regular-text" name="<?php echo esc_attr(
          WP_RETRIEVER_OPTION_KEY,
      ); ?>[custom_embedding_model]" value="<?php echo esc_attr(
    (string) $opts["custom_embedding_model"],
); ?>"></td>
					</tr>
					<tr data-provider-row="azure_openai ollama lmstudio infinity tei custom_http">
						<th scope="row"><label for="wp-retriever-dimensions"><?php echo esc_html(
          self::text("dimensions"),
      ); ?></label></th>
						<td>
							<input id="wp-retriever-dimensions" type="number" min="1" max="4096" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[embedding_dimensions]" value="<?php echo esc_attr(
    (string) $opts["embedding_dimensions"],
); ?>">
							<p class="description"><?php echo esc_html(
           self::text("dimensions_note"),
       ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(self::text("save_changes")); ?>
			</form>

			<hr>
			<h2><?php echo esc_html("3. " . self::text("embedding_test")); ?></h2>
			<?php self::render_embedding_test($opts); ?>

			<hr>
			<h2><?php echo esc_html(
       "4. " . self::text("japanese_normalization_settings"),
   ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields("wp_retriever"); ?>
				<table class="form-table" role="presentation">
					<tr>
						<td colspan="2">
							<input type="hidden" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[japanese_normalization_enabled]" value="0">
							<label><input id="wp-retriever-japanese-normalization" type="checkbox" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[japanese_normalization_enabled]" value="1" <?php checked(
    $opts["japanese_normalization_enabled"],
); ?>> <?php echo esc_html(
    self::text("enable_japanese_normalization"),
); ?></label>
							<p class="description"><?php echo esc_html(
           self::text("japanese_normalization_note"),
       ); ?></p>
						</td>
					</tr>
					<tr data-normalization-row="1">
						<th scope="row"><label for="wp-retriever-custom-fields"><?php echo esc_html(
          self::text("indexed_custom_fields"),
      ); ?></label></th>
						<td>
							<textarea id="wp-retriever-custom-fields" class="large-text code" rows="3" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[indexed_custom_fields]"><?php echo esc_textarea(
    implode("\n", (array) $opts["indexed_custom_fields"]),
); ?></textarea>
							<p class="description"><?php echo esc_html(
           self::text("indexed_custom_fields_note"),
       ); ?></p>
						</td>
					</tr>
					<tr data-normalization-row="1">
						<th scope="row"><label for="wp-retriever-taxonomies"><?php echo esc_html(
          self::text("indexed_taxonomies"),
      ); ?></label></th>
						<td>
							<textarea id="wp-retriever-taxonomies" class="large-text code" rows="3" name="<?php echo esc_attr(
           WP_RETRIEVER_OPTION_KEY,
       ); ?>[indexed_taxonomies]"><?php echo esc_textarea(
    implode("\n", (array) $opts["indexed_taxonomies"]),
); ?></textarea>
							<p class="description"><?php echo esc_html(
           self::text("indexed_taxonomies_note"),
       ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(self::text("save_changes")); ?>
			</form>

			<hr>
			<h2><?php echo esc_html("5. " . self::text("initialization")); ?></h2>
			<?php self::render_initialization($opts, $cap, $initialized); ?>

			<hr>
			<h2><?php echo esc_html("6. " . self::text("index_diagnostics")); ?></h2>
			<?php self::render_index_diagnostics(); ?>

			<hr>
			<h2><?php echo esc_html("7. " . self::text("live_vector_query")); ?></h2>
			<?php self::render_live_vector_query(); ?>

			<hr>
			<h2><?php echo esc_html("8. " . self::text("rag_retrieval_tuning")); ?></h2>
			<?php self::render_retrieval_tuning($opts); ?>
		</div>
		<?php self::render_model_script(); ?>
		<?php
    }

    private static function render_retrieval_tuning(array $opts): void
    {
        ?>
        <p><?php echo esc_html(
            self::text("rag_retrieval_tuning_explain"),
        ); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields("wp_retriever"); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wp-retriever-top-k"><?php echo esc_html(
                        self::text("rag_top_k"),
                    ); ?></label></th>
                    <td>
                        <input id="wp-retriever-top-k" type="number" min="1" max="200" step="1" name="<?php echo esc_attr(
                            WP_RETRIEVER_OPTION_KEY,
                        ); ?>[top_k]" value="<?php echo esc_attr((string) $opts["top_k"]); ?>">
                        <p class="description"><?php echo esc_html(
                            self::text("rag_top_k_note"),
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-retriever-min-score"><?php echo esc_html(
                        self::text("rag_min_score"),
                    ); ?></label></th>
                    <td>
                        <input id="wp-retriever-min-score" type="number" min="0" max="1" step="0.01" name="<?php echo esc_attr(
                            WP_RETRIEVER_OPTION_KEY,
                        ); ?>[min_score]" value="<?php echo esc_attr((string) $opts["min_score"]); ?>">
                        <p class="description"><?php echo esc_html(
                            self::text("rag_min_score_note"),
                        ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(self::text("save_changes")); ?>
        </form>
        <?php
    }

    private static function render_live_vector_query(): void
    {
        $result = get_transient(self::live_query_result_key()); ?>
        <p><?php echo esc_html(self::text("live_query_explain")); ?></p>
        <form method="post" action="<?php echo esc_url(
            admin_url("admin-post.php"),
        ); ?>">
            <input type="hidden" name="action" value="wp_retriever_live_vector_query">
            <?php wp_nonce_field("wp_retriever_live_vector_query"); ?>
            <input type="search" class="regular-text" name="wp_retriever_live_query" value="<?php echo esc_attr(
                is_array($result) ? (string) ($result["query"] ?? "") : "",
            ); ?>" placeholder="<?php echo esc_attr(
    self::text("live_query_placeholder"),
); ?>">
            <?php submit_button(
                self::text("live_query_button"),
                "secondary",
                "submit",
                false,
            ); ?>
        </form>
        <?php if (is_array($result)): ?>
            <p class="description"><?php echo esc_html(
                sprintf(
                    self::text("live_query_meta"),
                    (string) ($result["provider"] ?? ""),
                    (string) ($result["model"] ?? ""),
                    (int) ($result["elapsed_ms"] ?? 0),
                ),
            ); ?></p>
            <?php if (!empty($result["hits"]) && is_array($result["hits"])): ?>
                <table class="widefat striped" style="max-width:960px;">
                    <thead><tr><th>ID</th><th><?php echo esc_html(
                        self::text("title"),
                    ); ?></th><th><?php echo esc_html(
    self::text("score"),
); ?></th><th><?php echo esc_html(
    self::text("best_chunk_snippet"),
); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($result["hits"] as $hit): ?>
                            <tr>
                                <td><?php echo esc_html(
                                    (string) (int) ($hit["post_id"] ?? 0),
                                ); ?></td>
                                <td>
                                    <?php if (!empty($hit["edit_url"])): ?>
                                        <a href="<?php echo esc_url(
                                            (string) $hit["edit_url"],
                                        ); ?>"><?php echo esc_html(
    (string) ($hit["title"] ?? ""),
); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html(
                                            (string) ($hit["title"] ?? ""),
                                        ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(
                                    number_format(
                                        (float) ($hit["score"] ?? 0.0),
                                        4,
                                    ),
                                ); ?></td>
                                <td><?php echo esc_html(
                                    wp_html_excerpt(
                                        (string) ($hit["snippet"] ?? ""),
                                        240,
                                        "…",
                                    ),
                                ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="description"><?php echo esc_html(
                    self::text("live_query_no_hits"),
                ); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private static function render_index_diagnostics(): void
    {
        $summary = IndexDiagnostics::summary(); ?>
        <table class="widefat striped" style="max-width:760px;">
            <tbody>
                <tr><th><?php echo esc_html(
                    self::text("eligible_posts"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["eligible_posts"],
); ?></td></tr>
                <tr><th><?php echo esc_html(
                    self::text("indexed_posts"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["indexed_posts"],
); ?></td></tr>
                <tr><th><?php echo esc_html(
                    self::text("coverage"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["coverage_percent"],
); ?>%</td></tr>
                <tr><th><?php echo esc_html(
                    self::text("vector_chunks"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["chunk_count"],
); ?></td></tr>
                <tr><th><?php echo esc_html(
                    self::text("failed_posts"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["failed_count"],
); ?></td></tr>
                <tr><th><?php echo esc_html(
                    self::text("queue_status"),
                ); ?></th><td><?php echo esc_html(
    (string) $summary["queue_status"] .
        " (" .
        (int) $summary["queue_processed"] .
        "/" .
        (int) $summary["queue_total"] .
        ", errors=" .
        (int) $summary["queue_errors"] .
        ")",
); ?></td></tr>
            </tbody>
        </table>
        <?php if ($summary["failed_posts"] !== []): ?>
            <h3><?php echo esc_html(self::text("failed_post_list")); ?></h3>
            <form method="post" action="<?php echo esc_url(
                admin_url("admin-post.php"),
            ); ?>" style="margin:0 0 1em;">
                <input type="hidden" name="action" value="wp_retriever_retry_failed">
                <?php wp_nonce_field("wp_retriever_retry_failed"); ?>
                <?php submit_button(
                    self::text("retry_all_failed"),
                    "secondary",
                    "submit",
                    false,
                ); ?>
            </form>
            <table class="widefat striped" style="max-width:960px;">
                <thead><tr><th>ID</th><th><?php echo esc_html(
                    self::text("title"),
                ); ?></th><th><?php echo esc_html(
    self::text("status"),
); ?></th><th><?php echo esc_html(
    self::text("error"),
); ?></th><th><?php echo esc_html(self::text("actions")); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($summary["failed_posts"] as $failed): ?>
                        <tr>
                            <td><?php echo esc_html(
                                (string) $failed["post_id"],
                            ); ?></td>
                            <td>
                                <?php if ($failed["edit_url"] !== ""): ?>
                                    <a href="<?php echo esc_url(
                                        $failed["edit_url"],
                                    ); ?>"><?php echo esc_html(
    $failed["title"] !== "" ? $failed["title"] : "(no title)",
); ?></a>
                                <?php else: ?>
                                    <?php echo esc_html(
                                        $failed["title"] !== ""
                                            ? $failed["title"]
                                            : "(no title)",
                                    ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($failed["status"]); ?></td>
                            <td><?php echo esc_html(
                                wp_html_excerpt($failed["error"], 220, "…"),
                            ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(
                                    admin_url("admin-post.php"),
                                ); ?>">
                                    <input type="hidden" name="action" value="wp_retriever_retry_failed">
                                    <input type="hidden" name="post_id" value="<?php echo esc_attr(
                                        (string) $failed["post_id"],
                                    ); ?>">
                                    <?php wp_nonce_field(
                                        "wp_retriever_retry_failed",
                                    ); ?>
                                    <?php submit_button(
                                        self::text("retry"),
                                        "secondary small",
                                        "submit",
                                        false,
                                    ); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="description"><?php echo esc_html(
                self::text("no_failed_posts"),
            ); ?></p>
        <?php endif; ?>
        <?php
    }

    private static function render_db_test(): void
    {
        echo "<p>" . esc_html(self::text("db_test_explain")) . "</p>"; ?>
        <form method="post" action="<?php echo esc_url(
            admin_url("admin-post.php"),
        ); ?>">
            <input type="hidden" name="action" value="wp_retriever_test_db">
            <?php wp_nonce_field("wp_retriever_test_db"); ?>
            <?php submit_button(
                self::text("db_test_button"),
                "secondary",
                "submit",
                false,
            ); ?>
        </form>
        <?php
    }

    private static function render_embedding_test(array $opts): void
    {
        $provider = (string) ($opts["embedding_provider"] ?? "");
        echo "<p>" . esc_html(self::text("embedding_test_explain")) . "</p>";
        if ($provider === "openai") {
            echo '<p class="description"><strong>' .
                esc_html(self::text("warning")) .
                ":</strong> " .
                esc_html(self::text("embedding_test_openai_warning")) .
                "</p>";
        }
        ?>
        <form method="post" action="<?php echo esc_url(
            admin_url("admin-post.php"),
        ); ?>">
            <input type="hidden" name="action" value="wp_retriever_test_embedding">
            <?php wp_nonce_field("wp_retriever_test_embedding"); ?>
            <?php submit_button(
                self::text("embedding_test_button"),
                "secondary",
                "submit",
                false,
            ); ?>
        </form>
        <?php
    }

    private static function render_initialization(
        array $opts,
        array $cap,
        bool $initialized,
    ): void {
        if ($initialized) {
            $completed_at = (int) $opts["initial_backfill_completed_at"];
            echo "<p>" .
                esc_html(
                    sprintf(
                        self::text("initialized_status"),
                        wp_date("Y-m-d H:i:s", $completed_at),
                        (int) $opts["initial_backfill_processed"],
                        (int) $opts["initial_backfill_errors"],
                    ),
                ) .
                "</p>";
            return;
        }

        if ((string) $opts["initial_backfill_reset_reason"] !== "") {
            echo '<p class="notice notice-warning inline"><span>' .
                esc_html(self::text("needs_reinit")) .
                "</span></p>";
        }

        $queue = BackfillRunner::status();
        if (
            in_array(
                (string) $queue["status"],
                ["queued", "running", "paused"],
                true,
            )
        ) {
            self::render_queue_progress($queue);
            return;
        }
        if ((string) $queue["status"] === "cancelled") {
            echo '<p class="notice notice-warning inline"><span>' .
                esc_html(self::text("init_auto_cancelled")) .
                "</span></p>";
        }
        if ((string) $queue["status"] === "failed") {
            echo '<p class="notice notice-error inline"><span>' .
                esc_html(
                    sprintf(
                        self::text("queue_failed_status"),
                        (int) $queue["processed"],
                        (int) $queue["total"],
                        (int) $queue["errors"],
                    ),
                ) .
                "</span></p>";
        }

        if (!$cap["native_vector"] || !$cap["vector_index"]) {
            echo '<p class="description">' .
                esc_html(self::text("init_unavailable")) .
                "</p>";
            return;
        }
        ?>
		<p><?php echo esc_html(self::text("init_explain")); ?></p>
		<p class="description"><strong><?php echo esc_html(
      self::text("warning"),
  ); ?>:</strong> <?php echo esc_html(self::text("init_warning")); ?></p>
		<form method="post" action="<?php echo esc_url(
      admin_url("admin-post.php"),
  ); ?>">
			<input type="hidden" name="action" value="wp_retriever_initialize">
			<?php wp_nonce_field("wp_retriever_initialize"); ?>
			<?php submit_button(
       self::text("initialize_button"),
       "primary",
       "submit",
       false,
   ); ?>
		</form>
		<?php
    }

    private static function render_queue_progress(array $queue): void
    {
        $total = max(1, (int) $queue["total"]);
        $processed = min($total, max(0, (int) $queue["processed"]));
        $percent = (int) floor(($processed / $total) * 100);
        echo '<div id="wp-retriever-backfill-progress">';
        echo "<p data-wp-retriever-status-text>" .
            esc_html(
                sprintf(
                    self::text("init_progress"),
                    $processed,
                    (int) $queue["total"],
                    (int) $queue["errors"],
                ),
            ) .
            "</p>";
        echo '<div style="max-width:420px;height:14px;background:#dcdcde;border-radius:8px;overflow:hidden;"><div data-wp-retriever-progress-bar style="width:' .
            esc_attr((string) $percent) .
            '%;height:14px;background:#2271b1;"></div></div>';
        echo "<p><strong data-wp-retriever-percent>" .
            esc_html((string) $percent) .
            "%</strong></p>";
        echo '<p class="description" data-wp-retriever-detail-text>' .
            esc_html(self::queue_message((string) $queue["status"])) .
            "</p>";
        echo '<p class="description">' .
            esc_html(self::text("init_background_note")) .
            "</p>";
        echo '<p class="wp-retriever-backfill-controls">';
        echo '<button type="button" class="button" data-wp-retriever-control="pause">' .
            esc_html(self::text("init_pause")) .
            "</button> ";
        echo '<button type="button" class="button" data-wp-retriever-control="resume">' .
            esc_html(self::text("init_resume")) .
            "</button> ";
        echo '<button type="button" class="button button-link-delete" data-wp-retriever-control="cancel">' .
            esc_html(self::text("init_cancel")) .
            "</button>";
        echo "</p>";
        echo "</div>";
    }

    private static function hidden_state_fields(array $opts): void
    {
        foreach (
            [
                "initial_backfill_completed_at",
                "initial_backfill_processed",
                "initial_backfill_errors",
                "initial_backfill_reset_reason",
            ]
            as $key
        ) {
            echo '<input type="hidden" name="' .
                esc_attr(WP_RETRIEVER_OPTION_KEY . "[" . $key . "]") .
                '" value="' .
                esc_attr((string) ($opts[$key] ?? "")) .
                '">';
        }
    }

    private static function render_notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters from internal redirects.
        $status = isset($_GET["wp_retriever_status"])
            ? sanitize_key((string) wp_unslash($_GET["wp_retriever_status"]))
            : "";
        if ($status === "") {
            return;
        }
        $processed = isset($_GET["processed"]) ? (int) $_GET["processed"] : 0;
        $errors = isset($_GET["errors"]) ? (int) $_GET["errors"] : 0;
        $total = isset($_GET["total"]) ? (int) $_GET["total"] : 0;
        $hits = isset($_GET["hits"]) ? (int) $_GET["hits"] : 0;
        $dimensions = isset($_GET["dimensions"])
            ? (int) $_GET["dimensions"]
            : 0;
        $elapsed_ms = isset($_GET["elapsed_ms"])
            ? (int) $_GET["elapsed_ms"]
            : 0;
        $provider = isset($_GET["provider"])
            ? sanitize_key((string) wp_unslash($_GET["provider"]))
            : "";
        $family = isset($_GET["family"])
            ? sanitize_key((string) wp_unslash($_GET["family"]))
            : "";
        $index_used =
            isset($_GET["index_used"]) && (string) $_GET["index_used"] === "1";
        $nearest = isset($_GET["nearest"])
            ? sanitize_text_field((string) wp_unslash($_GET["nearest"]))
            : "";
        $distance = isset($_GET["distance"])
            ? sanitize_text_field((string) wp_unslash($_GET["distance"]))
            : "";
        $db_message = isset($_GET["message"])
            ? sanitize_text_field((string) wp_unslash($_GET["message"]))
            : "";
        $model = isset($_GET["model"])
            ? sanitize_text_field((string) wp_unslash($_GET["model"]))
            : "";
        $error = isset($_GET["error"])
            ? sanitize_text_field((string) wp_unslash($_GET["error"]))
            : "";
        $class = in_array(
            $status,
            [
                "init_done",
                "init_queued",
                "queue_processed",
                "embedding_test_ok",
                "db_test_ok",
                "live_query_ok",
                "retry_failed_done",
            ],
            true,
        )
            ? "notice-success"
            : "notice-error";
        $message = self::text($status);
        if ($status === "init_done") {
            $message = sprintf($message, $processed);
        } elseif ($status === "init_errors") {
            $message = sprintf($message, $processed, $errors);
        } elseif ($status === "init_queued") {
            $message = sprintf($message, $total);
        } elseif ($status === "queue_processed") {
            $message = sprintf($message, $processed, $total);
        } elseif ($status === "embedding_test_ok") {
            $message = sprintf(
                $message,
                $provider,
                $model,
                $dimensions,
                $elapsed_ms,
            );
        } elseif ($status === "embedding_test_failed") {
            $message = sprintf($message, $error);
        } elseif ($status === "db_test_ok") {
            $message = sprintf(
                $message,
                $family,
                $index_used ? self::text("yes") : self::text("no"),
                $nearest,
                $distance,
                $db_message,
            );
        } elseif ($status === "db_test_failed") {
            $message = sprintf($message, $db_message);
        } elseif ($status === "live_query_ok") {
            $message = sprintf($message, $hits, $elapsed_ms);
        } elseif ($status === "live_query_failed") {
            $message = sprintf($message, $error);
        } elseif (
            $status === "retry_failed_done" ||
            $status === "retry_failed_done_with_errors"
        ) {
            $message = sprintf($message, $processed, $errors);
        }
        echo '<div class="notice ' .
            esc_attr($class) .
            ' is-dismissible"><p>' .
            esc_html($message) .
            "</p></div>";
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private static function render_model_script(): void
    {
        ?>
		<script>
		(function () {
			const provider = document.getElementById('wp-retriever-provider');
			const model = document.getElementById('wp-retriever-openai-model');
			const dimensions = document.getElementById('wp-retriever-dimensions');
			const customPreset = document.getElementById('wp-retriever-custom-preset');
			const customEndpoint = document.getElementById('wp-retriever-custom-endpoint');
			const customModel = document.getElementById('wp-retriever-custom-model');
			const customFormat = document.getElementById('wp-retriever-custom-format');
			const providerRows = document.querySelectorAll('[data-provider-row]');
			const normalization = document.getElementById('wp-retriever-japanese-normalization');
			const normalizationRows = document.querySelectorAll('[data-normalization-row]');
			function syncProviderRows() {
				if (!provider) {
					return;
				}
				providerRows.forEach((row) => {
					const providers = (row.dataset.providerRow || '').split(/\s+/);
					row.style.display = providers.includes(provider.value) ? '' : 'none';
				});
				if (customPreset) {
					let firstVisible = null;
					Array.from(customPreset.options).forEach((option) => {
						const optionProvider = option.dataset.provider || 'custom_http';
						const visible = provider.value === 'custom_http' ? true : optionProvider === provider.value;
						option.hidden = !visible;
						option.disabled = !visible;
						if (visible && !firstVisible) {
							firstVisible = option;
						}
					});
					if (customPreset.selectedOptions.length && customPreset.selectedOptions[0].disabled && firstVisible) {
						customPreset.value = firstVisible.value;
					}
				}
				if (provider.value === 'openai' && model && dimensions) {
					const option = model.options[model.selectedIndex];
					dimensions.value = option && option.dataset.dimensions ? option.dataset.dimensions : '1536';
				}
			}
			function syncCustomPresetFields() {
				if (!customPreset || customPreset.value === 'custom') {
					return;
				}
				const option = customPreset.options[customPreset.selectedIndex];
				if (!option) {
					return;
				}
				if (customEndpoint && option.dataset.endpoint) {
					customEndpoint.value = option.dataset.endpoint;
				}
				if (customModel && option.dataset.model) {
					customModel.value = option.dataset.model;
				}
				if (dimensions && option.dataset.dimensions) {
					dimensions.value = option.dataset.dimensions;
				}
				if (customFormat && option.dataset.format) {
					customFormat.value = option.dataset.format;
				}
			}
			function syncNormalizationRows() {
				const visible = normalization ? normalization.checked : false;
				normalizationRows.forEach((row) => {
					row.style.display = visible ? '' : 'none';
				});
			}
			if (provider) {
				provider.addEventListener('change', syncProviderRows);
			}
			if (model) {
				model.addEventListener('change', syncProviderRows);
			}
			if (normalization) {
				normalization.addEventListener('change', syncNormalizationRows);
			}
			if (customPreset) {
				customPreset.addEventListener('change', syncCustomPresetFields);
			}
			syncProviderRows();
			syncCustomPresetFields();
			syncNormalizationRows();
		}());
		</script>
		<?php
    }

    private static function live_query_result_key(): string
    {
        return "wp_retriever_live_query_" . get_current_user_id();
    }

    private static function redirect_with_notice(
        string $status,
        array $args = [],
    ): never {
        $url = add_query_arg(
            array_merge(
                [
                    "page" => self::PAGE_SLUG,
                    "wp_retriever_status" => $status,
                ],
                $args,
            ),
            admin_url("options-general.php"),
        );
        wp_safe_redirect($url);
        exit();
    }

    private static function text(string $key): string
    {
        $ja = str_starts_with(strtolower((string) get_locale()), "ja");
        $copy = self::copy();
        $english = $copy["en"][$key] ?? $key;
        $translated = get_translations_for_domain("ai-retriever")->translate(
            $english,
        );
        if ($translated !== $english || !$ja) {
            return $translated;
        }
        return $copy["ja"][$key] ?? $english;
    }

    /** @return array{en:array<string,string>, ja:array<string,string>} */
    private static function copy(): array
    {
        return [
            "en" => [
                "forbidden" => "Forbidden",
                "database" => "Database",
                "yes" => "yes",
                "no" => "no",
                "db_capability_test" => "Database capability test",
                "db_test_explain" =>
                    "Create a temporary 3-dimensional vector table, insert probe rows, run EXPLAIN, run a nearest-neighbor query, then drop the probe table.",
                "db_test_button" => "Test database vector support",
                "db_test_ok" =>
                    'DB vector test passed. Family: %1$s, index used: %2$s, nearest: %3$s, distance: %4$s. %5$s',
                "db_test_failed" => "DB vector test failed: %s",
                "live_vector_query" => "Live vector query test",
                "live_query_explain" =>
                    "Run the current vector retrieval pipeline for one query and inspect the top matching posts.",
                "live_query_placeholder" => "Enter a test query",
                "live_query_button" => "Run vector query",
                "live_query_meta" =>
                    'Provider: %1$s, model: %2$s, elapsed: %3$d ms.',
                "live_query_no_hits" => "No vector hits returned.",
                "live_query_empty" =>
                    "Enter a query before running the vector test.",
                "live_query_ok" =>
                    'Live vector query completed. Hits: %1$d, elapsed: %2$d ms.',
                "live_query_failed" => "Live vector query failed: %s",
                "rag_retrieval_tuning" => "RAG retrieval tuning",
                "rag_retrieval_tuning_explain" =>
                    "Adjust how broadly vector retrieval contributes to search results. If RAG is matching too many unrelated posts, lower the candidate count or raise the minimum score.",
                "rag_top_k" => "Maximum RAG candidates",
                "rag_top_k_note" =>
                    "Upper limit of vector candidates before blending with core WordPress search. Smaller values narrow the retrieval range. Allowed range: 1-200.",
                "rag_min_score" => "Minimum RAG score",
                "rag_min_score_note" =>
                    "Only vector hits with this score or higher are used. Raise this to narrow RAG matches. Typical tuning range: 0.50-0.80.",
                "score" => "Score",
                "best_chunk_snippet" => "Best chunk snippet",
                "index_diagnostics" => "Index diagnostics",
                "eligible_posts" => "Eligible posts",
                "indexed_posts" => "Indexed posts",
                "coverage" => "Coverage",
                "vector_chunks" => "Vector chunks",
                "failed_posts" => "Failed posts",
                "queue_status" => "Queue status",
                "failed_post_list" => "Failed indexing list",
                "title" => "Title",
                "status" => "Status",
                "error" => "Error",
                "no_failed_posts" => "No failed indexing records found.",
                "actions" => "Actions",
                "retry" => "Retry",
                "retry_all_failed" => "Retry all failed posts",
                "retry_failed_done" =>
                    'Retried failed indexing records. Processed: %1$d, remaining errors: %2$d.',
                "retry_failed_done_with_errors" =>
                    'Retry finished with remaining errors. Processed: %1$d, remaining errors: %2$d.',
                "rag_search_settings" => "RAG search settings",
                "japanese_normalization_settings" =>
                    "Japanese query normalization settings",
                "rag_search_mode" => "RAG search mode",
                "search_mode_off" => "Off",
                "search_mode_a_b_admin" => "Admins only",
                "search_mode_full" => "Full",
                "display_badges" => "Display source badges",
                "standard_search" => "Standard search",
                "target_language" => "RAG target language",
                "target_language_note" =>
                    "Choose the WordPress-supported language that this RAG index should target. The list is loaded from the WordPress translation API so it follows current core language support.",
                "japanese_normalization" => "Japanese normalization",
                "enable_japanese_normalization" =>
                    "Enable Japanese query normalization",
                "japanese_normalization_note" =>
                    "Expands lexical search with width/case variants and normalizes vector test/search queries. This can improve matching for full-width/half-width Japanese and alphanumeric text.",
                "indexed_custom_fields" => "Custom fields to index",
                "indexed_custom_fields_note" =>
                    "Optional. One meta key per line or comma-separated. Values are sent to the embedding provider, so do not include private fields unless intended.",
                "indexed_taxonomies" => "Taxonomies to index",
                "indexed_taxonomies_note" =>
                    "Optional. One taxonomy slug per line or comma-separated, for example category or post_tag.",
                "embedding_provider" => "Embedding provider",
                "openai_api_key" => "OpenAI API key",
                "embedding_model" => "Embedding model",
                "large_not_supported" =>
                    "text-embedding-3-large requires native vector support for 3072 dimensions. It is disabled for this database.",
                "model_change_note" =>
                    "Changing the embedding model recreates the vector table and requires initialization again.",
                "dimensions" => "Dimensions",
                "dimensions_note" =>
                    "For Custom HTTP providers, set this to match the returned embedding dimensions.",
                "custom_embedding_preset" => "Provider/model preset",
                "custom_embedding_format" => "Request format",
                "custom_preset_manual" => "Manual custom HTTP settings",
                "custom_embedding_preset_note" =>
                    "Presets fill endpoint, model, and dimensions. External hosted providers are limited to OpenAI/Azure OpenAI; these custom presets are for local or self-hosted services.",
                "custom_endpoint" => "Custom embedding endpoint",
                "custom_api_key" => "Custom embedding API key",
                "api_key_configured" => "API key is configured",
                "api_key_blank_keeps_existing" =>
                    "Leave blank to keep the existing key.",
                "save_changes" => "Save Changes",
                "embedding_test" => "Embedding provider test",
                "embedding_test_explain" =>
                    "Run one small embedding request with the current provider settings before starting initialization.",
                "embedding_test_openai_warning" =>
                    "This sends a short test string to OpenAI and may incur a small API charge.",
                "embedding_test_button" => "Test embedding provider",
                "embedding_test_ok" =>
                    'Embedding test succeeded. Provider: %1$s, model: %2$s, dimensions: %3$d, elapsed: %4$d ms.',
                "embedding_test_failed" => "Embedding test failed: %s",
                "initialization" => "Initialization",
                "initialized_status" =>
                    'Initialized at %1$s. Processed %2$d posts with %3$d errors.',
                "needs_reinit" =>
                    "Embedding settings changed. Run initialization again.",
                "init_unavailable" =>
                    "Initialization is unavailable because native vector support is not enabled for this database.",
                "init_explain" =>
                    "Create embeddings for existing published content and store them in the local vector table.",
                "warning" => "Warning",
                "init_warning" =>
                    "The first initialization can take a long time, especially with many posts or a remote embedding API.",
                "initialize_button" => "Initialize",
                "init_done" => "Initialization completed. Processed %d posts.",
                "init_queued" =>
                    "Initialization queued %d posts. Indexing will start automatically while this page is open.",
                "queue_processed" =>
                    'Processed initialization batch. Progress: %1$d / %2$d.',
                "init_progress" =>
                    'Initialization progress: %1$d / %2$d posts processed, %3$d errors.',
                "init_background_note" =>
                    "Automatic indexing is running. You can leave this page open; if you close it, indexing will resume when you return and may also continue through WP-Cron where available.",
                "init_auto_running" => "Automatic indexing is running...",
                "init_auto_complete" =>
                    "Initialization is complete. RAG search is ready.",
                "init_auto_failed" =>
                    "Initialization stopped with errors. Check the plugin log and retry failed posts.",
                "init_auto_paused" => "Initialization is paused.",
                "init_auto_cancelled" => "Initialization was cancelled.",
                "init_auto_idle" => "Initialization is not currently running.",
                "init_auto_retrying" =>
                    "Temporary initialization error: %s. Retrying shortly...",
                "init_pause" => "Pause",
                "init_resume" => "Resume",
                "init_cancel" => "Cancel",
                "init_cancel_confirm" =>
                    "Cancel initialization? Already indexed chunks may remain until you initialize again.",
                "queue_failed_status" =>
                    'Initialization stopped after %1$d / %2$d posts with %3$d errors.',
                "init_errors" =>
                    'Initialization processed %1$d posts but found %2$d errors. Check post meta/logs and run again.',
                "init_failed" => "Initialization failed. Check the plugin log.",
                "init_unsupported" =>
                    "Native vector support is unavailable for this database.",
            ],
            "ja" => [
                "forbidden" => "権限がありません",
                "database" => "データベース",
                "yes" => "はい",
                "no" => "いいえ",
                "db_capability_test" => "データベースのチェック",
                "db_test_explain" =>
                    "一時的な3次元ベクトルテーブルを作成し、動作をチェックして、テーブルを削除します。",
                "db_test_button" => "データベースをチェック",
                "db_test_ok" =>
                    'データベースチェック成功。種別: %1$s、インデックス使用: %2$s、最近傍: %3$s、距離: %4$s。%5$s',
                "db_test_failed" => "データベースチェック失敗: %s",
                "live_vector_query" => "ライブベクトルクエリーテスト",
                "live_query_explain" =>
                    "現在のベクトル検索パイプラインでクエリーを1回実行し、上位一致投稿を確認します。",
                "live_query_placeholder" => "テストクエリーを入力",
                "live_query_button" => "クエリーを実行",
                "live_query_meta" =>
                    'プロバイダー: %1$s、モデル: %2$s、経過時間: %3$d ms。',
                "live_query_no_hits" => "ベクトル検索の結果はありません。",
                "live_query_empty" =>
                    "ベクトルテストを実行する前にクエリーを入力してください。",
                "live_query_ok" =>
                    'ライブベクトルクエリー完了。ヒット数: %1$d、経過時間: %2$d ms。',
                "live_query_failed" => "ライブベクトルクエリー失敗: %s",
                "rag_retrieval_tuning" => "RAG 取得範囲の調整",
                "rag_retrieval_tuning_explain" =>
                    "ベクトル検索の結果をどの程度広く検索結果に混ぜるかを調整します。RAG が関係の薄い投稿まで拾う場合は、候補数を減らすか、最小スコアを上げてください。",
                "rag_top_k" => "RAG 最大候補数",
                "rag_top_k_note" =>
                    "WordPress 標準検索と混ぜる前に取得するベクトル候補の上限です。小さくすると取得範囲が狭くなります。指定範囲: 1〜200。",
                "rag_min_score" => "RAG 最小スコア",
                "rag_min_score_note" =>
                    "このスコア以上のベクトル一致だけを採用します。値を上げると RAG の一致範囲が狭くなります。調整目安: 0.50〜0.80。",
                "score" => "スコア",
                "best_chunk_snippet" => "最適チャンク抜粋",
                "index_diagnostics" => "インデックス診断",
                "eligible_posts" => "対象投稿数",
                "indexed_posts" => "インデックス済み投稿数",
                "coverage" => "カバー率",
                "vector_chunks" => "ベクトルチャンク数",
                "failed_posts" => "失敗した投稿数",
                "queue_status" => "キュー状態",
                "failed_post_list" => "インデクシング失敗リスト",
                "title" => "タイトル",
                "status" => "ステータス",
                "error" => "エラー",
                "no_failed_posts" => "インデクシング失敗記録はありません。",
                "actions" => "操作",
                "retry" => "再試行",
                "retry_all_failed" => "失敗した投稿をすべて再試行",
                "retry_failed_done" =>
                    'インデクシング失敗記録を再試行しました。処理: %1$d、残りエラー: %2$d。',
                "retry_failed_done_with_errors" =>
                    '再試行後もエラーが残っています。処理: %1$d、残りエラー: %2$d。',
                "rag_search_settings" => "RAG 検索設定",
                "japanese_normalization_settings" => "日本語クエリー正規化設定",
                "rag_search_mode" => "RAG 検索モード",
                "search_mode_off" => "オフ",
                "search_mode_a_b_admin" => "管理者のみ",
                "search_mode_full" => "フル",
                "display_badges" => "検索元バッジを表示",
                "standard_search" => "標準検索",
                "target_language" => "RAG 検索対象言語",
                "target_language_note" =>
                    "この RAG インデックスが対象とする言語を、WordPress が対応する言語から選びます。一覧は WordPress の翻訳 API から取得するため、現在のコア対応言語に追従します。",
                "japanese_normalization" => "日本語クエリー正規化",
                "enable_japanese_normalization" => "日本語クエリー正規化",
                "japanese_normalization_note" =>
                    "全角/半角や大文字/小文字の揺らぎを文字列検索に追加し、ベクトルのテスト/検索クエリーを正規化します。",
                "indexed_custom_fields" =>
                    "インデックス対象のカスタムフィールド",
                "indexed_custom_fields_note" =>
                    "任意。メタキーを1行に1つ、またはカンマ区切りで指定します。値は埋め込みプロバイダーに送信されるため、個人情報は含めないでください。",
                "indexed_taxonomies" => "インデックス対象の分類基準",
                "indexed_taxonomies_note" =>
                    "任意。分類の識別子を1行に1つ、またはカンマ区切りで指定します。例: category, post_tag。",
                "embedding_provider" => "埋め込みプロバイダー",
                "openai_api_key" => "埋め込み API キー",
                "embedding_model" => "埋め込みモデル",
                "large_not_supported" =>
                    "text-embedding-3-large は 3072 次元のネイティブベクトル機能が必要です。このデータベースでは無効です。",
                "model_change_note" =>
                    "埋め込みモデルを変更するとベクトルテーブルを作り直すため、再初期化が必要です。",
                "dimensions" => "次元数",
                "dimensions_note" =>
                    "Custom HTTP プロバイダーが返すベクトル次元数に合わせて設定してください。",
                "custom_embedding_preset" => "プロバイダー/モデル候補",
                "custom_embedding_format" => "リクエスト形式",
                "custom_preset_manual" => "手動設定",
                "custom_embedding_preset_note" =>
                    "候補を選び、必要ならエンドポイント、モデル、次元数を入力してください。外部 hosted provider は OpenAI/Azure OpenAI に限定し、ここではローカルまたは self-hosted サービスを想定しています。",
                "custom_endpoint" => "埋め込みエンドポイント",
                "custom_api_key" => "埋め込み API キー",
                "api_key_configured" => "API キー設定済み",
                "api_key_blank_keeps_existing" =>
                    "空欄のまま保存すると既存のキーを保持します。",
                "save_changes" => "変更を保存",
                "embedding_test" => "埋め込みプロバイダーのチェック",
                "embedding_test_explain" =>
                    "初期化の前に、現在のプロバイダー設定で埋め込みリクエストを1回実行します。",
                "embedding_test_openai_warning" =>
                    "短いテスト文字列を OpenAI に送信します。少額の API 利用料が発生する可能性があります。",
                "embedding_test_button" => "埋め込みプロバイダーをチェック",
                "embedding_test_ok" =>
                    '埋め込みテスト成功。プロバイダー: %1$s、モデル: %2$s、次元数: %3$d、経過時間: %4$d ms。',
                "embedding_test_failed" => "埋め込みテスト失敗: %s",
                "initialization" => "初期化",
                "initialized_status" =>
                    '%1$s に初期化済み。%2$d 件を処理、エラー %3$d 件。',
                "needs_reinit" =>
                    "RAG 検索設定が変更されました。再度初期化してください。",
                "init_unavailable" =>
                    "このデータベースではネイティブベクトル機能が有効でないため、初期化できません。",
                "init_explain" =>
                    "既存の公開済みコンテンツの埋め込みを作成し、ローカルのベクトルテーブルに保存します。",
                "warning" => "注意",
                "init_warning" =>
                    "初回のロードには時間がかかります。投稿数が多い場合や外部の埋め込み API を使う場合は特に時間がかかります。",
                "initialize_button" => "初期化",
                "init_done" => "初期化が完了しました。%d 件を処理しました。",
                "init_queued" =>
                    "初期化キューに %d 件を追加しました。この画面を開いている間、自動でインデックス処理を開始します。",
                "queue_processed" =>
                    '初期化バッチを処理しました。進捗: %1$d / %2$d。',
                "init_progress" =>
                    '初期化の進捗: %1$d / %2$d 件処理済み、エラー %3$d 件。',
                "init_background_note" =>
                    "自動インデックス処理を実行中です。この画面を開いたままにしてください。閉じた場合は再度この画面を開くと再開し、利用可能な環境では WP-Cron でも処理を継続します。",
                "init_auto_running" => "自動インデックス処理を実行中です...",
                "init_auto_complete" =>
                    "初期化が完了しました。RAG 検索を利用できます。",
                "init_auto_failed" =>
                    "初期化がエラーで停止しました。プラグインログを確認し、失敗した投稿を再試行してください。",
                "init_auto_paused" => "初期化は一時停止中です。",
                "init_auto_cancelled" => "初期化はキャンセルされました。",
                "init_auto_idle" => "初期化は現在実行されていません。",
                "init_auto_retrying" =>
                    "一時的な初期化エラー: %s。まもなく再試行します...",
                "init_pause" => "一時停止",
                "init_resume" => "再開",
                "init_cancel" => "キャンセル",
                "init_cancel_confirm" =>
                    "初期化をキャンセルしますか？再度初期化するまで、すでに作成されたチャンクが一部残る場合があります。",
                "queue_failed_status" =>
                    '初期化は %1$d / %2$d 件の時点で停止しました。エラー %3$d 件。',
                "init_errors" =>
                    '初期化で %1$d 件を処理しましたが、%2$d 件のエラーがあります。投稿メタ/ログを確認して再実行してください。',
                "init_failed" =>
                    "初期化に失敗しました。プラグインログを確認してください。",
                "init_unsupported" =>
                    "このデータベースではネイティブベクトル機能を利用できません。",
            ],
        ];
    }
}
