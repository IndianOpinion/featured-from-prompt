<?php

/**
 * Uninstall: removes what the plugin created (PRD section 6).
 *
 * Accepted generated images and their attachment meta are kept: the meta is
 * the record that they were generated and drives their label.
 */

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

\IndianOpinion\FeaturedFromPrompt\Uninstall::run_all_sites();
