<?php
/**
 * Build a gettext POT file for strings that are intentionally centralized.
 *
 * @package RiTriever
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/Admin/SettingsPage.php';

$reflection = new ReflectionClass('RiTriever\\Admin\\SettingsPage');
$method = $reflection->getMethod('copy');
$copy = $method->invoke(null);
$strings = [];

foreach (($copy['en'] ?? []) as $message) {
    if (is_string($message) && $message !== '') {
        $strings[$message] = true;
    }
}

foreach (
    [
        'RiTriever',
        'Native-vector RAG search for WordPress using MariaDB 11.7+ or compatible MySQL 9.x vector indexes. Embeds posts on publish/update and blends vector retrieval with standard WordPress search.',
        'RiTriever requires PHP 8.1 or higher.',
        'Site language (%s)',
    ] as $message
) {
    $strings[$message] = true;
}

ksort($strings, SORT_NATURAL | SORT_FLAG_CASE);

$output = $argv[1] ?? ($root . '/languages/ritriever.pot');
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create {$dir}\n");
    exit(1);
}

$pot = '';
$pot .= 'msgid ""' . "\n";
$pot .= 'msgstr ""' . "\n";
$pot .= '"Project-Id-Version: RiTriever\n"' . "\n";
$pot .= '"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/ritriever\n"' . "\n";
$pot .= '"POT-Creation-Date: YEAR-MO-DA HO:MI+ZONE\n"' . "\n";
$pot .= '"MIME-Version: 1.0\n"' . "\n";
$pot .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
$pot .= '"Content-Transfer-Encoding: 8bit\n"' . "\n";
$pot .= '"X-Domain: ritriever\n"' . "\n\n";

foreach (array_keys($strings) as $message) {
    $pot .= 'msgid "' . pot_escape($message) . '"' . "\n";
    $pot .= 'msgstr ""' . "\n\n";
}

file_put_contents($output, rtrim($pot) . "\n");

function pot_escape(string $value): string
{
    return str_replace(
        ["\\", "\"", "\n", "\r", "\t"],
        ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
        $value,
    );
}
