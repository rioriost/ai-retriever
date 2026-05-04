<?php
/**
 * Hybrid RAG + core search rewrite.
 *
 * @package WPRetliever
 */

declare(strict_types=1);

namespace WPRetliever;

use WPRetliever\Provider\LocalVectorProvider;
use WPRetliever\Provider\RetrieveResult;

final class SearchInterceptor
{
    private static array $processed_queries = [];
    /** @var array<int, string> */
    private static array $hit_sources = [];

    private function __construct() {}

    public static function register(): void
    {
        add_action("pre_get_posts", [self::class, "on_pre_get_posts"], 5);
        add_filter("posts_search", [self::class, "on_posts_search"], 10, 2);
        add_filter("the_title", [self::class, "on_the_title"], 10, 2);
    }

    public static function on_pre_get_posts($query): void
    {
        if (
            !($query instanceof \WP_Query) ||
            !$query->is_main_query() ||
            is_admin() ||
            !$query->is_search() ||
            $query->get("suppress_filters")
        ) {
            return;
        }
        $qid = spl_object_id($query);
        if (isset(self::$processed_queries[$qid])) {
            return;
        }
        $user_query = trim((string) $query->get("s"));
        if (
            $user_query === "" ||
            !Settings::should_intercept_search(
                current_user_can((string) WP_RETLIEVER_ADMIN_CAPABILITY),
            )
        ) {
            self::$processed_queries[$qid] = "skipped";
            return;
        }

        $cached = self::cache_lookup($user_query, $query);
        if ($cached !== null) {
            $eligible = self::filter_ids_by_query_context(
                PostFilter::filter_eligible_ids($cached["ids"]),
                $query,
            );
            if ($eligible !== []) {
                self::remember_hit_sources($eligible, $cached["sources"]);
                self::rewrite_query($query, $user_query, $eligible);
                self::$processed_queries[$qid] = "rewritten";
                return;
            }
        }

        $rag = (new LocalVectorProvider())->retrieve(
            TextNormalizer::vector_query($user_query),
        );
        $rag_ids = $rag->ok
            ? self::filter_ids_by_query_context(
                PostFilter::filter_eligible_ids($rag->post_ids()),
                $query,
            )
            : [];
        $core_ids = PostFilter::filter_eligible_ids(
            self::core_search_ids($query, $user_query),
        );
        $combined = self::merge_ranked_ids($rag_ids, $core_ids);
        if ($combined === []) {
            self::$processed_queries[$qid] = "fallback";
            return;
        }
        $sources = self::build_source_map($combined, $rag_ids, $core_ids);
        self::remember_hit_sources($combined, $sources);
        self::rewrite_query($query, $user_query, $combined);
        self::$processed_queries[$qid] = "rewritten";
        self::cache_store($user_query, $combined, $sources, $query);
    }

    public static function on_posts_search($search, $query)
    {
        if (
            $query instanceof \WP_Query &&
            (self::$processed_queries[spl_object_id($query)] ?? "") ===
                "rewritten"
        ) {
            return "";
        }
        return $search;
    }

    public static function on_the_title($title, $post_id = 0): string
    {
        if (
            !(bool) Settings::get("display_source_badges") ||
            !is_string($title) ||
            $title === "" ||
            str_contains($title, "wp-retliever-hit-badges")
        ) {
            return (string) $title;
        }
        $source = self::source_for_render_post((int) $post_id);
        return $source === null ? $title : self::badge_html($source) . $title;
    }

    private static function core_search_ids(
        \WP_Query $source_query,
        string $user_query,
    ): array {
        $args = is_array($source_query->query_vars)
            ? $source_query->query_vars
            : [];
        unset(
            $args["post__in"],
            $args["orderby"],
            $args["order"],
            $args["paged"],
            $args["offset"],
            $args["fields"],
            $args["no_found_rows"],
        );
        $args["fields"] = "ids";
        $args["posts_per_page"] = (int) Settings::get("top_k");
        $args["paged"] = 1;
        $args["no_found_rows"] = true;
        $args["suppress_filters"] = false;
        $out = [];
        foreach (
            TextNormalizer::lexical_query_variants($user_query)
            as $variant
        ) {
            $args["s"] = $variant;
            $q = new \WP_Query($args);
            foreach (array_map("intval", $q->posts) as $post_id) {
                if (!in_array($post_id, $out, true)) {
                    $out[] = $post_id;
                }
            }
            wp_reset_postdata();
        }
        return $out;
    }

    private static function merge_ranked_ids(
        array $rag_ids,
        array $core_ids,
    ): array {
        $k = 60.0;
        $items = [];
        foreach (["rag" => $rag_ids, "core" => $core_ids] as $ids) {
            foreach ($ids as $i => $id) {
                $id = (int) $id;
                $items[$id] ??= [
                    "id" => $id,
                    "score" => 0.0,
                    "best" => PHP_INT_MAX,
                ];
                $rank = $i + 1;
                $items[$id]["score"] += 1.0 / ($k + $rank);
                $items[$id]["best"] = min($items[$id]["best"], $rank);
            }
        }
        usort(
            $items,
            static fn(array $a, array $b): int => $b["score"] <=> $a["score"] ?:
            $a["best"] <=> $b["best"],
        );
        return array_values(
            array_map(static fn(array $item): int => (int) $item["id"], $items),
        );
    }

