<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Merchandillo_WooCommerce_Bridge
{
    private const OPTION_NAME = 'merchandillo_sync_options';
    private const CRON_HOOK = 'merchandillo_sync_order_event';
    private const LOG_SOURCE = 'merchandillo-woocommerce-bridge';

    /** @var self|null */
    private static $instance = null;

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
            add_option(self::OPTION_NAME, self::default_settings());
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'bootstrap']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
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

    /**
     * @return array<string,string>
     */
    private static function default_settings(): array
    {
        return [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'log_errors' => '1',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_settings(): array
    {
        $raw = get_option(self::OPTION_NAME, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        /** @var array<string,string> $settings */
        $settings = wp_parse_args($raw, self::default_settings());

        return $settings;
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            'manage_options',
            'merchandillo-woocommerce-bridge',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'merchandillo_sync_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => self::default_settings(),
            ]
        );

        add_settings_section(
            'merchandillo_sync_main',
            __('Platform Credentials', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_settings_section_description'],
            'merchandillo-woocommerce-bridge'
        );

        add_settings_field(
            'enabled',
            __('Enable Sync', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_enabled_field'],
            'merchandillo-woocommerce-bridge',
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_base_url',
            __('API Base URL', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_base_url_field'],
            'merchandillo-woocommerce-bridge',
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_key_field'],
            'merchandillo-woocommerce-bridge',
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_secret',
            __('API Secret', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_secret_field'],
            'merchandillo-woocommerce-bridge',
            'merchandillo_sync_main'
        );

        add_settings_field(
            'log_errors',
            __('Log Errors', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_log_errors_field'],
            'merchandillo-woocommerce-bridge',
            'merchandillo_sync_main'
        );
    }

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize_settings($input): array
    {
        $existing = $this->get_settings();
        $incoming = is_array($input) ? $input : [];

        $apiBaseUrl = esc_url_raw(trim((string) ($incoming['api_base_url'] ?? '')));
        $apiBaseUrl = rtrim($apiBaseUrl, '/');

        $apiKey = trim((string) ($incoming['api_key'] ?? ''));
        if ('' === $apiKey) {
            $apiKey = (string) $existing['api_key'];
        }

        $apiSecret = trim((string) ($incoming['api_secret'] ?? ''));
        if ('' === $apiSecret) {
            $apiSecret = (string) $existing['api_secret'];
        }

        return [
            'enabled' => !empty($incoming['enabled']) ? '1' : '0',
            'api_base_url' => $apiBaseUrl,
            'api_key' => sanitize_text_field($apiKey),
            'api_secret' => sanitize_text_field($apiSecret),
            'log_errors' => !empty($incoming['log_errors']) ? '1' : '0',
        ];
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Merchandillo Bridge for WooCommerce', 'merchandillo-woocommerce-bridge') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('merchandillo_sync_settings_group');
        do_settings_sections('merchandillo-woocommerce-bridge');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function render_settings_section_description(): void
    {
        echo '<p>' . esc_html__('Configure the API credentials for pushing order updates to Merchandillo. Failed sync attempts are logged and never break WooCommerce checkout.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_enabled_field(): void
    {
        $settings = $this->get_settings();
        $checked = '1' === (string) $settings['enabled'];

        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[enabled]" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html__('Queue order sync on create/update/status change', 'merchandillo-woocommerce-bridge');
        echo '</label>';
    }

    public function render_api_base_url_field(): void
    {
        $settings = $this->get_settings();
        $value = (string) $settings['api_base_url'];

        echo '<input type="url" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_base_url]" value="' . esc_attr($value) . '" placeholder="https://data.merchandillo.com" />';
        echo '<p class="description">' . esc_html__('Example: https://data.merchandillo.com', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_api_key_field(): void
    {
        $settings = $this->get_settings();
        $value = (string) $settings['api_key'];

        echo '<input type="text" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_key]" value="' . esc_attr($value) . '" autocomplete="off" />';
    }

    public function render_api_secret_field(): void
    {
        $settings = $this->get_settings();
        $hasSecret = '' !== (string) $settings['api_secret'];

        echo '<input type="password" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr($hasSecret ? '****************' : '') . '" />';
        echo '<p class="description">' . esc_html__('Leave empty to keep the current secret.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_log_errors_field(): void
    {
        $settings = $this->get_settings();
        $checked = '1' === (string) $settings['log_errors'];

        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[log_errors]" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html__('Write sync failures to WooCommerce logs', 'merchandillo-woocommerce-bridge');
        echo '</label>';
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function add_settings_link(array $links): array
    {
        $url = admin_url('options-general.php?page=merchandillo-woocommerce-bridge');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'merchandillo-woocommerce-bridge') . '</a>';

        return $links;
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

        $settings = $this->get_settings();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        if ('' === (string) $settings['api_base_url'] || '' === (string) $settings['api_key'] || '' === (string) $settings['api_secret']) {
            $this->log('warning', 'There was a problem syncing that order because API settings are missing.', ['order_id' => $orderId]);
            return;
        }

        if (false !== wp_next_scheduled(self::CRON_HOOK, [$orderId])) {
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 5, self::CRON_HOOK, [$orderId]);
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

        $settings = $this->get_settings();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            $this->log('warning', 'There was a problem syncing that order because it could not be loaded.', ['order_id' => $orderId]);
            return;
        }

        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';

        $payload = $this->build_order_payload($order);

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
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            $this->log('error', 'There was a problem syncing that order to Merchandillo.', [
                'order_id' => $orderId,
                'error' => $response->get_error_message(),
            ]);
            return;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->log('error', 'There was a problem syncing that order to Merchandillo.', [
                'order_id' => $orderId,
                'http_status' => $statusCode,
                'response_body' => substr((string) wp_remote_retrieve_body($response), 0, 1000),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function build_order_payload(WC_Order $order): array
    {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');

        $subtotal = 0.0;
        $items = [];

        foreach ($order->get_items('line_item') as $lineItem) {
            if (!$lineItem instanceof WC_Order_Item_Product) {
                continue;
            }

            $quantity = max(1, (int) $lineItem->get_quantity());
            $lineTotal = (float) $lineItem->get_total();
            $unitPrice = $lineTotal / $quantity;
            $product = $lineItem->get_product();

            $entry = [
                'product_id' => $product ? (int) $product->get_id() : 0,
                'product_sku' => $product ? (string) $product->get_sku() : '',
                'product_name' => (string) $lineItem->get_name(),
                'quantity' => (int) $lineItem->get_quantity(),
                'price' => round($unitPrice, 2),
                'total' => round($lineTotal, 2),
                'tax_amount' => round((float) $lineItem->get_total_tax(), 2),
            ];

            $options = $this->extract_item_options($lineItem);
            if (!empty($options)) {
                $entry['product_options'] = $options;
            }

            $items[] = $entry;
            $subtotal += (float) $lineItem->get_subtotal();
        }

        $dateCreated = $order->get_date_created();
        $trackingNumber = (string) $order->get_meta('_tracking_number', true);
        $trackingUrl = (string) $order->get_meta('_tracking_url', true);
        $courier = (string) $order->get_meta('_shipping_courier', true);
        $orderNumber = (string) $order->get_order_number();
        if ('' === trim($orderNumber)) {
            $orderNumber = (string) $order->get_id();
        }

        $customerName = trim((string) $order->get_formatted_billing_full_name());
        if ('' === $customerName) {
            $customerName = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        }
        if ('' === $customerName) {
            $customerName = 'Customer #' . (string) $order->get_id();
        }

        return [
            'id' => (int) $order->get_id(),
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'customer_email' => (string) $order->get_billing_email(),
            'customer_phone' => (string) $order->get_billing_phone(),
            'total_amount' => round((float) $order->get_total(), 2),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round((float) $order->get_total_tax(), 2),
            'shipping_amount' => round((float) $order->get_shipping_total(), 2),
            'discount_amount' => round((float) $order->get_discount_total(), 2),
            'currency' => (string) $order->get_currency(),
            'status' => (string) $order->get_status(),
            'payment_method' => (string) $order->get_payment_method_title(),
            'payment_status' => $order->is_paid() ? 'paid' : 'pending',
            'shipping_method' => (string) $order->get_shipping_method(),
            'tracking_number' => $trackingNumber,
            'courier' => $courier,
            'tracking_url' => $trackingUrl,
            'order_date' => $dateCreated ? (string) $dateCreated->date('Y-m-d') : gmdate('Y-m-d'),
            'notes' => (string) $order->get_customer_note(),
            'shipping_address' => $this->map_address($shipping),
            'billing_address' => $this->map_address($billing),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,string>
     */
    private function map_address(array $address): array
    {
        return [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'address_1' => (string) ($address['address_1'] ?? ''),
            'address_2' => (string) ($address['address_2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'postcode' => (string) ($address['postcode'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
            'zone' => (string) ($address['state'] ?? ''),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function extract_item_options(WC_Order_Item_Product $lineItem): array
    {
        $options = [];
        foreach ($lineItem->get_meta_data() as $meta) {
            $key = (string) $meta->key;
            if ('' === $key || 0 === strpos($key, '_')) {
                continue;
            }

            $rawValue = $meta->value;
            if (is_scalar($rawValue)) {
                $value = (string) $rawValue;
            } else {
                $encoded = wp_json_encode($rawValue);
                $value = false === $encoded ? '' : $encoded;
            }

            if ('' === trim($value)) {
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $settings = $this->get_settings();
        if ('1' !== (string) $settings['log_errors']) {
            return;
        }

        $normalizedLevel = in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)
            ? $level
            : 'error';

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log(
                $normalizedLevel,
                $message . (empty($context) ? '' : ' | ' . wp_json_encode($context)),
                ['source' => self::LOG_SOURCE]
            );
            return;
        }

        $line = '[' . self::LOG_SOURCE . '] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . wp_json_encode($context);
        }

        error_log($line);
    }
}
