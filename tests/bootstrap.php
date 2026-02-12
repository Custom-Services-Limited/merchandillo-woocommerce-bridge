<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('MERCHANDILLO_WC_BRIDGE_FILE')) {
    define('MERCHANDILLO_WC_BRIDGE_FILE', dirname(__DIR__) . '/merchandillo-woocommerce-bridge.php');
}

if (!function_exists('mwb_test_default_state')) {
    /**
     * @return array<string,mixed>
     */
    function mwb_test_default_state(): array
    {
        return [
            'actions' => [],
            'filters' => [],
            'registered_settings' => [],
            'settings_sections' => [],
            'settings_fields' => [],
            'options_pages' => [],
            'options' => [],
            'upload_dir' => ['basedir' => sys_get_temp_dir()],
            'wc_log_file_path' => '',
            'current_user_can' => true,
            'is_admin' => true,
            'next_scheduled' => false,
            'schedule_single_event_result' => true,
            'scheduled_events' => [],
            'cleared_hooks' => [],
            'logger_calls' => [],
            'enqueued_styles' => [],
            'remote_post_response' => ['response' => ['code' => 200], 'body' => ''],
            'remote_post_requests' => [],
            'wc_get_order_return' => null,
            'last_redirect' => null,
            'nonce_checks' => [],
        ];
    }
}

if (!class_exists('MWB_Test_Logger')) {
    final class MWB_Test_Logger
    {
        /**
         * @param array<string,mixed> $context
         */
        public function log(string $level, string $message, array $context = []): void
        {
            $GLOBALS['mwb_test_state']['logger_calls'][] = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];
        }
    }
}

if (!function_exists('mwb_test_reset_state')) {
    function mwb_test_reset_state(): void
    {
        $GLOBALS['mwb_test_state'] = mwb_test_default_state();
        $GLOBALS['mwb_test_state']['logger'] = new MWB_Test_Logger();
    }
}

