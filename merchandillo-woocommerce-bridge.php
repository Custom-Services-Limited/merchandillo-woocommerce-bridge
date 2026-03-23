<?php
/**
 * Plugin Name: Merchandillo Bridge for WooCommerce
 * Plugin URI: https://merchandillo.com
 * Description: Sync WooCommerce order changes to Merchandillo via API key/secret without interrupting checkout flows.
 * Version: 0.3.2
 * Author: Merchandillo
 * Author URI: https://merchandillo.com
 * Update URI: https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: merchandillo-woocommerce-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('merchandillo_wc_bridge_plugin_version')) {
    function merchandillo_wc_bridge_plugin_version(): string
    {
        $fallback = '0.0.0-dev';
        $plugin_file = __FILE__;

        if (!is_readable($plugin_file)) {
            return $fallback;
        }

        $contents = (string) file_get_contents($plugin_file);
        if (preg_match('/^[[:space:]]*\\*[[:space:]]Version:[[:space:]]*([^[:space:]]+).*/m', $contents, $matches) !== 1) {
            return $fallback;
        }

        $version = trim((string) $matches[1]);

        return $version !== '' ? $version : $fallback;
    }
}

define('MERCHANDILLO_WC_BRIDGE_VERSION', merchandillo_wc_bridge_plugin_version());
define('MERCHANDILLO_WC_BRIDGE_FILE', __FILE__);
define('MERCHANDILLO_WC_BRIDGE_DIR', plugin_dir_path(__FILE__));

require_once MERCHANDILLO_WC_BRIDGE_DIR . 'includes/bootstrap.php';

register_activation_hook(MERCHANDILLO_WC_BRIDGE_FILE, ['Merchandillo_WooCommerce_Bridge', 'activate']);
register_deactivation_hook(MERCHANDILLO_WC_BRIDGE_FILE, ['Merchandillo_WooCommerce_Bridge', 'deactivate']);

Merchandillo_WooCommerce_Bridge::instance();
