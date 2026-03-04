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
        $requestEndpoint = add_query_arg(
            [
                'page' => '1',
                'limit' => '1',
            ],
            $endpoint
        );
        $requestHeaders = [
            'Accept' => 'application/json',
            'X-API-Key' => (string) $settings['api_key'],
            'X-API-Secret' => (string) $settings['api_secret'],
        ];
        $response = wp_remote_get(
            $requestEndpoint,
            [
                'timeout' => 15,
                'headers' => $requestHeaders,
            ]
        );

        if (is_wp_error($response)) {
            $this->logs->write('error', __('API connection test failed.', 'merchandillo-woocommerce-bridge'), [
                'endpoint' => $requestEndpoint,
                'request_headers' => $this->redact_headers($requestHeaders),
                'error' => $response->get_error_message(),
            ]);

            return [
                'ok' => false,
                'code' => 'request_error',
                'http_status' => 0,
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = $this->truncate_text((string) wp_remote_retrieve_body($response));
        $result = ['ok' => false, 'code' => 'unexpected_http_status', 'http_status' => $statusCode];

        if ($statusCode >= 200 && $statusCode < 300) {
            $result = ['ok' => true, 'code' => 'success', 'http_status' => $statusCode];
        } elseif (in_array($statusCode, [401, 403], true)) {
            $result = ['ok' => false, 'code' => 'unauthorized', 'http_status' => $statusCode];
        } elseif (404 === $statusCode) {
            $result = ['ok' => false, 'code' => 'endpoint_not_found', 'http_status' => $statusCode];
        } elseif ($statusCode >= 500) {
            $result = ['ok' => false, 'code' => 'server_error', 'http_status' => $statusCode];
        }

        $this->logs->write('info', __('API connection test request/response.', 'merchandillo-woocommerce-bridge'), [
            'endpoint' => $requestEndpoint,
            'request_headers' => $this->redact_headers($requestHeaders),
            'response_http_status' => $statusCode,
            'response_body' => $responseBody,
            'result_code' => $result['code'],
        ]);

        return $result;
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

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function redact_headers(array $headers): array
    {
        if (isset($headers['X-API-Key'])) {
            $headers['X-API-Key'] = '[redacted]';
        }
        if (isset($headers['X-API-Secret'])) {
            $headers['X-API-Secret'] = '[redacted]';
        }

        return $headers;
    }

    private function truncate_text(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        return substr($value, 0, 1000);
    }
}