mwb_test_reset_state();

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var string */
        private $message;

        public function __construct(string $code = '', string $message = '')
        {
            unset($code);
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WooCommerce')) {
    class WooCommerce
    {
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        /** @var int */
        private $id;

        /** @var string */
        private $sku;

        public function __construct(int $id = 0, string $sku = '')
        {
            $this->id = $id;
            $this->sku = $sku;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_sku(): string
        {
            return $this->sku;
        }
    }
}

if (!class_exists('WC_Meta_Data')) {
    class WC_Meta_Data
    {
        /** @var string */
        public $key;

        /** @var mixed */
        public $value;

        /**
         * @param mixed $value
         */
        public function __construct(string $key, $value)
        {
            $this->key = $key;
            $this->value = $value;
        }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product
    {
        /** @var int */
        private $quantity;

        /** @var float */
        private $total;

        /** @var float */
        private $totalTax;

        /** @var float */
        private $subtotal;

        /** @var string */
        private $name;

        /** @var WC_Product|null */
        private $product;

        /** @var array<int,WC_Meta_Data> */
        private $metaData;

        /**
         * @param array<int,WC_Meta_Data> $metaData
         */
        public function __construct(
            int $quantity,
            float $total,
            float $totalTax,
            float $subtotal,
            string $name,
            ?WC_Product $product = null,
            array $metaData = []
        ) {
            $this->quantity = $quantity;
            $this->total = $total;
            $this->totalTax = $totalTax;
            $this->subtotal = $subtotal;
            $this->name = $name;
            $this->product = $product;
            $this->metaData = $metaData;
        }

        public function get_quantity(): int
        {
            return $this->quantity;
        }

        public function get_total(): float
        {
            return $this->total;
        }

        public function get_total_tax(): float
        {
            return $this->totalTax;
        }

        public function get_subtotal(): float
        {
            return $this->subtotal;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_product(): ?WC_Product
        {
            return $this->product;
        }

        /**
         * @return array<int,WC_Meta_Data>
         */
        public function get_meta_data(): array
        {
            return $this->metaData;
        }
    }
}

if (!class_exists('MWB_Test_Date')) {
    class MWB_Test_Date
    {
        /** @var int */
        private $timestamp;

        public function __construct(int $timestamp)
        {
            $this->timestamp = $timestamp;
        }

        public function date(string $format): string
        {
            return gmdate($format, $this->timestamp);
        }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var int */
        private $id;

        /** @var array<string,mixed> */
        private $data;

        /**
         * @param array<string,mixed> $data
         */
        public function __construct(int $id, array $data = [])
        {
            $this->id = $id;
            $this->data = $data;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        /**
         * @return array<string,mixed>
         */
        public function get_address(string $type): array
        {
            return isset($this->data[$type . '_address']) && is_array($this->data[$type . '_address']) ? $this->data[$type . '_address'] : [];
        }

        /**
         * @return array<int,mixed>
         */
        public function get_items(string $type = 'line_item'): array
        {
            unset($type);
            return isset($this->data['items']) && is_array($this->data['items']) ? $this->data['items'] : [];
        }

        public function get_date_created()
        {
            return isset($this->data['date_created']) ? $this->data['date_created'] : null;
        }

        public function get_meta(string $key, bool $single = true): string
        {
            unset($single);
            return isset($this->data['meta'][$key]) ? (string) $this->data['meta'][$key] : '';
        }

        public function get_order_number(): string
        {
            return isset($this->data['order_number']) ? (string) $this->data['order_number'] : '';
        }

        public function get_formatted_billing_full_name(): string
        {
            return isset($this->data['formatted_billing_full_name']) ? (string) $this->data['formatted_billing_full_name'] : '';
        }

        public function get_billing_first_name(): string
        {
            return isset($this->data['billing_first_name']) ? (string) $this->data['billing_first_name'] : '';
        }

        public function get_billing_last_name(): string
        {
            return isset($this->data['billing_last_name']) ? (string) $this->data['billing_last_name'] : '';
        }

        public function get_billing_email(): string
        {
            return isset($this->data['billing_email']) ? (string) $this->data['billing_email'] : '';
        }

        public function get_billing_phone(): string
        {
            return isset($this->data['billing_phone']) ? (string) $this->data['billing_phone'] : '';
        }

        public function get_total(): float
        {
            return isset($this->data['total']) ? (float) $this->data['total'] : 0.0;
        }

        public function get_total_tax(): float
        {
            return isset($this->data['total_tax']) ? (float) $this->data['total_tax'] : 0.0;
        }

        public function get_shipping_total(): float
        {
            return isset($this->data['shipping_total']) ? (float) $this->data['shipping_total'] : 0.0;
        }

        public function get_discount_total(): float
        {
            return isset($this->data['discount_total']) ? (float) $this->data['discount_total'] : 0.0;
        }

        public function get_currency(): string
        {
            return isset($this->data['currency']) ? (string) $this->data['currency'] : 'USD';
        }

        public function get_status(): string
        {
            return isset($this->data['status']) ? (string) $this->data['status'] : 'pending';
        }

        public function get_payment_method_title(): string
        {
            return isset($this->data['payment_method_title']) ? (string) $this->data['payment_method_title'] : '';
        }

        public function is_paid(): bool
        {
            return !empty($this->data['is_paid']);
        }

        public function get_shipping_method(): string
        {
            return isset($this->data['shipping_method']) ? (string) $this->data['shipping_method'] : '';
        }

        public function get_customer_note(): string
        {
            return isset($this->data['customer_note']) ? (string) $this->data['customer_note'] : '';
        }
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        unset($domain);
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        unset($domain);
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return $text;
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return trim($url);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $fileName): string
    {
        return preg_replace('/[^A-Za-z0-9._\-]/', '', basename($fileName));
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_kses')) {
    /**
     * @param array<string,mixed> $allowedHtml
     */
    function wp_kses(string $content, array $allowedHtml): string
    {
        unset($allowedHtml);
        return $content;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}

if (!function_exists('absint')) {
    /**
     * @param mixed $value
     */
    function absint($value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['mwb_test_state']['actions'][] = [$hook, $callback, $priority, $acceptedArgs];
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['mwb_test_state']['filters'][] = [$hook, $callback, $priority, $acceptedArgs];
    }
}

if (!function_exists('add_options_page')) {
    /**
     * @param callable $callback
     * @return string
     */
    function add_options_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, $callback): string
    {
        $GLOBALS['mwb_test_state']['options_pages'][] = [$pageTitle, $menuTitle, $capability, $menuSlug, $callback];
        return $menuSlug;
    }
}

if (!function_exists('register_setting')) {
    /**
     * @param array<string,mixed> $args
     */
    function register_setting(string $optionGroup, string $optionName, array $args = []): void
    {
        $GLOBALS['mwb_test_state']['registered_settings'][] = [$optionGroup, $optionName, $args];
    }
}

if (!function_exists('add_settings_section')) {
    /**
     * @param callable $callback
     */
    function add_settings_section(string $id, string $title, $callback, string $page): void
    {
        $GLOBALS['mwb_test_state']['settings_sections'][] = [$id, $title, $callback, $page];
    }
}

if (!function_exists('add_settings_field')) {
    /**
     * @param callable $callback
     */
    function add_settings_field(string $id, string $title, $callback, string $page, string $section): void
    {
        $GLOBALS['mwb_test_state']['settings_fields'][] = [$id, $title, $callback, $page, $section];
    }
}

if (!function_exists('settings_fields')) {
    function settings_fields(string $optionGroup): void
    {
        echo '<input type="hidden" name="option_page" value="' . esc_attr($optionGroup) . '" />';
    }
}

if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void
    {
        unset($page);
    }
}

if (!function_exists('get_submit_button')) {
    /**
     * @param array<string,string> $otherAttributes
     */
    function get_submit_button(
        ?string $text = null,
        string $type = 'primary',
        string $name = 'submit',
        bool $wrap = true,
        array $otherAttributes = []
    ): string {
        $label = null === $text ? 'Save Changes' : $text;
        $attributes = '';
        foreach ($otherAttributes as $key => $value) {
            $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
        }

        $button = '<input type="submit" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '" value="' . esc_attr($label) . '"' . $attributes . ' />';
        if ($wrap) {
            $button = '<p class="submit">' . $button . '</p>';
        }

        return $button;
    }
}

if (!function_exists('submit_button')) {
    /**
     * @param array<string,string> $otherAttributes
     */
    function submit_button(
        ?string $text = null,
        string $type = 'primary',
        string $name = 'submit',
        bool $wrap = true,
        array $otherAttributes = []
    ): void {
        echo get_submit_button($text, $type, $name, $wrap, $otherAttributes);
    }
}

if (!function_exists('checked')) {
    /**
     * @param mixed $checked
     * @param mixed $current
     */
    function checked($checked, $current = true, bool $echo = true): string
    {
        $result = ((string) $checked === (string) $current) ? 'checked="checked"' : '';
        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('selected')) {
    /**
     * @param mixed $selected
     * @param mixed $current
     */
    function selected($selected, $current = true, bool $echo = true): string
    {
        $result = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action): void
    {
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($action . '-nonce') . '" />';
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action): string
    {
        return add_query_arg(['_wpnonce' => $action . '-nonce'], $url);
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): bool
    {
        $GLOBALS['mwb_test_state']['nonce_checks'][] = $action;
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        unset($capability);
        return !empty($GLOBALS['mwb_test_state']['current_user_can']);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return !empty($GLOBALS['mwb_test_state']['is_admin']);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        unset($plugin);
        return 'https://example.test/wp-content/plugins/merchandillo-woocommerce-bridge/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    /**
     * @param mixed ...$args
     */
    function add_query_arg(...$args): string
    {
        if (empty($args)) {
            return '';
        }

        if (is_array($args[0])) {
            /** @var array<string,mixed> $queryArgs */
            $queryArgs = $args[0];
            $url = isset($args[1]) ? (string) $args[1] : '';
        } else {
            $queryArgs = [(string) $args[0] => isset($args[1]) ? $args[1] : ''];
            $url = isset($args[2]) ? (string) $args[2] : '';
        }

        if ('' === $url) {
            $url = 'https://example.test/';
        }

        $parts = parse_url($url);
        if (false === $parts) {
            return $url;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach ($queryArgs as $key => $value) {
            if (null === $value) {
                unset($query[$key]);
            } else {
                $query[$key] = (string) $value;
            }
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $queryString = http_build_query($query);

        return $scheme . $host . $port . $path . ('' === $queryString ? '' : '?' . $queryString) . $fragment;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $url): bool
    {
        $GLOBALS['mwb_test_state']['last_redirect'] = $url;
        return true;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $path): string
    {
        return rtrim($path, '/\\') . '/';
    }
}

if (!function_exists('size_format')) {
    function size_format(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return (string) $bytes . ' B';
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, int $timestamp): string
    {
        return date($format, $timestamp);
    }
}

if (!function_exists('wp_enqueue_style')) {
    /**
     * @param array<int,string> $deps
     * @param string|bool|null $ver
     */
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false): void
    {
        $GLOBALS['mwb_test_state']['enqueued_styles'][] = [
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
        ];
    }
}

if (!function_exists('wp_upload_dir')) {
    /**
     * @return array<string,string>
     */
    function wp_upload_dir(): array
    {
        return $GLOBALS['mwb_test_state']['upload_dir'];
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        if (!array_key_exists($name, $GLOBALS['mwb_test_state']['options'])) {
            return $default;
        }

        return $GLOBALS['mwb_test_state']['options'][$name];
    }
}

if (!function_exists('add_option')) {
    /**
     * @param mixed $value
     */
    function add_option(string $name, $value): bool
    {
        $GLOBALS['mwb_test_state']['options'][$name] = $value;
        return true;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $name, $value): bool
    {
        $GLOBALS['mwb_test_state']['options'][$name] = $value;
        return true;
    }
}

if (!function_exists('wp_parse_args')) {
    /**
     * @param mixed $args
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    function wp_parse_args($args, array $defaults = []): array
    {
        if (!is_array($args)) {
            $args = [];
        }

        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): void
    {
        $GLOBALS['mwb_test_state']['cleared_hooks'][] = $hook;
    }
}

if (!function_exists('wp_next_scheduled')) {
    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    function wp_next_scheduled(string $hook, array $args = [])
    {
        unset($hook, $args);
        return $GLOBALS['mwb_test_state']['next_scheduled'];
    }
}

if (!function_exists('wp_schedule_single_event')) {
    /**
     * @param array<int,mixed> $args
     */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['mwb_test_state']['scheduled_events'][] = [$timestamp, $hook, $args];
        return (bool) $GLOBALS['mwb_test_state']['schedule_single_event_result'];
    }
}

if (!function_exists('wc_get_log_file_path')) {
    function wc_get_log_file_path(string $source): string
    {
        unset($source);
        return (string) $GLOBALS['mwb_test_state']['wc_log_file_path'];
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger(): MWB_Test_Logger
    {
        return $GLOBALS['mwb_test_state']['logger'];
    }
}

if (!function_exists('wc_get_order')) {
    /**
     * @return mixed
     */
    function wc_get_order(int $orderId)
    {
        unset($orderId);
        return $GLOBALS['mwb_test_state']['wc_get_order_return'];
    }
}

if (!function_exists('wp_remote_post')) {
    /**
     * @param array<string,mixed> $args
     * @return mixed
     */
    function wp_remote_post(string $url, array $args)
    {
        $GLOBALS['mwb_test_state']['remote_post_requests'][] = [$url, $args];

        $response = $GLOBALS['mwb_test_state']['remote_post_response'];
        if (is_callable($response)) {
            return $response($url, $args);
        }

        return $response;
    }
}

if (!function_exists('is_wp_error')) {
    /**
     * @param mixed $value
     */
    function is_wp_error($value): bool
    {
        return $value instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    /**
     * @param mixed $response
     */
    function wp_remote_retrieve_response_code($response): int
    {
        if (!is_array($response)) {
            return 0;
        }

        return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    /**
     * @param mixed $response
     */
    function wp_remote_retrieve_body($response): string
    {
        if (!is_array($response)) {
            return '';
        }

        return isset($response['body']) ? (string) $response['body'] : '';
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $value
     */
    function wp_json_encode($value): string
    {
        $encoded = json_encode($value);
        return false === $encoded ? '' : $encoded;
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
    }
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/MerchandilloTestCase.php';
