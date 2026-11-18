<?php

// phpcs:ignoreFile

declare(strict_types=1);

$root = sys_get_temp_dir() . '/ffp-tests-' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0777, true);
}
define('ABSPATH', $root . '/wp/');
define('WP_CONTENT_DIR', $root . '/wp-content');

require_once __DIR__ . '/stubs/classes.php';
require_once __DIR__ . '/stubs/functions.php';
WP::$root = $root;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/functions.php';
require_once dirname(__DIR__) . '/src/global-functions.php';

if (!defined('FFP_VERSION')) {
    define('FFP_VERSION', '0.1.0');
    define('FFP_FILE', dirname(__DIR__) . '/featured-from-prompt.php');
}

$owner = getmypid();
register_shutdown_function(static function () use ($root, $owner): void {
    if (getmypid() !== $owner || !is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
});

if (!function_exists('dbDelta')) {
    /**
     * Integration-suite stand-in for WordPress's dbDelta(): creates each
     * table when it does not exist. The plugin never trusts dbDelta anyway;
     * it reads the columns back with SHOW COLUMNS.
     *
     * @return array<string, string>
     */
    function dbDelta(string $sql): array
    {
        $db = $GLOBALS['ffp_test_dbdelta_target'] ?? null;
        if ($db === null) {
            return [];
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $statement));
        }
        return [];
    }
}
