<?php

declare(strict_types=1);

final class MerchandilloBridgeCoreTest extends MerchandilloTestCase
{
    public function test_instance_registers_wordpress_hooks(): void
    {
        Merchandillo_WooCommerce_Bridge::instance();

        $actionHooks = array_map(
            static function (array $action): string {
                return (string) $action[0];
            },
            $GLOBALS['mwb_test_state']['actions']
        );

        $this->assertContains('plugins_loaded', $actionHooks);
        $this->assertContains('admin_menu', $actionHooks);
        $this->assertContains('admin_init', $actionHooks);
        $this->assertContains('admin_enqueue_scripts', $actionHooks);
        $this->assertContains('merchandillo_sync_order_event', $actionHooks);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['filters']);
        $this->assertSame(
            'plugin_action_links_' . plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            $GLOBALS['mwb_test_state']['filters'][0][0]
        );
    }

    public function test_activate_sets_default_option_when_missing(): void
    {
        Merchandillo_WooCommerce_Bridge::activate();

        $options = $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'];
        $this->assertSame('1', $options['enabled']);
        $this->assertSame('https://data.merchandillo.com', $options['api_base_url']);
        $this->assertSame('en', $options['ui_language']);
        $this->assertSame('1', $options['log_errors']);
    }

    public function test_deactivate_clears_cron_hook(): void
    {
        Merchandillo_WooCommerce_Bridge::deactivate();

        $this->assertSame(
            ['merchandillo_sync_order_event'],
            $GLOBALS['mwb_test_state']['cleared_hooks']
        );
    }

    public function test_register_settings_adds_all_fields(): void
    {
        $bridge = $this->newBridge();
        $bridge->register_settings();

        $this->assertCount(1, $GLOBALS['mwb_test_state']['registered_settings']);
        $fieldIds = array_map(
            static function (array $field): string {
                return (string) $field[0];
            },
            $GLOBALS['mwb_test_state']['settings_fields']
        );

        $this->assertSame(['enabled', 'api_base_url', 'api_key', 'api_secret', 'ui_language', 'log_errors'], $fieldIds);
    }

    public function test_register_settings_page_registers_expected_slug(): void
    {
        $bridge = $this->newBridge();
        $bridge->register_settings_page();

        $this->assertCount(1, $GLOBALS['mwb_test_state']['options_pages']);
        $this->assertSame(
            'merchandillo-woocommerce-bridge',
            $GLOBALS['mwb_test_state']['options_pages'][0][3]
        );
    }

    public function test_bootstrap_registers_woocommerce_hooks_when_available(): void
    {
        $bridge = $this->newBridge();
        $bridge->bootstrap();

        $hooks = array_map(
            static function (array $action): string {
                return (string) $action[0];
            },
            $GLOBALS['mwb_test_state']['actions']
        );

        $this->assertContains('woocommerce_new_order', $hooks);
        $this->assertContains('woocommerce_update_order', $hooks);
        $this->assertContains('woocommerce_order_status_changed', $hooks);
    }

    public function test_enqueue_admin_assets_only_runs_on_plugin_settings_screen(): void
    {
        $bridge = $this->newBridge();

        $bridge->enqueue_admin_assets('dashboard_page_anything');
        $this->assertCount(0, $GLOBALS['mwb_test_state']['enqueued_styles']);

        $bridge->enqueue_admin_assets('settings_page_merchandillo-woocommerce-bridge');
        $this->assertCount(1, $GLOBALS['mwb_test_state']['enqueued_styles']);
        $this->assertSame('merchandillo-wc-bridge-admin', $GLOBALS['mwb_test_state']['enqueued_styles'][0]['handle']);
        $this->assertStringContainsString('/assets/admin.css', $GLOBALS['mwb_test_state']['enqueued_styles'][0]['src']);
    }

    public function test_sanitize_settings_preserves_existing_credentials_when_empty(): void
    {
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'existing-key',
            'api_secret' => 'existing-secret',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        $bridge = $this->newBridge();
        $sanitized = $bridge->sanitize_settings(
            [
                'enabled' => '1',
                'api_base_url' => 'https://api.example.com/',
                'api_key' => '',
                'api_secret' => '',
                'ui_language' => 'el',
                'log_errors' => '',
            ]
        );

        $this->assertSame('https://api.example.com', $sanitized['api_base_url']);
        $this->assertSame('existing-key', $sanitized['api_key']);
        $this->assertSame('existing-secret', $sanitized['api_secret']);
        $this->assertSame('el', $sanitized['ui_language']);
        $this->assertSame('1', $sanitized['enabled']);
        $this->assertSame('0', $sanitized['log_errors']);
    }

    public function test_sanitize_settings_falls_back_to_english_for_invalid_language(): void
    {
        $bridge = $this->newBridge();

        $sanitized = $bridge->sanitize_settings(
            [
                'enabled' => '1',
                'api_base_url' => 'https://api.example.com',
                'api_key' => 'k',
                'api_secret' => 's',
                'ui_language' => 'invalid-language',
                'log_errors' => '1',
            ]
        );

        $this->assertSame('en', $sanitized['ui_language']);
    }

    public function test_add_settings_link_appends_plugin_settings_link(): void
    {
        $bridge = $this->newBridge();
        $links = $bridge->add_settings_link(['<a href="https://example.test">Existing</a>']);

        $this->assertCount(2, $links);
        $this->assertStringContainsString('options-general.php?page=merchandillo-woocommerce-bridge', $links[1]);
        $this->assertStringContainsString('Settings', $links[1]);
    }
}
