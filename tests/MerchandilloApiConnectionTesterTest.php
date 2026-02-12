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

    public function test_run_returns_reachable_for_validation_error_status(): void
    {
        $tester = $this->newApiConnectionTester();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['remote_post_response'] = [
            'response' => ['code' => 400],
            'body' => 'validation failed',
        ];

        $result = $tester->run();

        $this->assertSame(true, $result['ok']);
        $this->assertSame('reachable', $result['code']);
        $this->assertSame(400, $result['http_status']);
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
        $GLOBALS['mwb_test_state']['remote_post_response'] = [
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
        $GLOBALS['mwb_test_state']['remote_post_response'] = new WP_Error('network_error', 'timeout');

        $result = $tester->run();

        $this->assertSame(false, $result['ok']);
        $this->assertSame('request_error', $result['code']);
        $this->assertSame(0, $result['http_status']);
    }
}
