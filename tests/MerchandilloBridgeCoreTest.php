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
        $this->assertContains('admin_action_merchandillo_push_order', $actionHooks);
        $this->assertContains('admin_notices', $actionHooks);
        $this->assertContains('admin_enqueue_scripts', $actionHooks);
        $this->assertContains('merchandillo_sync_order_event', $actionHooks);
        $this->assertContains('upgrader_process_complete', $actionHooks);

        $filtersByHook = [];
        foreach ($GLOBALS['mwb_test_state']['filters'] as $filter) {
            $filtersByHook[(string) $filter[0]] = (int) $filter[3];
        }

        $this->assertArrayHasKey('plugin_action_links_' . plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), $filtersByHook);
        $this->assertArrayHasKey('update_plugins_github.com', $filtersByHook);
        $this->assertArrayHasKey('plugins_api', $filtersByHook);
        $this->assertSame(4, $filtersByHook['update_plugins_github.com']);
        $this->assertSame(3, $filtersByHook['plugins_api']);
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
        $this->assertContains('woocommerce_order_item_add_action_buttons', $hooks);
    }

    public function test_render_order_push_button_outputs_action_link(): void
    {
        $bridge = $this->newBridge();
        $order = $this->buildSampleOrder(88);

        ob_start();
        $bridge->render_order_push_button($order);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Push to Merchandillo', $output);
        $this->assertStringContainsString('class="button mwb-order-push-btn"', $output);
        $this->assertStringContainsString('data-order-id="88"', $output);
        $this->assertStringContainsString('rgb(77, 121, 170)', $output);
        $this->assertStringContainsString('mwb-order-push-modal', $output);
        $this->assertStringContainsString('dashicons-media-code', $output);
    }

    public function test_handle_manual_push_order_queues_order_and_redirects_with_success_notice_state(): void
    {
        $bridge = $this->newBridge();
        $_GET = ['order_id' => '77'];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(77);
        $GLOBALS['mwb_test_state']['next_scheduled'] = false;
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $bridge->handle_manual_push_order();

        $this->assertCount(1, $GLOBALS['mwb_test_state']['scheduled_events']);
        $this->assertContains('merchandillo_push_order', $GLOBALS['mwb_test_state']['nonce_checks']);
        $this->assertStringContainsString('merchandillo_manual_push=queued', (string) $GLOBALS['mwb_test_state']['last_redirect']);
        $this->assertStringContainsString('merchandillo_order_id=77', (string) $GLOBALS['mwb_test_state']['last_redirect']);
    }

    public function test_handle_manual_push_order_sets_already_queued_status_when_event_exists(): void
    {
        $bridge = $this->newBridge();
        $_GET = ['order_id' => '77'];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(77);
        $GLOBALS['mwb_test_state']['next_scheduled'] = true;
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $bridge->handle_manual_push_order();

        $this->assertCount(0, $GLOBALS['mwb_test_state']['scheduled_events']);
        $this->assertStringContainsString('merchandillo_manual_push=already_queued', (string) $GLOBALS['mwb_test_state']['last_redirect']);
    }

    public function test_render_manual_push_notice_outputs_success_message(): void
    {
        $bridge = $this->newBridge();
        $_GET = [
            'merchandillo_manual_push' => 'queued',
            'merchandillo_order_id' => '33',
        ];

        ob_start();
        $bridge->render_manual_push_notice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('Order #33 was queued for Merchandillo sync.', $output);
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
                'api_base_url' => 'http://localhost:8787/',
                'api_key' => '',
                'api_secret' => '',
                'ui_language' => 'el',
                'log_errors' => '',
            ]
        );

        $this->assertSame('http://localhost:8787', $sanitized['api_base_url']);
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
                'api_base_url' => 'https://evil.example.com',
                'api_key' => 'k',
                'api_secret' => 's',
                'ui_language' => 'invalid-language',
                'log_errors' => '1',
            ]
        );

        $this->assertSame('en', $sanitized['ui_language']);
        $this->assertSame('https://data.merchandillo.com', $sanitized['api_base_url']);
    }

    public function test_sanitize_settings_accepts_local_docker_host_endpoint_with_port(): void
    {
        $bridge = $this->newBridge();

        $sanitized = $bridge->sanitize_settings(
            [
                'enabled' => '1',
                'api_base_url' => 'http://host.docker.internal:8787',
                'api_key' => 'k',
                'api_secret' => 's',
                'ui_language' => 'en',
                'log_errors' => '1',
            ]
        );

        $this->assertSame('http://host.docker.internal:8787', $sanitized['api_base_url']);
    }

    public function test_sanitize_settings_maps_merchandillo_mode_to_fixed_endpoint(): void
    {
        $bridge = $this->newBridge();

        $sanitized = $bridge->sanitize_settings(
            [
                'enabled' => '1',
                'api_base_url_mode' => 'merchandillo_com',
                'api_base_url_local' => 'http://localhost:8787',
                'api_key' => 'k',
                'api_secret' => 's',
                'ui_language' => 'en',
                'log_errors' => '1',
            ]
        );

        $this->assertSame('https://data.merchandillo.com', $sanitized['api_base_url']);
    }

    public function test_sanitize_settings_maps_local_dev_mode_to_local_url(): void
    {
        $bridge = $this->newBridge();

        $sanitized = $bridge->sanitize_settings(
            [
                'enabled' => '1',
                'api_base_url_mode' => 'local_dev',
                'api_base_url_local' => 'http://localhost:8899',
                'api_key' => 'k',
                'api_secret' => 's',
                'ui_language' => 'en',
                'log_errors' => '1',
            ]
        );

        $this->assertSame('http://localhost:8899', $sanitized['api_base_url']);
    }

    public function test_add_settings_link_appends_plugin_settings_link(): void
    {
        $bridge = $this->newBridge();
        $links = $bridge->add_settings_link(['<a href="https://example.test">Existing</a>']);

        $this->assertCount(2, $links);
        $this->assertStringContainsString('options-general.php?page=merchandillo-woocommerce-bridge', $links[1]);
        $this->assertStringContainsString('Settings', $links[1]);
    }

    public function test_calculate_payload_differences_ignores_false_positives_from_list_response_shape(): void
    {
        $bridge = $this->newBridge();
        $order = $this->buildSampleOrder(14);
        $payloadBuilder = new Merchandillo_Order_Payload_Builder();
        $localPayload = $payloadBuilder->build($order);

        $remoteOrder = [
            'order_number' => (string) $localPayload['order_number'],
            'customer_name' => (string) $localPayload['customer_name'],
            'customer_email' => (string) $localPayload['customer_email'],
            'customer_phone' => (string) $localPayload['customer_phone'],
            'status' => (string) $localPayload['status'],
            'subtotal' => number_format((float) $localPayload['subtotal'], 2, '.', ''),
            'tax_amount' => number_format((float) $localPayload['tax_amount'], 2, '.', ''),
            'shipping_amount' => number_format((float) $localPayload['shipping_amount'], 2, '.', ''),
            'discount_amount' => number_format((float) $localPayload['discount_amount'], 2, '.', ''),
            'total_amount' => number_format((float) $localPayload['total_amount'], 2, '.', ''),
            'currency' => (string) $localPayload['currency'],
            'shipping_address' => wp_json_encode($localPayload['shipping_address']),
            'billing_address' => wp_json_encode($localPayload['billing_address']),
            'payment_method' => (string) $localPayload['payment_method'],
            'payment_status' => (string) $localPayload['payment_status'],
            'shipping_method' => (string) $localPayload['shipping_method'],
            'tracking_number' => (string) $localPayload['tracking_number'],
            'notes' => (string) $localPayload['notes'],
            'order_date' => (string) $localPayload['order_date'] . 'T00:00:00.000Z',
        ];

        $method = new ReflectionMethod(Merchandillo_WooCommerce_Bridge::class, 'calculate_payload_differences');
        /** @var array<int,array{field:string,local:string,remote:string}> $differences */
        $differences = $method->invoke($bridge, $localPayload, $remoteOrder);

        $this->assertSame([], $differences);
    }

    public function test_find_remote_order_for_manual_compare_uses_reduced_timeout(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 200],
            'body' => wp_json_encode(['orders' => []]),
        ];

        $method = new ReflectionMethod(Merchandillo_WooCommerce_Bridge::class, 'find_remote_order_for_manual_compare');
        $method->invoke(
            $bridge,
            [
                'api_base_url' => 'https://data.merchandillo.com',
                'api_key' => 'key',
                'api_secret' => 'secret',
            ],
            [
                'id' => 77,
                'order_number' => 'ORD-77',
            ]
        );

        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_get_requests']);
        $this->assertSame(8, $GLOBALS['mwb_test_state']['remote_get_requests'][0][1]['timeout']);
    }

    public function test_push_order_to_merchandillo_now_uses_reduced_timeout(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(55);
        $GLOBALS['mwb_test_state']['remote_post_response'] = [
            'response' => ['code' => 200],
            'body' => '',
        ];

        $method = new ReflectionMethod(Merchandillo_WooCommerce_Bridge::class, 'push_order_to_merchandillo_now');
        $result = $method->invoke($bridge, 55);

        $this->assertTrue((bool) $result['ok']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_post_requests']);
        $this->assertSame(8, $GLOBALS['mwb_test_state']['remote_post_requests'][0][1]['timeout']);
    }
}
