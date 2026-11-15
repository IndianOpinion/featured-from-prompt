<?php

/**
 * Plugin Name: Featured from prompt (must-use loader)
 * Description: Loads featured-from-prompt as a must-use plugin.
 *
 * Copy this file to wp-content/mu-plugins/featured-from-prompt-loader.php and
 * put the plugin folder at wp-content/mu-plugins/featured-from-prompt/.
 * Must-use plugins have no activation hook: the tables are created on the
 * first request (the init hook checks the schema version).
 */

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

require_once __DIR__ . '/featured-from-prompt/featured-from-prompt.php';
