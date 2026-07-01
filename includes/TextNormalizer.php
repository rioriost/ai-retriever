<?php
/**
 * Query normalization helpers.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

final class TextNormalizer
{
    private function __construct() {}

    public static function normalize_japanese(string $text): string
    {
        $text = trim(preg_replace("/\s+/u", " ", $text) ?? $text);
        if (function_exists("mb_convert_kana")) {
            $text = mb_convert_kana($text, "asKV", "UTF-8");
        }
        if (function_exists("mb_strtolower")) {
            $text = mb_strtolower($text, "UTF-8");
        } else {
            $text = strtolower($text);
        }
        return trim(preg_replace("/\s+/u", " ", $text) ?? $text);
    }

    /** @return string[] */
    public static function lexical_query_variants(string $query): array
    {
        $query = trim($query);
        if (
            $query === "" ||
            !(bool) Settings::get("japanese_normalization_enabled")
        ) {
            return $query === "" ? [] : [$query];
        }

        $variants = [$query, self::normalize_japanese($query)];
        if (function_exists("mb_convert_kana")) {
            $variants[] = trim(mb_convert_kana($query, "ASKV", "UTF-8"));
            $variants[] = trim(mb_convert_kana($query, "askV", "UTF-8"));
        }

        return array_values(
            array_unique(
                array_filter(
                    $variants,
                    static fn(string $variant): bool => $variant !== "",
                ),
            ),
        );
    }

    public static function vector_query(string $query): string
    {
        return (bool) Settings::get("japanese_normalization_enabled")
            ? self::normalize_japanese($query)
            : $query;
    }
}
