<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Merchandillo_WooCommerce_Bridge
{
    private const OPTION_NAME = 'merchandillo_sync_options';
    private const CRON_HOOK = 'merchandillo_sync_order_event';
    private const LOG_SOURCE = 'merchandillo-woocommerce-bridge';
    private const SETTINGS_PAGE_SLUG = 'merchandillo-woocommerce-bridge';
    private const LOG_ACTION_KEY = 'merchandillo_logs_action';
    private const LOG_NONCE_ACTION = 'merchandillo_logs_action';
    private const MANUAL_PUSH_ACTION = 'merchandillo_push_order';
    private const MANUAL_PUSH_NONCE_ACTION = 'merchandillo_push_order';
    private const MANUAL_COMPARE_AJAX_ACTION = 'merchandillo_compare_order';
    private const MANUAL_PUSH_AJAX_ACTION = 'merchandillo_push_order_now';

    /** @var bool */
    private static $manualPushModalRendered = false;

    /** @var self|null */
    private static $instance = null;

    /** @var Merchandillo_Service_Locator|null */
    private $services = null;

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
            add_option(self::OPTION_NAME, Merchandillo_Settings::default_settings());
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'register_translations'], 5);
        add_action('plugins_loaded', [$this, 'bootstrap']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_log_actions']);
        add_action('admin_action_' . self::MANUAL_PUSH_ACTION, [$this, 'handle_manual_push_order']);
        add_action('wp_ajax_' . self::MANUAL_COMPARE_AJAX_ACTION, [$this, 'handle_manual_compare_ajax']);
        add_action('wp_ajax_' . self::MANUAL_PUSH_AJAX_ACTION, [$this, 'handle_manual_push_ajax']);
        add_action('admin_notices', [$this, 'render_manual_push_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
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
        add_action('woocommerce_order_item_add_action_buttons', [$this, 'render_order_push_button'], 20, 1);
    }

    public function register_translations(): void
    {
        $this->service_locator()->translation_manager()->register_hooks();
    }

    public function register_settings_page(): void
    {
        $this->service_locator()->admin_page()->register_settings_page();
    }

    public function register_settings(): void
    {
        $this->service_locator()->admin_page()->register_settings();
    }

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize_settings($input): array
    {
        return $this->service_locator()->settings()->sanitize($input);
    }

    public function handle_log_actions(): void
    {
        $this->service_locator()->admin_page()->handle_log_actions();
    }

    public function render_settings_page(): void
    {
        $this->service_locator()->admin_page()->render_settings_page();
    }

    public function enqueue_admin_assets(string $hookSuffix): void
    {
        $this->service_locator()->admin_page()->enqueue_admin_assets($hookSuffix);
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function add_settings_link(array $links): array
    {
        $url = $this->service_locator()->admin_page()->get_settings_page_url();
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'merchandillo-woocommerce-bridge') . '</a>';

        return $links;
    }

    public function handle_status_change(int $orderId, string $oldStatus, string $newStatus): void
    {
        $this->service_locator()->sync_service()->handle_status_change($orderId, $oldStatus, $newStatus);
    }

    public function queue_order_sync(int $orderId): void
    {
        $this->service_locator()->sync_service()->queue_order_sync($orderId);
    }

    public function sync_order_now(int $orderId): void
    {
        $this->service_locator()->sync_service()->sync_order_now($orderId);
    }

    /**
     * @param mixed $order
     */
    public function render_order_push_button($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }

        $nonce = function_exists('wp_create_nonce')
            ? wp_create_nonce(self::MANUAL_PUSH_NONCE_ACTION)
            : self::MANUAL_PUSH_NONCE_ACTION;
        $ajaxUrl = admin_url('admin-ajax.php');

        echo '<button type="button" class="button mwb-order-push-btn"';
        echo ' style="background:rgb(77, 121, 170);border-color:rgb(77, 121, 170);color:#fff;"';
        echo ' data-order-id="' . esc_attr((string) $order->get_id()) . '"';
        echo ' data-ajax-url="' . esc_url($ajaxUrl) . '"';
        echo ' data-nonce="' . esc_attr((string) $nonce) . '"';
        echo '>';
        echo esc_html__('Push to Merchandillo', 'merchandillo-woocommerce-bridge');
        echo '</button>';

        $this->render_manual_push_modal();
    }

    public function handle_manual_push_order(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer(self::MANUAL_PUSH_NONCE_ACTION);
        $orderId = isset($_GET['order_id']) ? absint((string) wp_unslash($_GET['order_id'])) : 0;
        $status = 'queued';

        if ($orderId <= 0 || !function_exists('wc_get_order') || !(wc_get_order($orderId) instanceof WC_Order)) {
            $status = 'invalid_order';
        } else {
            $settings = $this->service_locator()->settings()->get();
            if ('1' !== (string) $settings['enabled']) {
                $status = 'sync_disabled';
            } elseif (!$this->has_required_api_settings($settings)) {
                $status = 'missing_credentials';
            } elseif (false !== wp_next_scheduled(self::CRON_HOOK, [$orderId])) {
                $status = 'already_queued';
            } else {
                $this->queue_order_sync($orderId);
            }
        }

        $redirectUrl = add_query_arg(
            [
                'merchandillo_manual_push' => $status,
                'merchandillo_order_id' => (string) $orderId,
            ],
            $this->manual_push_redirect_url($orderId)
        );
        wp_safe_redirect($redirectUrl);
        return;
    }

    public function render_manual_push_notice(): void
    {
        $status = isset($_GET['merchandillo_manual_push'])
            ? sanitize_key((string) wp_unslash($_GET['merchandillo_manual_push']))
            : '';
        if ('' === $status) {
            return;
        }

        $orderId = isset($_GET['merchandillo_order_id']) ? absint((string) wp_unslash($_GET['merchandillo_order_id'])) : 0;
        $message = '';
        $noticeClass = 'notice-info';

        if ('queued' === $status) {
            $message = sprintf(
                __('Order #%d was queued for Merchandillo sync.', 'merchandillo-woocommerce-bridge'),
                $orderId
            );
            $noticeClass = 'notice-success';
        } elseif ('already_queued' === $status) {
            $message = sprintf(
                __('Order #%d is already queued for Merchandillo sync.', 'merchandillo-woocommerce-bridge'),
                $orderId
            );
            $noticeClass = 'notice-warning';
        } elseif ('sync_disabled' === $status) {
            $message = __('Manual push skipped because sync is disabled in plugin settings.', 'merchandillo-woocommerce-bridge');
            $noticeClass = 'notice-error';
        } elseif ('missing_credentials' === $status) {
            $message = __('Manual push skipped because API credentials are incomplete.', 'merchandillo-woocommerce-bridge');
            $noticeClass = 'notice-error';
        } elseif ('invalid_order' === $status) {
            $message = __('Manual push failed because the order could not be loaded.', 'merchandillo-woocommerce-bridge');
            $noticeClass = 'notice-error';
        }

        if ('' === $message) {
            return;
        }

        echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    public function handle_manual_compare_ajax(): void
    {
        if (!$this->can_manage_manual_push()) {
            $this->send_json_response(false, ['message' => __('You are not allowed to perform this action.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        if (!$this->is_manual_push_nonce_valid()) {
            $this->send_json_response(false, ['message' => __('Security check failed. Please refresh the page and try again.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        $orderId = isset($_POST['order_id']) ? absint((string) wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0) {
            $this->send_json_response(false, ['message' => __('Missing order id.', 'merchandillo-woocommerce-bridge')], 400);
            return;
        }

        $context = $this->build_manual_push_context($orderId);
        if (!$context['ok']) {
            $this->send_json_response(false, ['message' => $context['message']], 400);
            return;
        }

        $remoteResult = $this->find_remote_order_for_manual_compare($context['settings'], $context['payload']);
        if (!$remoteResult['ok']) {
            $this->send_json_response(false, ['message' => $remoteResult['message']], 400);
            return;
        }

        if (null === $remoteResult['order']) {
            $this->send_json_response(true, [
                'state' => 'not_found',
                'message' => __('This order does not exist in Merchandillo yet. You can push it now.', 'merchandillo-woocommerce-bridge'),
                'remote_response' => $remoteResult['remote_response'],
            ]);
            return;
        }

        $differences = $this->calculate_payload_differences($context['payload'], (array) $remoteResult['order']);
        if (empty($differences)) {
            $this->send_json_response(true, [
                'state' => 'identical',
                'message' => __('Order already exists in Merchandillo and no differences were detected.', 'merchandillo-woocommerce-bridge'),
                'differences' => [],
                'remote_response' => $remoteResult['remote_response'],
            ]);
            return;
        }

        $this->send_json_response(true, [
            'state' => 'different',
            'message' => __('Order exists in Merchandillo and differences were found. Review them before overwriting.', 'merchandillo-woocommerce-bridge'),
            'differences' => $differences,
            'remote_response' => $remoteResult['remote_response'],
        ]);
    }

    public function handle_manual_push_ajax(): void
    {
        if (!$this->can_manage_manual_push()) {
            $this->send_json_response(false, ['message' => __('You are not allowed to perform this action.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        if (!$this->is_manual_push_nonce_valid()) {
            $this->send_json_response(false, ['message' => __('Security check failed. Please refresh the page and try again.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        $orderId = isset($_POST['order_id']) ? absint((string) wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0) {
            $this->send_json_response(false, ['message' => __('Missing order id.', 'merchandillo-woocommerce-bridge')], 400);
            return;
        }

        $result = $this->push_order_to_merchandillo_now($orderId);
        if (!$result['ok']) {
            $this->send_json_response(false, ['message' => $result['message']], 400);
            return;
        }

        $this->send_json_response(true, ['message' => sprintf(__('Order #%d was pushed to Merchandillo.', 'merchandillo-woocommerce-bridge'), $orderId)]);
    }

    private function service_locator(): Merchandillo_Service_Locator
    {
        if (null === $this->services) {
            $this->services = new Merchandillo_Service_Locator(
                self::OPTION_NAME,
                self::CRON_HOOK,
                self::LOG_SOURCE,
                self::SETTINGS_PAGE_SLUG,
                self::LOG_ACTION_KEY,
                self::LOG_NONCE_ACTION
            );
        }

        return $this->services;
    }

    /**
     * @return array{ok:bool,message:string,settings:array<string,string>,payload:array<string,mixed>,order:WC_Order|null}
     */
    private function build_manual_push_context(int $orderId): array
    {
        if (!function_exists('wc_get_order')) {
            return [
                'ok' => false,
                'message' => __('WooCommerce order API is unavailable.', 'merchandillo-woocommerce-bridge'),
                'settings' => [],
                'payload' => [],
                'order' => null,
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return [
                'ok' => false,
                'message' => __('Order could not be loaded.', 'merchandillo-woocommerce-bridge'),
                'settings' => [],
                'payload' => [],
                'order' => null,
            ];
        }

        $settings = $this->service_locator()->settings()->get();
        if ('1' !== (string) $settings['enabled']) {
            return [
                'ok' => false,
                'message' => __('Sync is disabled in plugin settings.', 'merchandillo-woocommerce-bridge'),
                'settings' => [],
                'payload' => [],
                'order' => $order,
            ];
        }

        if (!$this->has_required_api_settings($settings)) {
            return [
                'ok' => false,
                'message' => __('API credentials are incomplete.', 'merchandillo-woocommerce-bridge'),
                'settings' => [],
                'payload' => [],
                'order' => $order,
            ];
        }

        $payload = $this->service_locator()->payload_builder()->build($order);

        return [
            'ok' => true,
            'message' => '',
            'settings' => $settings,
            'payload' => $payload,
            'order' => $order,
        ];
    }

    /**
     * @param array<string,string> $settings
     * @param array<string,mixed> $payload
     * @return array{ok:bool,order:array<string,mixed>|null,message:string,remote_response:array<string,mixed>|null}
     */
    private function find_remote_order_for_manual_compare(array $settings, array $payload): array
    {
        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';
        $page = 1;
        $limit = 50;
        $maxPages = 10;
        $targetId = isset($payload['id']) ? (int) $payload['id'] : 0;
        $targetOrderNumber = isset($payload['order_number']) ? (string) $payload['order_number'] : '';
        $lastResponse = null;

        while ($page <= $maxPages) {
            $requestEndpoint = add_query_arg(
                [
                    'page' => (string) $page,
                    'limit' => (string) $limit,
                ],
                $endpoint
            );
            $response = wp_remote_get(
                $requestEndpoint,
                [
                    'timeout' => 20,
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-API-Key' => (string) $settings['api_key'],
                        'X-API-Secret' => (string) $settings['api_secret'],
                    ],
                ]
            );

            if (is_wp_error($response)) {
                return ['ok' => false, 'order' => null, 'message' => __('Could not read orders from Merchandillo.', 'merchandillo-woocommerce-bridge') . ' ' . $response->get_error_message(), 'remote_response' => null];
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300) {
                return ['ok' => false, 'order' => null, 'message' => sprintf(__('Could not read orders from Merchandillo (HTTP %d).', 'merchandillo-woocommerce-bridge'), $statusCode), 'remote_response' => null];
            }

            $body = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'order' => null, 'message' => __('Merchandillo returned an invalid response while reading orders.', 'merchandillo-woocommerce-bridge'), 'remote_response' => null];
            }
            $lastResponse = $decoded;

            $orders = isset($decoded['orders']) && is_array($decoded['orders']) ? $decoded['orders'] : [];
            foreach ($orders as $remoteOrder) {
                if (!is_array($remoteOrder)) {
                    continue;
                }

                $remoteId = isset($remoteOrder['id']) ? (int) $remoteOrder['id'] : 0;
                $remoteOrderNumber = isset($remoteOrder['order_number']) ? (string) $remoteOrder['order_number'] : '';
                if (($targetId > 0 && $remoteId === $targetId) || ('' !== $targetOrderNumber && $remoteOrderNumber === $targetOrderNumber)) {
                    return ['ok' => true, 'order' => $remoteOrder, 'message' => '', 'remote_response' => $decoded];
                }
            }

            $totalPages = isset($decoded['totalPages']) ? (int) $decoded['totalPages'] : 0;
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }
            if (count($orders) < $limit) {
                break;
            }

            $page++;
        }

        return ['ok' => true, 'order' => null, 'message' => '', 'remote_response' => $lastResponse];
    }

    /**
     * @return array<int,array{field:string,local:string,remote:string}>
     */
    private function calculate_payload_differences(array $localPayload, array $remoteOrder): array
    {
        $comparison = $this->build_comparable_payloads($localPayload, $remoteOrder);
        $differences = [];
        $this->collect_differences($comparison['local'], $comparison['remote'], '', $differences, 150);

        return $differences;
    }

    /**
     * @param array<string,mixed> $localPayload
     * @param array<string,mixed> $remoteOrder
     * @return array{local:array<string,mixed>,remote:array<string,mixed>}
     */
    private function build_comparable_payloads(array $localPayload, array $remoteOrder): array
    {
        $localComparable = [];
        $remoteComparable = [];

        $comparableKeys = [
            'id',
            'order_number',
            'customer_name',
            'customer_email',
            'customer_phone',
            'status',
            'subtotal',
            'tax_amount',
            'shipping_amount',
            'discount_amount',
            'total_amount',
            'currency',
            'shipping_address',
            'billing_address',
            'payment_method',
            'payment_status',
            'shipping_method',
            'tracking_number',
            'notes',
            'order_date',
        ];

        foreach ($comparableKeys as $key) {
            if (!array_key_exists($key, $localPayload)) {
                continue;
            }

            if ('id' === $key) {
                $remoteId = $remoteOrder['id'] ?? $remoteOrder['external_order_id'] ?? null;
                if (null === $remoteId && isset($remoteOrder['order_number']) && is_numeric((string) $remoteOrder['order_number'])) {
                    $remoteId = $remoteOrder['order_number'];
                }
                if (null === $remoteId || '' === (string) $remoteId) {
                    continue;
                }
                $localComparable[$key] = $localPayload[$key];
                $remoteComparable[$key] = $remoteId;
                continue;
            }

            if ('shipping_address' === $key || 'billing_address' === $key) {
                $decodedAddress = $this->decode_json_object($remoteOrder[$key] ?? null);
                if (null === $decodedAddress) {
                    continue;
                }
                $localComparable[$key] = $localPayload[$key];
                $remoteComparable[$key] = $decodedAddress;
                continue;
            }

            if ('order_date' === $key) {
                $remoteDate = isset($remoteOrder['order_date']) ? (string) $remoteOrder['order_date'] : '';
                if ('' === $remoteDate) {
                    continue;
                }
                $localComparable[$key] = $localPayload[$key];
                $remoteComparable[$key] = substr($remoteDate, 0, 10);
                continue;
            }

            if (array_key_exists($key, $remoteOrder)) {
                $localComparable[$key] = $localPayload[$key];
                $remoteComparable[$key] = $remoteOrder[$key];
            }
        }

        if (array_key_exists('items', $localPayload) && array_key_exists('items', $remoteOrder) && is_array($remoteOrder['items'])) {
            $localComparable['items'] = $localPayload['items'];
            $remoteComparable['items'] = $remoteOrder['items'];
        }

        $remoteNormalized = $this->normalize_remote_for_compare($remoteComparable, $localComparable);

        return [
            'local' => $localComparable,
            'remote' => $remoteNormalized,
        ];
    }

    /**
     * @param mixed $local
     * @param mixed $remote
     * @param array<int,array{field:string,local:string,remote:string}> $differences
     */
    private function collect_differences($local, $remote, string $path, array &$differences, int $maxItems): void
    {
        if (count($differences) >= $maxItems) {
            return;
        }

        if (is_array($local)) {
            $keys = array_keys($local);
            foreach ($keys as $key) {
                $nextPath = '' === $path ? (string) $key : $path . '.' . (string) $key;
                $localValue = $local[$key];
                $remoteValue = is_array($remote) && array_key_exists($key, $remote) ? $remote[$key] : null;
                $this->collect_differences($localValue, $remoteValue, $nextPath, $differences, $maxItems);
            }

            return;
        }

        if ($this->normalize_compare_value($local) === $this->normalize_compare_value($remote)) {
            return;
        }

        $differences[] = [
            'field' => '' === $path ? 'root' : $path,
            'local' => $this->encode_compare_value($local),
            'remote' => $this->encode_compare_value($remote),
        ];
    }

    /**
     * @param array<string,mixed> $remote
     * @param array<string,mixed> $shape
     * @return array<string,mixed>
     */
    private function normalize_remote_for_compare(array $remote, array $shape): array
    {
        $normalized = [];
        foreach ($shape as $key => $shapeValue) {
            $remoteValue = array_key_exists($key, $remote) ? $remote[$key] : null;

            if (is_array($shapeValue)) {
                if ($this->is_list_array($shapeValue)) {
                    $normalized[$key] = [];
                    $remoteList = is_array($remoteValue) ? $remoteValue : [];
                    foreach ($shapeValue as $index => $listItemShape) {
                        $remoteItem = is_array($remoteList) && array_key_exists($index, $remoteList) ? $remoteList[$index] : null;
                        if (is_array($listItemShape)) {
                            $normalized[$key][$index] = $this->normalize_remote_for_compare(is_array($remoteItem) ? $remoteItem : [], $listItemShape);
                        } else {
                            $normalized[$key][$index] = $remoteItem;
                        }
                    }
                } else {
                    $normalized[$key] = $this->normalize_remote_for_compare(is_array($remoteValue) ? $remoteValue : [], $shapeValue);
                }
            } else {
                $normalized[$key] = $remoteValue;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalize_compare_value($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize_compare_value($item);
            }

            return $normalized;
        }

        if (is_numeric($value)) {
            $formatted = number_format((float) $value, 6, '.', '');
            return rtrim(rtrim($formatted, '0'), '.');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>|null
     */
    private function decode_json_object($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     */
    private function encode_compare_value($value): string
    {
        if (is_array($value)) {
            $encoded = wp_json_encode($value);
            return false === $encoded ? '' : (string) $encoded;
        }

        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    private function is_list_array(array $value): bool
    {
        if ([] === $value) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function push_order_to_merchandillo_now(int $orderId): array
    {
        $context = $this->build_manual_push_context($orderId);
        if (!$context['ok']) {
            return ['ok' => false, 'message' => $context['message']];
        }

        $settings = $context['settings'];
        $payload = $context['payload'];
        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';
        $response = wp_remote_post(
            $endpoint,
            [
                'method' => 'POST',
                'timeout' => 20,
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
            return [
                'ok' => false,
                'message' => __('Could not push order to Merchandillo.', 'merchandillo-woocommerce-bridge') . ' ' . $response->get_error_message(),
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode >= 200 && $statusCode < 300) {
            return ['ok' => true, 'message' => ''];
        }

        $body = trim((string) wp_remote_retrieve_body($response));
        $bodyExcerpt = '' === $body ? '' : ' ' . substr($body, 0, 350);

        return [
            'ok' => false,
            'message' => sprintf(__('Merchandillo rejected the push (HTTP %d).', 'merchandillo-woocommerce-bridge'), $statusCode) . $bodyExcerpt,
        ];
    }

    private function render_manual_push_modal(): void
    {
        if (self::$manualPushModalRendered) {
            return;
        }
        self::$manualPushModalRendered = true;

        $i18n = [
            'pushOrder' => __('Push Order', 'merchandillo-woocommerce-bridge'),
            'overwriteInMerchandillo' => __('Overwrite in Merchandillo', 'merchandillo-woocommerce-bridge'),
            'checkingOrder' => __('Checking order in Merchandillo...', 'merchandillo-woocommerce-bridge'),
            'requestFailed' => __('Request failed.', 'merchandillo-woocommerce-bridge'),
            'unexpectedResponse' => __('Unexpected response.', 'merchandillo-woocommerce-bridge'),
            'sendingOrder' => __('Sending order...', 'merchandillo-woocommerce-bridge'),
            'pushFailed' => __('Push failed.', 'merchandillo-woocommerce-bridge'),
            'orderPushed' => __('Order pushed.', 'merchandillo-woocommerce-bridge'),
            'successLabel' => __('Success:', 'merchandillo-woocommerce-bridge'),
            'errorLabel' => __('Error:', 'merchandillo-woocommerce-bridge'),
            'fieldLabel' => __('Field', 'merchandillo-woocommerce-bridge'),
            'merchandilloLabel' => __('Merchandillo', 'merchandillo-woocommerce-bridge'),
            'woocommerceLabel' => __('WooCommerce', 'merchandillo-woocommerce-bridge'),
            'jsonResponseLabel' => __('View JSON response', 'merchandillo-woocommerce-bridge'),
        ];

        $textAlign = function_exists('is_rtl') && is_rtl() ? 'right' : 'left';

        echo '<div id="mwb-order-push-modal" class="mwb-order-push-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.45);text-align:' . esc_attr($textAlign) . ';">';
        echo '<div style="max-width:760px;width:92%;margin:6vh auto;background:#fff;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.25);overflow:hidden;text-align:' . esc_attr($textAlign) . ';">';
        echo '<div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:10px;">';
        echo '<h2 style="margin:0;font-size:18px;line-height:1.3;">' . esc_html__('Push Order to Merchandillo', 'merchandillo-woocommerce-bridge') . '</h2>';
        echo '<button type="button" id="mwb-order-push-close" class="button" style="min-width:32px;padding:0 10px;">&times;</button>';
        echo '</div>';
        echo '<div id="mwb-order-push-content" style="padding:16px 18px;max-height:58vh;overflow:auto;color:#0f172a;"></div>';
        echo '<div style="padding:14px 18px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;">';
        echo '<button type="button" id="mwb-order-push-cancel" class="button">' . esc_html__('Cancel', 'merchandillo-woocommerce-bridge') . '</button>';
        echo '<button type="button" id="mwb-order-push-confirm" class="button button-primary" style="display:none;background:rgb(77, 121, 170);border-color:rgb(77, 121, 170);">' . esc_html__('Push Order', 'merchandillo-woocommerce-bridge') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<script>(function(){';
        echo 'var t=' . wp_json_encode($i18n) . ';';
        echo 'var modal=document.getElementById("mwb-order-push-modal");if(!modal){return;}';
        echo 'var content=document.getElementById("mwb-order-push-content");var confirmBtn=document.getElementById("mwb-order-push-confirm");var closeBtn=document.getElementById("mwb-order-push-close");var cancelBtn=document.getElementById("mwb-order-push-cancel");';
        echo 'var state={orderId:0,ajaxUrl:"",nonce:""};';
        echo 'function esc(s){return String(s===undefined?"":s).replace(/[&<>]/g,function(c){return({"&":"&amp;","<":"&lt;",">":"&gt;"})[c];});}';
        echo 'function open(){modal.style.display="block";document.body.style.overflow="hidden";}';
        echo 'function close(){modal.style.display="none";document.body.style.overflow="";confirmBtn.style.display="none";confirmBtn.textContent=t.pushOrder;confirmBtn.disabled=false;}';
        echo 'function post(action,data){var body=new URLSearchParams(data);body.append("action",action);return fetch(state.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString(),credentials:"same-origin"}).then(function(r){return r.json();});}';
        echo 'function renderRemoteResponse(remote){if(!remote){return "";}var raw="";try{raw=JSON.stringify(remote,null,2);}catch(e){raw=String(remote);}return "<div style=\'margin:10px 0 12px;\'><button type=\'button\' class=\'button mwb-json-toggle\' data-target=\'mwb-remote-json-response\' title=\'"+esc(t.jsonResponseLabel)+"\' aria-label=\'"+esc(t.jsonResponseLabel)+"\'><span class=\'dashicons dashicons-media-code\'></span></button><pre id=\'mwb-remote-json-response\' style=\'display:none;margin-top:8px;max-height:260px;overflow:auto;border:1px solid #e2e8f0;background:#f8fafc;padding:10px;border-radius:8px;\'><code>"+esc(raw)+"</code></pre></div>";}';
        echo 'function renderDiffs(diffs){if(!diffs||!diffs.length){return "";}var rows=diffs.map(function(d){return "<tr><td style=\'padding:6px;border:1px solid #e2e8f0;white-space:nowrap;\'><code>"+esc(d.field)+"</code></td><td style=\'padding:6px;border:1px solid #e2e8f0;\'><code>"+esc(d.remote)+"</code></td><td style=\'padding:6px;border:1px solid #e2e8f0;\'><code>"+esc(d.local)+"</code></td></tr>";}).join("");return "<div style=\'overflow:auto;max-height:320px;\'><table style=\'width:100%;border-collapse:collapse;font-size:12px;\'><thead><tr><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.fieldLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.merchandilloLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.woocommerceLabel)+"</th></tr></thead><tbody>"+rows+"</tbody></table></div>";}';
        echo 'function compare(){content.innerHTML="<p>"+esc(t.checkingOrder)+"</p>";confirmBtn.style.display="none";post("' . esc_js(self::MANUAL_COMPARE_AJAX_ACTION) . '",{nonce:state.nonce,order_id:String(state.orderId)}).then(function(res){if(!res||!res.success){throw new Error((res&&res.data&&res.data.message)?res.data.message:t.requestFailed);}var d=res.data||{};var jsonView=renderRemoteResponse(d.remote_response||null);if(d.state==="not_found"){content.innerHTML="<p>"+esc(d.message)+"</p>"+jsonView;confirmBtn.textContent=t.pushOrder;confirmBtn.style.display="inline-block";return;}if(d.state==="identical"){content.innerHTML="<p>"+esc(d.message)+"</p>"+jsonView;return;}if(d.state==="different"){content.innerHTML="<p>"+esc(d.message)+"</p>"+jsonView+renderDiffs(d.differences||[]);confirmBtn.textContent=t.overwriteInMerchandillo;confirmBtn.style.display="inline-block";return;}content.innerHTML="<p>"+esc(t.unexpectedResponse)+"</p>";}).catch(function(err){content.innerHTML="<div style=\'padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:8px;\'><strong>"+esc(t.errorLabel)+"</strong> "+esc(err.message)+"</div>";});}';
        echo 'function pushNow(){confirmBtn.disabled=true;content.innerHTML+="<p>"+esc(t.sendingOrder)+"</p>";post("' . esc_js(self::MANUAL_PUSH_AJAX_ACTION) . '",{nonce:state.nonce,order_id:String(state.orderId)}).then(function(res){confirmBtn.disabled=false;if(!res||!res.success){throw new Error((res&&res.data&&res.data.message)?res.data.message:t.pushFailed);}content.innerHTML="<div style=\'padding:10px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:8px;\'><strong>"+esc(t.successLabel)+"</strong> "+esc((res.data&&res.data.message)?res.data.message:t.orderPushed)+"</div>";confirmBtn.style.display="none";}).catch(function(err){confirmBtn.disabled=false;content.innerHTML="<div style=\'padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:8px;\'><strong>"+esc(t.errorLabel)+"</strong> "+esc(err.message)+"</div>";});}';
        echo 'document.addEventListener("click",function(e){var btn=e.target.closest(".mwb-order-push-btn");if(!btn){return;}e.preventDefault();state.orderId=parseInt(btn.getAttribute("data-order-id")||"0",10);state.ajaxUrl=btn.getAttribute("data-ajax-url")||"";state.nonce=btn.getAttribute("data-nonce")||"";if(!state.orderId||!state.ajaxUrl){return;}open();compare();});';
        echo 'content.addEventListener("click",function(e){var btn=e.target.closest(".mwb-json-toggle");if(!btn){return;}e.preventDefault();var targetId=btn.getAttribute("data-target")||"";if(!targetId){return;}var target=document.getElementById(targetId);if(!target){return;}target.style.display=(target.style.display==="block")?"none":"block";});';
        echo 'confirmBtn.addEventListener("click",function(e){e.preventDefault();pushNow();});closeBtn.addEventListener("click",function(e){e.preventDefault();close();});cancelBtn.addEventListener("click",function(e){e.preventDefault();close();});modal.addEventListener("click",function(e){if(e.target===modal){close();}});';
        echo '})();</script>';
    }

    private function can_manage_manual_push(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function is_manual_push_nonce_valid(): bool
    {
        $nonce = isset($_REQUEST['nonce']) ? (string) wp_unslash($_REQUEST['nonce']) : '';
        if ('' === $nonce) {
            return false;
        }

        if (function_exists('wp_verify_nonce')) {
            return false !== wp_verify_nonce($nonce, self::MANUAL_PUSH_NONCE_ACTION);
        }

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function send_json_response(bool $success, array $payload, int $statusCode = 200): void
    {
        if ($success) {
            if (function_exists('wp_send_json_success')) {
                wp_send_json_success($payload, $statusCode);
                return;
            }
        } else {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error($payload, $statusCode);
                return;
            }
        }

        if (function_exists('status_header')) {
            status_header($statusCode);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(
            [
                'success' => $success,
                'data' => $payload,
            ]
        );
        if (function_exists('wp_die')) {
            wp_die();
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

    private function manual_push_redirect_url(int $orderId): string
    {
        if (function_exists('wp_get_referer')) {
            $referer = wp_get_referer();
            if (is_string($referer) && '' !== $referer) {
                return $referer;
            }
        }

        if ($orderId > 0) {
            return add_query_arg(
                [
                    'post' => (string) $orderId,
                    'action' => 'edit',
                ],
                admin_url('post.php')
            );
        }

        return admin_url('edit.php?post_type=shop_order');
    }
}
