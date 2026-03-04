<?php

declare(strict_types=1);

final class MerchandilloSyncAndPayloadTest extends MerchandilloTestCase
{
    public function test_queue_order_sync_schedules_single_event_when_ready(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $bridge->queue_order_sync(77);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['scheduled_events']);
        $this->assertSame('merchandillo_sync_order_event', $GLOBALS['mwb_test_state']['scheduled_events'][0][1]);
        $this->assertSame([77], $GLOBALS['mwb_test_state']['scheduled_events'][0][2]);
    }

    public function test_queue_order_sync_logs_warning_when_credentials_missing(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => '',
            'api_key' => '',
            'api_secret' => '',
            'log_errors' => '1',
        ];

        $bridge->queue_order_sync(90);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('warning', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('API settings are missing', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }

    public function test_handle_status_change_queues_sync_for_order_id(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $bridge->handle_status_change(91, 'pending', 'processing');

        $this->assertCount(1, $GLOBALS['mwb_test_state']['scheduled_events']);
        $this->assertSame([91], $GLOBALS['mwb_test_state']['scheduled_events'][0][2]);
    }

    public function test_payload_builder_contains_expected_core_fields(): void
    {
        $builder = new Merchandillo_Order_Payload_Builder();
        $order = $this->buildSampleOrder(123);

        $payload = $builder->build($order);

        $this->assertSame(123, $payload['id']);
        $this->assertSame('ORD-123', $payload['order_number']);
        $this->assertSame('Jane Doe', $payload['customer_name']);
        $this->assertSame('paid', $payload['payment_status']);
        $this->assertSame('UPS', $payload['courier']);
        $this->assertSame('TRACK-123', $payload['tracking_number']);
        $this->assertSame('black', $payload['items'][0]['product_options']['Color']);
        $this->assertSame('CA', $payload['shipping_address']['zone']);
    }

    public function test_sync_order_now_logs_error_when_http_status_is_not_success(): void
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
            'response' => ['code' => 500],
            'body' => 'server error',
        ];

        $bridge->sync_order_now(55);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_post_requests']);
        $this->assertSame(
            'https://data.merchandillo.com/api/woocommerce/orders',
            $GLOBALS['mwb_test_state']['remote_post_requests'][0][0]
        );
        $this->assertSame(0, $GLOBALS['mwb_test_state']['remote_post_requests'][0][1]['redirection']);
        $this->assertSame(true, $GLOBALS['mwb_test_state']['remote_post_requests'][0][1]['reject_unsafe_urls']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('error', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('"http_status":500', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringNotContainsString('"response_body":', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }

    public function test_sync_order_now_allows_local_dev_endpoint_by_disabling_reject_unsafe_urls(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'http://host.docker.internal:8787',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(58);
        $GLOBALS['mwb_test_state']['remote_post_response'] = [
            'response' => ['code' => 200],
            'body' => '',
        ];

        $bridge->sync_order_now(58);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_post_requests']);
        $this->assertSame(
            'http://host.docker.internal:8787/api/woocommerce/orders',
            $GLOBALS['mwb_test_state']['remote_post_requests'][0][0]
        );
        $this->assertSame(false, $GLOBALS['mwb_test_state']['remote_post_requests'][0][1]['reject_unsafe_urls']);
    }

    public function test_sync_order_now_logs_error_when_remote_returns_wp_error(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(56);
        $GLOBALS['mwb_test_state']['remote_post_response'] = new WP_Error('network_error', 'timeout');

        $bridge->sync_order_now(56);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('error', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('timeout', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }

    public function test_sync_order_now_does_not_call_api_when_credentials_missing(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $this->buildSampleOrder(57);

        $bridge->sync_order_now(57);

        $this->assertCount(0, $GLOBALS['mwb_test_state']['remote_post_requests']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('warning', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('API settings are missing', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }
}
