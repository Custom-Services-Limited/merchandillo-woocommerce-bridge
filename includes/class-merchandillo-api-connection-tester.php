<?php

final class Merchandillo_Api_Connection_Tester implements Merchandillo_Api_Connection_Tester_Interface
{
    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var Merchandillo_Log_Manager_Interface */
    private $logs;

    public function __construct(
        Merchandillo_Settings_Interface $settings,
        Merchandillo_Log_Manager_Interface $logs
    ) {
        $this->settings = $settings;
        $this->logs = $logs;
    }

    /**
     * @return array{ok:bool,code:string,http_status:int}
     */
    public function run(): array
    {
        $settings = $this->settings->get();
        if (!$this->has_required_settings($settings)) {
            return [
                'ok' => false,
                'code' => 'missing_credentials',
                'http_status' => 0,
            ];
        }

        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';
        $response = wp_remote_post(
            $endpoint,
            [
                'method' => 'POST',
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => (string) $settings['api_key'],
                    'X-API-Secret' => (string) $settings['api_secret'],
                    'X-Merchandillo-Test' => '1',
                ],
                'body' => wp_json_encode(['_test_connection' => true]),
            ]
        );

        if (is_wp_error($response)) {
            $this->logs->write('error', __('API connection test failed.', 'merchandillo-woocommerce-bridge'), [
                'error' => $response->get_error_message(),
            ]);

            return [
                'ok' => false,
                'code' => 'request_error',
                'http_status' => 0,
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);

        if ($statusCode >= 200 && $statusCode < 300) {
            return ['ok' => true, 'code' => 'success', 'http_status' => $statusCode];
        }

        if (in_array($statusCode, [400, 405, 422], true)) {
            return ['ok' => true, 'code' => 'reachable', 'http_status' => $statusCode];
        }

        if (in_array($statusCode, [401, 403], true)) {
            return ['ok' => false, 'code' => 'unauthorized', 'http_status' => $statusCode];
        }

        if (404 === $statusCode) {
            return ['ok' => false, 'code' => 'endpoint_not_found', 'http_status' => $statusCode];
        }

        if ($statusCode >= 500) {
            return ['ok' => false, 'code' => 'server_error', 'http_status' => $statusCode];
        }

        return ['ok' => false, 'code' => 'unexpected_http_status', 'http_status' => $statusCode];
    }

    /**
     * @param array<string,string> $settings
     */
    private function has_required_settings(array $settings): bool
    {
        return '' !== (string) $settings['api_base_url']
            && '' !== (string) $settings['api_key']
            && '' !== (string) $settings['api_secret'];
    }
}