    private static function filter_ids_by_query_context(
        array $post_ids,
        \WP_Query $source_query,
    ): array {
        $post_ids = array_values(array_unique(array_map("intval", $post_ids)));
        if ($post_ids === []) {
            return [];
        }

        $context_args = self::taxonomy_context_args($source_query);
        if ($context_args === []) {
            return $post_ids;
        }

        $args = array_merge($context_args, [
            "post__in" => $post_ids,
            "fields" => "ids",
            "posts_per_page" => count($post_ids),
            "orderby" => "post__in",
            "no_found_rows" => true,
            "ignore_sticky_posts" => true,
            "suppress_filters" => false,
        ]);
        $q = new \WP_Query($args);
        $ids = array_map("intval", is_array($q->posts) ? $q->posts : []);
        wp_reset_postdata();
        return $ids;
    }

    private static function build_source_map(
        array $combined,
        array $rag_ids,
        array $core_ids,
    ): array {
        $rag = array_fill_keys(array_map("intval", $rag_ids), true);
        $core = array_fill_keys(array_map("intval", $core_ids), true);
        $out = [];
        foreach ($combined as $id) {
            $id = (int) $id;
            $out[$id] = isset($rag[$id], $core[$id])
                ? "both"
                : (isset($rag[$id])
                    ? "rag"
                    : "core");
        }
        return $out;
    }

    private static function remember_hit_sources(
        array $ids,
        array $sources,
    ): void {
        self::$hit_sources = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($sources[$id])) {
                self::$hit_sources[$id] = (string) $sources[$id];
            }
        }
    }

    private static function rewrite_query(
        \WP_Query $query,
        string $user_query,
        array $post_ids,
    ): void {
        $query->set("post__in", $post_ids);
        $query->set("orderby", "post__in");
        $query->set("order", "ASC");
        $query->set("s", $user_query);
        $query->set("post_status", "publish");
        $query->set("ignore_sticky_posts", true);
        $query->set("no_found_rows", false);
    }

    private static function source_for_render_post(int $post_id): ?string
    {
        if ($post_id <= 0 && function_exists("get_the_ID")) {
            $post_id = (int) get_the_ID();
        }
        if (
            $post_id <= 0 ||
            !isset(self::$hit_sources[$post_id]) ||
            is_admin()
        ) {
            return null;
        }
        if (function_exists("is_search") && !is_search()) {
            return null;
        }
        if (function_exists("in_the_loop") && !in_the_loop()) {
            return null;
        }
        return self::$hit_sources[$post_id];
    }

    private static function badge_html(string $source): string
    {
        $labels = [];
        if ($source === "rag" || $source === "both") {
            $labels[] = "RAG";
        }
        if ($source === "core" || $source === "both") {
            $labels[] = "標準検索";
        }
        $html =
            '<span class="wp-retliever-hit-badges" style="display:block;font-size:.72em;font-weight:400;line-height:1.4;margin:0 0 .15em;color:#666;">';
        foreach ($labels as $label) {
            $html .=
                '<span style="display:inline-block;margin-right:.35em;">[' .
                esc_html($label) .
                "]</span>";
        }
        return $html . "</span>";
    }

    private static function cache_key(
        string $query,
        ?\WP_Query $source_query = null,
    ): string {
        $context =
            $source_query instanceof \WP_Query
                ? self::query_context_fingerprint($source_query)
                : "";
        return "wp_retliever_q_" .
            substr(hash("sha256", $query . "|" . $context), 0, 32);
    }

    private static function cache_lookup(
        string $query,
        \WP_Query $source_query,
    ): ?array {
        $raw = get_transient(self::cache_key($query, $source_query));
        return is_array($raw) && isset($raw["ids"], $raw["sources"])
            ? $raw
            : null;
    }

    private static function cache_store(
        string $query,
        array $ids,
        array $sources,
        \WP_Query $source_query,
    ): void {
        set_transient(
            self::cache_key($query, $source_query),
            ["ids" => $ids, "sources" => $sources],
            (int) Settings::get("cache_ttl_seconds"),
        );
    }

    private static function query_context_fingerprint(
        \WP_Query $source_query,
    ): string {
        return wp_json_encode(self::taxonomy_context_args($source_query)) ?: "";
    }

    private static function taxonomy_context_args(
        \WP_Query $source_query,
    ): array {
        $context = [];
        $keys = [
            "cat",
            "category_name",
            "category__and",
            "category__in",
            "category__not_in",
            "tag",
            "tag_id",
            "tag__and",
            "tag__in",
            "tag__not_in",
            "tag_slug__and",
            "tag_slug__in",
            "taxonomy",
            "term",
            "tax_query",
        ];
        foreach ($keys as $key) {
            $value = $source_query->get($key);
            if ($value !== null && $value !== "" && $value !== []) {
                $context[$key] = $value;
            }
        }
        return $context;
    }
    public static function purge_query_cache(): int
    {
        global $wpdb;

        $prefix = "wp_retliever_q_";
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

        $keys = array_values(
            array_unique(
                array_filter(
                    $keys,
                    static fn(string $key): bool => str_starts_with(
                        $key,
                        $prefix,
                    ),
                ),
            ),
        );
        foreach ($keys as $key) {
            delete_transient($key);
        }

        return count($keys);
    }
}
