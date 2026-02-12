<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Merchandillo_WooCommerce_Bridge
{
    private const OPTION_NAME = 'merchandillo_sync_options';
    private const CRON_HOOK = 'merchandillo_sync_order_event';
    private const LOG_SOURCE = 'merchandillo-woocommerce-bridge';
    private const SETTINGS_PAGE_SLUG = 'merchandillo-woocommerce-bridge';
    private const LOG_ACTION_KEY = 'merchandillo_logs_action';
    private const LOG_NONCE_ACTION = 'merchandillo_logs_action';

    /** @var self|null */
    private static $instance = null;

    /** @var Merchandillo_Service_Locator|null */
    private $services = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        if (!get_option(self::OPTION_NAME)) {
            add_option(self::OPTION_NAME, Merchandillo_Settings::default_settings());
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'register_translations'], 5);
        add_action('plugins_loaded', [$this, 'bootstrap']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_log_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action(self::CRON_HOOK, [$this, 'sync_order_now'], 10, 1);
        add_filter('plugin_action_links_' . plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), [$this, 'add_settings_link']);
    }

    public function bootstrap(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_new_order', [$this, 'queue_order_sync'], 20, 1);
        add_action('woocommerce_update_order', [$this, 'queue_order_sync'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 20, 4);
    }

    public function register_translations(): void
    {
        $this->service_locator()->translation_manager()->register_hooks();
    }

    public function register_settings_page(): void
    {
        $this->service_locator()->admin_page()->register_settings_page();
    }

    public function register_settings(): void
    {
        $this->service_locator()->admin_page()->register_settings();
    }

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize_settings($input): array
    {
        return $this->service_locator()->settings()->sanitize($input);
    }

    public function handle_log_actions(): void
    {
        $this->service_locator()->admin_page()->handle_log_actions();
    }

    public function render_settings_page(): void
    {
        $this->service_locator()->admin_page()->render_settings_page();
    }

    public function enqueue_admin_assets(string $hookSuffix): void
    {
        $this->service_locator()->admin_page()->enqueue_admin_assets($hookSuffix);
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function add_settings_link(array $links): array
    {
        $url = $this->service_locator()->admin_page()->get_settings_page_url();
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'merchandillo-woocommerce-bridge') . '</a>';

        return $links;
    }

    public function handle_status_change(int $orderId, string $oldStatus, string $newStatus): void
    {
        $this->service_locator()->sync_service()->handle_status_change($orderId, $oldStatus, $newStatus);
    }

    public function queue_order_sync(int $orderId): void
    {
        $this->service_locator()->sync_service()->queue_order_sync($orderId);
    }

    public function sync_order_now(int $orderId): void
    {
        $this->service_locator()->sync_service()->sync_order_now($orderId);
    }

    private function service_locator(): Merchandillo_Service_Locator
    {
        if (null === $this->services) {
            $this->services = new Merchandillo_Service_Locator(
                self::OPTION_NAME,
                self::CRON_HOOK,
                self::LOG_SOURCE,
                self::SETTINGS_PAGE_SLUG,
                self::LOG_ACTION_KEY,
                self::LOG_NONCE_ACTION
            );
        }

        return $this->services;
    }
}
