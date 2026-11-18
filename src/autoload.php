<?php

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

/*
 * PSR-4 autoloader for IndianOpinion\FeaturedFromPrompt, so the plugin runs
 * without Composer. Also loads the function files.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\FeaturedFromPrompt\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (str_starts_with($relative, 'Tests\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/global-functions.php';
