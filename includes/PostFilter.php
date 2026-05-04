<?php
/**
 * Shared post eligibility checks for indexing and rendering.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

final class PostFilter
{
    private function __construct() {}

    public static function is_eligible($post): bool
    {
        $post = get_post($post);
        if (!($post instanceof \WP_Post)) {
            return false;
        }
        if ($post->post_password !== "") {
            return false;
        }
        $types = Settings::get("post_types");
        $statuses = Settings::get("post_statuses");
        $excluded = array_map(
            "intval",
            (array) Settings::get("sync_excluded_post_ids"),
        );
        return in_array($post->post_type, (array) $types, true) &&
            in_array($post->post_status, (array) $statuses, true) &&
            !in_array((int) $post->ID, $excluded, true);
    }

    /** @param int[] $post_ids @return int[] */
    public static function filter_eligible_ids(array $post_ids): array
    {
        $out = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0 && self::is_eligible($post_id)) {
                $out[] = $post_id;
            }
        }
        return $out;
    }
}
