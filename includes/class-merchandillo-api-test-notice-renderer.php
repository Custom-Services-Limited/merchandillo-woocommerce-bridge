<?php

final class Merchandillo_Api_Test_Notice_Renderer
{
    /**
     * @param array<string,mixed> $request
     */
    public function render(array $request): void
    {
        if (!isset($request['api_test_result'])) {
            return;
        }

        $resultCode = sanitize_key((string) wp_unslash($request['api_test_result']));
        $statusCode = isset($request['api_test_http_status']) ? absint((string) wp_unslash($request['api_test_http_status'])) : 0;

        echo '<div class="notice notice-' . esc_attr($this->notice_class($resultCode)) . ' is-dismissible"><p>';
        echo esc_html($this->notice_message($resultCode, $statusCode));
        echo '</p></div>';
    }

    private function notice_class(string $resultCode): string
    {
        if (in_array($resultCode, ['success', 'reachable'], true)) {
            return 'success';
        }

        if ('server_error' === $resultCode) {
            return 'warning';
        }

        return 'error';
    }

    private function notice_message(string $resultCode, int $statusCode): string
    {
        if ('success' === $resultCode) {
            return sprintf(
                __('API connection test succeeded (HTTP %d).', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        if ('reachable' === $resultCode) {
            return sprintf(
                __('API connection test reached Merchandillo successfully (HTTP %d). The endpoint rejected test payload data, which is expected.', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        if ('unauthorized' === $resultCode) {
            return sprintf(
                __('API connection test failed: credentials were rejected (HTTP %d).', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        if ('endpoint_not_found' === $resultCode) {
            return sprintf(
                __('API connection test failed: endpoint not found (HTTP %d).', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        if ('server_error' === $resultCode) {
            return sprintf(
                __('API connection test reached Merchandillo, but the server returned an error (HTTP %d).', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        if ('missing_credentials' === $resultCode) {
            return __('API connection test could not run because API settings are incomplete.', 'merchandillo-woocommerce-bridge');
        }

        if ('request_error' === $resultCode) {
            return __('API connection test failed because the request could not be completed.', 'merchandillo-woocommerce-bridge');
        }

        if ('unexpected_http_status' === $resultCode) {
            return sprintf(
                __('API connection test returned an unexpected HTTP status (%d).', 'merchandillo-woocommerce-bridge'),
                $statusCode
            );
        }

        return __('API connection test finished with an unknown result.', 'merchandillo-woocommerce-bridge');
    }
}
