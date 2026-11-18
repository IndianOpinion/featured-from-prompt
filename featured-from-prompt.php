<?php

/**
 * Plugin Name:       Featured from prompt
 * Plugin URI:        https://github.com/IndianOpinion/featured-from-prompt
 * Description:       Suggests a featured image generated from a prompt built from the post, through any image API. Checks the image, holds it for an editor's Accept, and spends no more than the daily cap.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       featured-from-prompt
 */

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('FFP_VERSION')) {
    return;
}

define('FFP_VERSION', '0.1.0');
define('FFP_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';

register_activation_hook(__FILE__, [\IndianOpinion\FeaturedFromPrompt\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\FeaturedFromPrompt\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [\IndianOpinion\FeaturedFromPrompt\Plugin::class, 'boot']);
