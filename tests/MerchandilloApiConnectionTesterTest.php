<?php

declare(strict_types=1);

final class MerchandilloApiConnectionTesterTest extends MerchandilloTestCase
{
    public function test_run_returns_missing_credentials_when_keys_absent(): void
    {
        $tester = $this->newApiConnectionTester();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'log_errors' => '1',
        ];

        $result = $tester->run();

        $this->assertSame(false, $result['ok']);
        $this->assertSame('missing_credentials', $result['code']);
        $this->assertSame(0, $result['http_status']);
    }

    public function test_run_returns_unexpected_status_for_bad_request_on_get_connection_check(): void
    {
        $tester = $this->newApiConnectionTester();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 400],
            'body' => 'validation failed',
        ];

        $result = $tester->run();

        $this->assertSame(false, $result['ok']);
        $this->assertSame('unexpected_http_status', $result['code']);
        $this->assertSame(400, $result['http_status']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_get_requests']);
        $this->assertStringContainsString(
            '/api/woocommerce/orders?page=1&limit=1',
            $GLOBALS['mwb_test_state']['remote_get_requests'][0][0]
        );
        $this->assertSame(0, $GLOBALS['mwb_test_state']['remote_get_requests'][0][1]['redirection']);
        $this->assertSame(true, $GLOBALS['mwb_test_state']['remote_get_requests'][0][1]['reject_unsafe_urls']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('info', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('"response_http_status":400', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringContainsString('"X-API-Key":"[redacted]"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringContainsString('"X-API-Secret":"[redacted]"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringNotContainsString('"X-API-Secret":"secret"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringNotContainsString('"response_body":', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }

    public function test_run_returns_unauthorized_when_credentials_rejected(): void
    {
        $tester = $this->newApiConnectionTester();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'bad-key',
            'api_secret' => 'bad-secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 401],
            'body' => 'unauthorized',
        ];

        $result = $tester->run();

        $this->assertSame(false, $result['ok']);
        $this->assertSame('unauthorized', $result['code']);
        $this->assertSame(401, $result['http_status']);
    }

    public function test_run_returns_request_error_when_http_call_fails(): void
    {
        $tester = $this->newApiConnectionTester();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['remote_get_response'] = new WP_Error('network_error', 'timeout');

        $result = $tester->run();

        $this->assertSame(false, $result['ok']);
        $this->assertSame('request_error', $result['code']);
        $this->assertSame(0, $result['http_status']);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['logger_calls']);
        $this->assertSame('error', $GLOBALS['mwb_test_state']['logger_calls'][0]['level']);
        $this->assertStringContainsString('"error":"timeout"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringContainsString('"X-API-Key":"[redacted]"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
        $this->assertStringContainsString('"X-API-Secret":"[redacted]"', $GLOBALS['mwb_test_state']['logger_calls'][0]['message']);
    }
}
