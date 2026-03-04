<?php
/**
 * Plugin Name: Merchandillo Bridge for WooCommerce
 * Plugin URI: https://merchandillo.com
 * Description: Sync WooCommerce order changes to Merchandillo via API key/secret without interrupting checkout flows.
 * Version: 0.2.0
 * Author: Merchandillo
 * Author URI: https://merchandillo.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: merchandillo-woocommerce-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('merchandillo_wc_bridge_read_version')) {
    function merchandillo_wc_bridge_read_version(): string
    {
        $fallback = '0.0.0-dev';
        $version_file = __DIR__ . '/VERSION';

        if (!is_readable($version_file)) {
            return $fallback;
        }

        $version = trim((string) file_get_contents($version_file));

        return $version !== '' ? $version : $fallback;
    }
}

define('MERCHANDILLO_WC_BRIDGE_VERSION', merchandillo_wc_bridge_read_version());
define('MERCHANDILLO_WC_BRIDGE_FILE', __FILE__);
define('MERCHANDILLO_WC_BRIDGE_DIR', plugin_dir_path(__FILE__));

require_once MERCHANDILLO_WC_BRIDGE_DIR . 'includes/bootstrap.php';

register_activation_hook(MERCHANDILLO_WC_BRIDGE_FILE, ['Merchandillo_WooCommerce_Bridge', 'activate']);
register_deactivation_hook(MERCHANDILLO_WC_BRIDGE_FILE, ['Merchandillo_WooCommerce_Bridge', 'deactivate']);

Merchandillo_WooCommerce_Bridge::instance();
