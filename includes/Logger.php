<?php
/**
 * Small bounded logger stored in one option row.
 *
 * @package WPRetriever
 */

declare(strict_types=1);

namespace WPRetriever;

final class Logger
{
    private const OPTION = "wp_retriever_log";
    private const LIMIT = 200;
    private const LEVELS = [
        "debug" => 10,
        "info" => 20,
        "warn" => 30,
        "error" => 40,
    ];

    private function __construct() {}

    public static function debug(
        string $channel,
        string $message,
        array $context = [],
    ): void {
        self::log("debug", $channel, $message, $context);
    }
    public static function info(
        string $channel,
        string $message,
        array $context = [],
    ): void {
        self::log("info", $channel, $message, $context);
    }
    public static function warn(
        string $channel,
        string $message,
        array $context = [],
    ): void {
        self::log("warn", $channel, $message, $context);
    }
    public static function error(
        string $channel,
        string $message,
        array $context = [],
    ): void {
        self::log("error", $channel, $message, $context);
    }

    private static function log(
        string $level,
        string $channel,
        string $message,
        array $context,
    ): void {
        $floor = (string) Settings::get("log_level");
        if ((self::LEVELS[$level] ?? 999) < (self::LEVELS[$floor] ?? 20)) {
            return;
        }
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows[] = [
            "ts" => gmdate("c"),
            "level" => $level,
            "channel" => $channel,
            "message" => $message,
            "context" => $context,
        ];
        $rows = array_slice($rows, -self::LIMIT);
        update_option(self::OPTION, $rows, false);
    }
}
