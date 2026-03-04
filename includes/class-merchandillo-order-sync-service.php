<?php

final class Merchandillo_Order_Sync_Service
{
    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var Merchandillo_Log_Manager_Interface */
    private $logs;

    /** @var Merchandillo_Order_Payload_Builder_Interface */
    private $payloadBuilder;

    /** @var string */
    private $cronHook;

    public function __construct(
        Merchandillo_Settings_Interface $settings,
        Merchandillo_Log_Manager_Interface $logs,
        Merchandillo_Order_Payload_Builder_Interface $payloadBuilder,
        string $cronHook
    ) {
        $this->settings = $settings;
        $this->logs = $logs;
        $this->payloadBuilder = $payloadBuilder;
        $this->cronHook = $cronHook;
    }

    public function handle_status_change(int $orderId, string $oldStatus, string $newStatus): void
    {
        unset($oldStatus, $newStatus);
        $this->queue_order_sync($orderId);
    }

    public function queue_order_sync(int $orderId): void
    {
        $orderId = absint($orderId);
        if ($orderId <= 0) {
            return;
        }

        $settings = $this->settings->get();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        if (!$this->has_required_api_settings($settings)) {
            $this->logs->write('warning', __('There was a problem syncing that order because API settings are missing.', 'merchandillo-woocommerce-bridge'), ['order_id' => $orderId]);
            return;
        }

        if (false !== wp_next_scheduled($this->cronHook, [$orderId])) {
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 5, $this->cronHook, [$orderId]);
        if (false === $scheduled) {
            // Fall back to immediate execution if event scheduling fails.
            $this->sync_order_now($orderId);
        }
    }

    public function sync_order_now(int $orderId): void
    {
        $orderId = absint($orderId);
        if ($orderId <= 0) {
            return;
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $settings = $this->settings->get();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        if (!$this->has_required_api_settings($settings)) {
            $this->logs->write('warning', __('There was a problem syncing that order because API settings are missing.', 'merchandillo-woocommerce-bridge'), ['order_id' => $orderId]);
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            $this->logs->write('warning', __('There was a problem syncing that order because it could not be loaded.', 'merchandillo-woocommerce-bridge'), ['order_id' => $orderId]);
            return;
        }

        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';
        $rejectUnsafeUrls = !$this->is_local_dev_endpoint($endpoint);

        $payload = $this->payloadBuilder->build($order);

        $response = wp_remote_post(
            $endpoint,
            [
                'method' => 'POST',
                'timeout' => 15,
                'redirection' => 0,
                'reject_unsafe_urls' => $rejectUnsafeUrls,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => (string) $settings['api_key'],
                    'X-API-Secret' => (string) $settings['api_secret'],
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            $this->logs->write('error', __('There was a problem syncing that order to Merchandillo.', 'merchandillo-woocommerce-bridge'), [
                'order_id' => $orderId,
                'error' => $response->get_error_message(),
            ]);
            return;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $logContext = [
                'order_id' => $orderId,
                'http_status' => $statusCode,
            ];
            if ($this->should_log_response_body()) {
                $logContext['response_body'] = substr((string) wp_remote_retrieve_body($response), 0, 1000);
            }

            $this->logs->write('error', __('There was a problem syncing that order to Merchandillo.', 'merchandillo-woocommerce-bridge'), $logContext);
        }
    }

    /**
     * @param array<string,string> $settings
     */
    private function has_required_api_settings(array $settings): bool
    {
        return '' !== (string) $settings['api_base_url']
            && '' !== (string) $settings['api_key']
            && '' !== (string) $settings['api_secret'];
    }

    private function should_log_response_body(): bool
    {
        return defined('MERCHANDILLO_WC_BRIDGE_LOG_REMOTE_RESPONSE_BODY')
            && true === MERCHANDILLO_WC_BRIDGE_LOG_REMOTE_RESPONSE_BODY;
    }

    private function is_local_dev_endpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return 'http' === $scheme && in_array($host, ['localhost', 'host.docker.internal'], true);
    }
}
