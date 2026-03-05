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
    private const BULK_PUSH_ACTION = 'merchandillo_bulk_push_orders';
    private const BULK_COMPARE_AJAX_ACTION = 'merchandillo_bulk_compare_orders';
    private const BULK_PUSH_AJAX_ACTION = 'merchandillo_bulk_push_orders_now';
    private const BULK_MAX_ORDER_SELECTION = 50;

    /** @var bool */
    private static $manualPushModalRendered = false;

    /** @var bool */
    private static $bulkPushModalRendered = false;

    /** @var self|null */
    private static $instance = null;

    /** @var Merchandillo_Service_Locator|null */
    private $services = null;

    /** @var Merchandillo_Github_Updater|null */
    private $githubUpdater = null;

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
        add_action('admin_init', [$this, 'maybe_handle_bulk_order_push_fallback']);
        add_action('admin_action_' . self::MANUAL_PUSH_ACTION, [$this, 'handle_manual_push_order']);
        add_action('wp_ajax_' . self::MANUAL_COMPARE_AJAX_ACTION, [$this, 'handle_manual_compare_ajax']);
        add_action('wp_ajax_' . self::MANUAL_PUSH_AJAX_ACTION, [$this, 'handle_manual_push_ajax']);
        add_action('wp_ajax_' . self::BULK_COMPARE_AJAX_ACTION, [$this, 'handle_bulk_compare_ajax']);
        add_action('wp_ajax_' . self::BULK_PUSH_AJAX_ACTION, [$this, 'handle_bulk_push_ajax']);
        add_action('admin_notices', [$this, 'render_manual_push_notice']);
        add_action('admin_notices', [$this, 'render_bulk_push_launcher_notice']);
        add_action('admin_footer', [$this, 'render_bulk_push_launcher_footer']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action(self::CRON_HOOK, [$this, 'sync_order_now'], 10, 1);
        add_filter('plugin_action_links_' . plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), [$this, 'add_settings_link']);

        if ($this->should_register_updater_hooks()) {
            $this->github_updater()->register_hooks();
        }
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
        add_filter('bulk_actions-edit-shop_order', [$this, 'register_bulk_order_push_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_order_push_action'], 10, 3);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'register_bulk_order_push_action']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_order_push_action'], 10, 3);
        add_filter('bulk_actions-admin_page_wc-orders', [$this, 'register_bulk_order_push_action']);
        add_filter('handle_bulk_actions-admin_page_wc-orders', [$this, 'handle_bulk_order_push_action'], 10, 3);
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

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function register_bulk_order_push_action(array $actions): array
    {
        $actions[self::BULK_PUSH_ACTION] = __('Send to Merchandillo', 'merchandillo-woocommerce-bridge');

        return $actions;
    }

    /**
     * @param array<int,mixed> $orderIds
     */
    public function handle_bulk_order_push_action(string $redirectTo, string $action, array $orderIds): string
    {
        if (self::BULK_PUSH_ACTION !== $action) {
            return $redirectTo;
        }

        if (!$this->can_manage_manual_push()) {
            return add_query_arg(
                [
                    'merchandillo_bulk_status' => 'forbidden',
                ],
                $redirectTo
            );
        }

        $allOrderIds = $this->parse_bulk_order_ids_from_request($orderIds, 5000);
        $selectedOrderIds = array_slice($allOrderIds, 0, self::BULK_MAX_ORDER_SELECTION);
        if (empty($selectedOrderIds)) {
            return add_query_arg(
                [
                    'merchandillo_bulk_status' => 'empty_selection',
                ],
                $redirectTo
            );
        }

        $nonce = function_exists('wp_create_nonce')
            ? wp_create_nonce(self::MANUAL_PUSH_NONCE_ACTION)
            : self::MANUAL_PUSH_NONCE_ACTION;

        return add_query_arg(
            [
                'merchandillo_bulk_push' => '1',
                'merchandillo_bulk_ids' => implode(',', $selectedOrderIds),
                'merchandillo_bulk_nonce' => (string) $nonce,
                'merchandillo_bulk_truncated' => count($allOrderIds) > count($selectedOrderIds) ? '1' : '0',
            ],
            $redirectTo
        );
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

    public function render_bulk_push_launcher_notice(): void
    {
        $status = isset($_GET['merchandillo_bulk_status'])
            ? sanitize_key((string) wp_unslash($_GET['merchandillo_bulk_status']))
            : '';

        if ('empty_selection' === $status) {
            $this->render_admin_notice(
                __('Bulk push skipped because no valid orders were selected.', 'merchandillo-woocommerce-bridge'),
                'notice-error'
            );
        } elseif ('forbidden' === $status) {
            $this->render_admin_notice(
                __('You are not allowed to perform this action.', 'merchandillo-woocommerce-bridge'),
                'notice-error'
            );
        }

        $shouldLaunch = isset($_GET['merchandillo_bulk_push'])
            && '1' === (string) wp_unslash($_GET['merchandillo_bulk_push']);
        if (!$shouldLaunch) {
            return;
        }

        $launch = $this->bulk_push_launch_context_from_query();
        if (!$launch['ok']) {
            $this->render_admin_notice(
                __('Security check failed. Please refresh the page and try again.', 'merchandillo-woocommerce-bridge'),
                'notice-error'
            );
            return;
        }

        if (isset($_GET['merchandillo_bulk_truncated']) && '1' === (string) wp_unslash($_GET['merchandillo_bulk_truncated'])) {
            $this->render_admin_notice(
                sprintf(__('Only the first %d selected orders will be processed in one run.', 'merchandillo-woocommerce-bridge'), self::BULK_MAX_ORDER_SELECTION),
                'notice-warning'
            );
        }

        $this->render_admin_notice(
            sprintf(__('Bulk push started for %d selected order(s).', 'merchandillo-woocommerce-bridge'), count($launch['order_ids'])),
            'notice-info'
        );

        $this->render_bulk_push_modal($launch['order_ids'], $launch['nonce']);
    }

    public function render_bulk_push_launcher_footer(): void
    {
        $launch = $this->bulk_push_launch_context_from_query();
        if (!$launch['ok']) {
            return;
        }

        $this->render_bulk_push_modal($launch['order_ids'], $launch['nonce']);
    }

    public function handle_bulk_compare_ajax(): void
    {
        if (!$this->can_manage_manual_push()) {
            $this->send_json_response(false, ['message' => __('You are not allowed to perform this action.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        if (!$this->is_manual_push_nonce_valid()) {
            $this->send_json_response(false, ['message' => __('Security check failed. Please refresh the page and try again.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        $rawOrderIds = isset($_POST['order_ids']) ? wp_unslash($_POST['order_ids']) : [];
        $orderIds = $this->parse_bulk_order_ids_from_request($rawOrderIds, self::BULK_MAX_ORDER_SELECTION);
        if (empty($orderIds)) {
            $this->send_json_response(false, ['message' => __('No valid order ids were provided.', 'merchandillo-woocommerce-bridge')], 400);
            return;
        }

        $compareResult = $this->build_bulk_compare_result($orderIds);
        if (!$compareResult['ok']) {
            $this->send_json_response(false, ['message' => $compareResult['message']], 400);
            return;
        }

        $this->send_json_response(true, [
            'message' => $compareResult['message'],
            'summary' => $compareResult['summary'],
            'orders' => $compareResult['orders'],
            'not_found_order_ids' => $compareResult['not_found_order_ids'],
            'different_order_ids' => $compareResult['different_order_ids'],
            'identical_order_ids' => $compareResult['identical_order_ids'],
        ]);
    }

    public function handle_bulk_push_ajax(): void
    {
        if (!$this->can_manage_manual_push()) {
            $this->send_json_response(false, ['message' => __('You are not allowed to perform this action.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        if (!$this->is_manual_push_nonce_valid()) {
            $this->send_json_response(false, ['message' => __('Security check failed. Please refresh the page and try again.', 'merchandillo-woocommerce-bridge')], 403);
            return;
        }

        $rawOrderIds = isset($_POST['order_ids']) ? wp_unslash($_POST['order_ids']) : [];
        $orderIds = $this->parse_bulk_order_ids_from_request($rawOrderIds, self::BULK_MAX_ORDER_SELECTION);
        if (empty($orderIds)) {
            $this->send_json_response(false, ['message' => __('No valid order ids were provided.', 'merchandillo-woocommerce-bridge')], 400);
            return;
        }

        $results = [];
        $pushed = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            $pushResult = $this->push_order_to_merchandillo_now($orderId);
            if ($pushResult['ok']) {
                $pushed++;
                $results[] = [
                    'order_id' => $orderId,
                    'ok' => true,
                    'message' => sprintf(__('Order #%d was pushed to Merchandillo.', 'merchandillo-woocommerce-bridge'), $orderId),
                ];
                continue;
            }

            $failed++;
            $results[] = [
                'order_id' => $orderId,
                'ok' => false,
                'message' => $pushResult['message'],
            ];
        }

        $payload = [
            'message' => sprintf(__('Bulk push finished. %1$d pushed, %2$d failed.', 'merchandillo-woocommerce-bridge'), $pushed, $failed),
            'summary' => [
                'requested' => count($orderIds),
                'pushed' => $pushed,
                'failed' => $failed,
            ],
            'results' => $results,
        ];

        if (0 === $pushed) {
            $this->send_json_response(false, $payload, 400);
            return;
        }

        $this->send_json_response(true, $payload);
    }

    public function maybe_handle_bulk_order_push_fallback(): void
    {
        if (!$this->can_manage_manual_push()) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
        if ('' === $action || '-1' === $action) {
            $action = isset($_REQUEST['action2']) ? sanitize_key((string) wp_unslash($_REQUEST['action2'])) : '';
        }

        if (self::BULK_PUSH_ACTION !== $action) {
            return;
        }

        $allOrderIds = $this->parse_bulk_order_ids_from_request(
            $_REQUEST['post'] ?? $_REQUEST['id'] ?? $_REQUEST['order_id'] ?? [],
            5000
        );
        $selectedOrderIds = array_slice($allOrderIds, 0, self::BULK_MAX_ORDER_SELECTION);

        $redirectTo = $this->bulk_push_redirect_url();
        if (empty($selectedOrderIds)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'merchandillo_bulk_status' => 'empty_selection',
                    ],
                    $redirectTo
                )
            );
            return;
        }

        $nonce = function_exists('wp_create_nonce')
            ? wp_create_nonce(self::MANUAL_PUSH_NONCE_ACTION)
            : self::MANUAL_PUSH_NONCE_ACTION;
        wp_safe_redirect(
            add_query_arg(
                [
                    'merchandillo_bulk_push' => '1',
                    'merchandillo_bulk_ids' => implode(',', $selectedOrderIds),
                    'merchandillo_bulk_nonce' => (string) $nonce,
                    'merchandillo_bulk_truncated' => count($allOrderIds) > count($selectedOrderIds) ? '1' : '0',
                ],
                $redirectTo
            )
        );
    }

    /**
     * @param array<int,int> $orderIds
     */
    private function render_bulk_push_modal(array $orderIds, string $nonce): void
    {
        if (self::$bulkPushModalRendered) {
            return;
        }
        self::$bulkPushModalRendered = true;

        $i18n = [
            'title' => __('Bulk Push Orders to Merchandillo', 'merchandillo-woocommerce-bridge'),
            'cancel' => __('Cancel', 'merchandillo-woocommerce-bridge'),
            'checkingOrders' => __('Checking selected orders in Merchandillo...', 'merchandillo-woocommerce-bridge'),
            'requestFailed' => __('Request failed.', 'merchandillo-woocommerce-bridge'),
            'unexpectedResponse' => __('Unexpected response.', 'merchandillo-woocommerce-bridge'),
            'summaryTitle' => __('Comparison summary', 'merchandillo-woocommerce-bridge'),
            'autoPushingMissing' => __('Sending orders that do not exist in Merchandillo yet...', 'merchandillo-woocommerce-bridge'),
            'differencesRequireAction' => __('Some orders have differences. Review and confirm overwrite.', 'merchandillo-woocommerce-bridge'),
            'noDifferencesDone' => __('No differing orders found. Bulk run finished.', 'merchandillo-woocommerce-bridge'),
            'overwriteDiffering' => __('Overwrite differing orders in Merchandillo', 'merchandillo-woocommerce-bridge'),
            'overwriteInProgress' => __('Overwriting differing orders...', 'merchandillo-woocommerce-bridge'),
            'autoPushSummary' => __('Auto-push result:', 'merchandillo-woocommerce-bridge'),
            'overwriteSummary' => __('Overwrite result:', 'merchandillo-woocommerce-bridge'),
            'fieldLabel' => __('Field', 'merchandillo-woocommerce-bridge'),
            'merchandilloLabel' => __('Merchandillo', 'merchandillo-woocommerce-bridge'),
            'woocommerceLabel' => __('WooCommerce', 'merchandillo-woocommerce-bridge'),
            'orderLabel' => __('Order', 'merchandillo-woocommerce-bridge'),
            'stateLabel' => __('State', 'merchandillo-woocommerce-bridge'),
            'messageLabel' => __('Message', 'merchandillo-woocommerce-bridge'),
            'errorLabel' => __('Error:', 'merchandillo-woocommerce-bridge'),
            'successLabel' => __('Success:', 'merchandillo-woocommerce-bridge'),
        ];

        $textAlign = function_exists('is_rtl') && is_rtl() ? 'right' : 'left';
        $ajaxUrl = admin_url('admin-ajax.php');

        echo '<div id="mwb-bulk-order-push-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.45);text-align:' . esc_attr($textAlign) . ';">';
        echo '<div style="max-width:900px;width:94%;margin:5vh auto;background:#fff;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.25);overflow:hidden;text-align:' . esc_attr($textAlign) . ';">';
        echo '<div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:10px;">';
        echo '<h2 style="margin:0;font-size:18px;line-height:1.3;">' . esc_html__('Bulk Push Orders to Merchandillo', 'merchandillo-woocommerce-bridge') . '</h2>';
        echo '<button type="button" id="mwb-bulk-order-push-close" class="button" style="min-width:32px;padding:0 10px;">&times;</button>';
        echo '</div>';
        echo '<div id="mwb-bulk-order-push-content" style="padding:16px 18px;max-height:58vh;overflow:auto;color:#0f172a;"></div>';
        echo '<div style="padding:14px 18px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;">';
        echo '<button type="button" id="mwb-bulk-order-push-cancel" class="button">' . esc_html__('Cancel', 'merchandillo-woocommerce-bridge') . '</button>';
        echo '<button type="button" id="mwb-bulk-order-push-confirm" class="button button-primary" style="display:none;background:rgb(77, 121, 170);border-color:rgb(77, 121, 170);">' . esc_html__('Overwrite differing orders in Merchandillo', 'merchandillo-woocommerce-bridge') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<script>(function(){';
        echo 'var t=' . wp_json_encode($i18n) . ';';
        echo 'var modal=document.getElementById("mwb-bulk-order-push-modal");if(!modal){return;}';
        echo 'var content=document.getElementById("mwb-bulk-order-push-content");var confirmBtn=document.getElementById("mwb-bulk-order-push-confirm");var closeBtn=document.getElementById("mwb-bulk-order-push-close");var cancelBtn=document.getElementById("mwb-bulk-order-push-cancel");';
        echo 'var state={orderIds:' . wp_json_encode(array_values($orderIds)) . ',nonce:' . wp_json_encode($nonce) . ',ajaxUrl:' . wp_json_encode($ajaxUrl) . ',differentOrderIds:[]};';
        echo 'function esc(s){return String(s===undefined?"":s).replace(/[&<>]/g,function(c){return({"&":"&amp;","<":"&lt;",">":"&gt;"})[c];});}';
        echo 'function open(){modal.style.display="block";document.body.style.overflow="hidden";}';
        echo 'function close(){modal.style.display="none";document.body.style.overflow="";confirmBtn.style.display="none";confirmBtn.disabled=false;}';
        echo 'function post(action,data){var body=new URLSearchParams();Object.keys(data||{}).forEach(function(k){body.append(k,data[k]);});body.append("action",action);return fetch(state.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString(),credentials:"same-origin"}).then(function(r){return r.json();});}';
        echo 'function renderSummary(summary){if(!summary){return "";}var html="<h3 style=\'margin:6px 0 10px;font-size:15px;\'>"+esc(t.summaryTitle)+"</h3>";html+="<ul style=\'margin:0 0 10px 18px;padding:0;line-height:1.6;\'><li>Total: "+esc(summary.total||0)+"</li><li>Not found: "+esc(summary.not_found||0)+"</li><li>Identical: "+esc(summary.identical||0)+"</li><li>Different: "+esc(summary.different||0)+"</li><li>Invalid: "+esc(summary.invalid||0)+"</li><li>Compare errors: "+esc(summary.compare_errors||0)+"</li></ul>";return html;}';
        echo 'function renderOrderRows(orders){if(!orders||!orders.length){return "";}var rows=orders.map(function(item){return "<tr><td style=\'padding:6px;border:1px solid #e2e8f0;white-space:nowrap;\'>#"+esc(item.order_id||0)+"</td><td style=\'padding:6px;border:1px solid #e2e8f0;white-space:nowrap;\'><code>"+esc(item.state||"")+"</code></td><td style=\'padding:6px;border:1px solid #e2e8f0;\'><span>"+esc(item.message||"")+"</span></td></tr>";}).join("");return "<div style=\'overflow:auto;max-height:240px;margin-top:8px;\'><table style=\'width:100%;border-collapse:collapse;font-size:12px;\'><thead><tr><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.orderLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.stateLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.messageLabel)+"</th></tr></thead><tbody>"+rows+"</tbody></table></div>";}';
        echo 'function renderDifferences(orders){if(!orders||!orders.length){return "";}var blocks=[];orders.forEach(function(item){if(item.state!=="different"||!item.differences||!item.differences.length){return;}var rows=item.differences.map(function(d){return "<tr><td style=\'padding:6px;border:1px solid #e2e8f0;white-space:nowrap;\'><code>"+esc(d.field)+"</code></td><td style=\'padding:6px;border:1px solid #e2e8f0;\'><code>"+esc(d.remote)+"</code></td><td style=\'padding:6px;border:1px solid #e2e8f0;\'><code>"+esc(d.local)+"</code></td></tr>";}).join("");blocks.push("<details style=\'margin:10px 0;\'><summary><strong>#"+esc(item.order_id||0)+"</strong></summary><div style=\'overflow:auto;max-height:220px;margin-top:6px;\'><table style=\'width:100%;border-collapse:collapse;font-size:12px;\'><thead><tr><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.fieldLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.merchandilloLabel)+"</th><th style=\'padding:6px;border:1px solid #e2e8f0;text-align:left;\'>"+esc(t.woocommerceLabel)+"</th></tr></thead><tbody>"+rows+"</tbody></table></div></details>");});return blocks.join("");}';
        echo 'function renderPushResult(res,prefix){if(!res){return "";}var data=(res.data&&typeof res.data==="object")?res.data:{};var summary=(data.summary&&typeof data.summary==="object")?data.summary:{};var requested=Number(summary.requested||0);var pushed=Number(summary.pushed||0);var failed=Number(summary.failed||0);var cls=res.success?"#166534":"#b91c1c";var bg=res.success?"#f0fdf4":"#fef2f2";var border=res.success?"#bbf7d0":"#fecaca";var msg=(data.message&&String(data.message).trim()!=="")?String(data.message):"";var html="<div style=\'margin-top:10px;padding:10px;border:1px solid "+border+";background:"+bg+";color:"+cls+";border-radius:8px;\'><strong>"+esc(prefix)+"</strong> "+esc(msg)+"<div>Requested: "+esc(requested)+" | Pushed: "+esc(pushed)+" | Failed: "+esc(failed)+"</div></div>";if(Array.isArray(data.results)&&data.results.length){var items=data.results.map(function(item){var ok=item&&item.ok;return "<li><code>#"+esc(item&&item.order_id?item.order_id:0)+"</code> "+(ok?"ok":"failed")+" - "+esc(item&&item.message?item.message:"")+"</li>";}).join("");html+="<ul style=\'margin:8px 0 0 18px;padding:0;max-height:180px;overflow:auto;line-height:1.5;\'>"+items+"</ul>";}return html;}';
        echo 'function pushOrders(orderIds){if(!orderIds||!orderIds.length){return Promise.resolve({success:true,data:{summary:{requested:0,pushed:0,failed:0},results:[]}});}return post("' . esc_js(self::BULK_PUSH_AJAX_ACTION) . '",{nonce:state.nonce,order_ids:orderIds.join(",")});}';
        echo 'function finalizeComparison(data){var different=Array.isArray(data.different_order_ids)?data.different_order_ids:[];state.differentOrderIds=different;if(!different.length){content.innerHTML+="<div style=\'margin-top:10px;padding:10px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:8px;\'><strong>"+esc(t.successLabel)+"</strong> "+esc(t.noDifferencesDone)+"</div>";return;}content.innerHTML+="<p style=\'margin-top:12px;\'><strong>"+esc(t.differencesRequireAction)+"</strong></p>"+renderDifferences(data.orders||[]);confirmBtn.textContent=t.overwriteDiffering;confirmBtn.style.display="inline-block";}';
        echo 'function runCompare(){content.innerHTML="<p>"+esc(t.checkingOrders)+"</p>";confirmBtn.style.display="none";post("' . esc_js(self::BULK_COMPARE_AJAX_ACTION) . '",{nonce:state.nonce,order_ids:state.orderIds.join(",")}).then(function(res){if(!res||!res.success){throw new Error((res&&res.data&&res.data.message)?res.data.message:t.requestFailed);}var data=(res.data&&typeof res.data==="object")?res.data:{};content.innerHTML="<p>"+esc(data.message||"")+"</p>"+renderSummary(data.summary||{})+renderOrderRows(data.orders||[]);var missing=Array.isArray(data.not_found_order_ids)?data.not_found_order_ids:[];if(!missing.length){finalizeComparison(data);return;}content.innerHTML+="<p>"+esc(t.autoPushingMissing)+"</p>";return pushOrders(missing).then(function(pushRes){content.innerHTML+=renderPushResult(pushRes,t.autoPushSummary);finalizeComparison(data);});}).catch(function(err){content.innerHTML="<div style=\'padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:8px;\'><strong>"+esc(t.errorLabel)+"</strong> "+esc(err.message||t.unexpectedResponse)+"</div>";});}';
        echo 'confirmBtn.addEventListener("click",function(e){e.preventDefault();confirmBtn.disabled=true;content.innerHTML+="<p>"+esc(t.overwriteInProgress)+"</p>";pushOrders(state.differentOrderIds).then(function(res){content.innerHTML+=renderPushResult(res,t.overwriteSummary);confirmBtn.style.display="none";confirmBtn.disabled=false;}).catch(function(err){confirmBtn.disabled=false;content.innerHTML+="<div style=\'padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:8px;\'><strong>"+esc(t.errorLabel)+"</strong> "+esc(err.message||t.requestFailed)+"</div>";});});';
        echo 'closeBtn.addEventListener("click",function(e){e.preventDefault();close();});cancelBtn.addEventListener("click",function(e){e.preventDefault();close();});modal.addEventListener("click",function(e){if(e.target===modal){close();}});';
        echo 'open();runCompare();';
        echo '})();</script>';
    }

    /**
     * @param mixed $rawOrderIds
     * @return array<int,int>
     */
    private function parse_bulk_order_ids_from_request($rawOrderIds, int $max = self::BULK_MAX_ORDER_SELECTION): array
    {
        if ($max <= 0) {
            return [];
        }

        $values = [];
        if (is_array($rawOrderIds)) {
            $values = $rawOrderIds;
        } elseif (is_string($rawOrderIds) && '' !== trim($rawOrderIds)) {
            $values = explode(',', $rawOrderIds);
        }

        $orderIds = [];
        $seen = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $orderId = absint((string) $value);
            if ($orderId <= 0 || isset($seen[$orderId])) {
                continue;
            }

            $seen[$orderId] = true;
            $orderIds[] = $orderId;
            if (count($orderIds) >= $max) {
                break;
            }
        }

        return $orderIds;
    }

    /**
     * @param array<int,int> $orderIds
     * @return array{
     *     ok:bool,
     *     message:string,
     *     summary:array<string,int>,
     *     orders:array<int,array<string,mixed>>,
     *     not_found_order_ids:array<int,int>,
     *     different_order_ids:array<int,int>,
     *     identical_order_ids:array<int,int>
     * }
     */
    private function build_bulk_compare_result(array $orderIds): array
    {
        $settings = $this->service_locator()->settings()->get();
        if ('1' !== (string) $settings['enabled']) {
            return [
                'ok' => false,
                'message' => __('Sync is disabled in plugin settings.', 'merchandillo-woocommerce-bridge'),
                'summary' => [],
                'orders' => [],
                'not_found_order_ids' => [],
                'different_order_ids' => [],
                'identical_order_ids' => [],
            ];
        }

        if (!$this->has_required_api_settings($settings)) {
            return [
                'ok' => false,
                'message' => __('API credentials are incomplete.', 'merchandillo-woocommerce-bridge'),
                'summary' => [],
                'orders' => [],
                'not_found_order_ids' => [],
                'different_order_ids' => [],
                'identical_order_ids' => [],
            ];
        }

        $remoteIndex = $this->build_remote_order_index($settings);
        if (!$remoteIndex['ok']) {
            return [
                'ok' => false,
                'message' => $remoteIndex['message'],
                'summary' => [],
                'orders' => [],
                'not_found_order_ids' => [],
                'different_order_ids' => [],
                'identical_order_ids' => [],
            ];
        }

        $summary = [
            'total' => count($orderIds),
            'not_found' => 0,
            'identical' => 0,
            'different' => 0,
            'invalid' => 0,
            'compare_errors' => 0,
        ];
        $orders = [];
        $notFoundOrderIds = [];
        $differentOrderIds = [];
        $identicalOrderIds = [];

        foreach ($orderIds as $orderId) {
            $context = $this->build_manual_push_context($orderId);
            if (!$context['ok']) {
                $summary['invalid']++;
                $orders[] = [
                    'order_id' => $orderId,
                    'state' => 'invalid',
                    'message' => $context['message'],
                    'differences' => [],
                ];
                continue;
            }

            $remoteOrder = $this->find_remote_order_from_index(
                $context['payload'],
                $remoteIndex['by_id'],
                $remoteIndex['by_order_number']
            );
            if (null === $remoteOrder) {
                $summary['not_found']++;
                $notFoundOrderIds[] = $orderId;
                $orders[] = [
                    'order_id' => $orderId,
                    'state' => 'not_found',
                    'message' => __('This order does not exist in Merchandillo yet. You can push it now.', 'merchandillo-woocommerce-bridge'),
                    'differences' => [],
                ];
                continue;
            }

            $differences = $this->calculate_payload_differences($context['payload'], $remoteOrder);
            if (empty($differences)) {
                $summary['identical']++;
                $identicalOrderIds[] = $orderId;
                $orders[] = [
                    'order_id' => $orderId,
                    'state' => 'identical',
                    'message' => __('Order already exists in Merchandillo and no differences were detected.', 'merchandillo-woocommerce-bridge'),
                    'differences' => [],
                ];
                continue;
            }

            $summary['different']++;
            $differentOrderIds[] = $orderId;
            $orders[] = [
                'order_id' => $orderId,
                'state' => 'different',
                'message' => __('Order exists in Merchandillo and differences were found. Review them before overwriting.', 'merchandillo-woocommerce-bridge'),
                'differences' => $differences,
            ];
        }

        return [
            'ok' => true,
            'message' => __('Bulk comparison finished.', 'merchandillo-woocommerce-bridge'),
            'summary' => $summary,
            'orders' => $orders,
            'not_found_order_ids' => $notFoundOrderIds,
            'different_order_ids' => $differentOrderIds,
            'identical_order_ids' => $identicalOrderIds,
        ];
    }

    /**
     * @param array<string,string> $settings
     * @return array{
     *     ok:bool,
     *     message:string,
     *     by_id:array<int,array<string,mixed>>,
     *     by_order_number:array<string,array<string,mixed>>
     * }
     */
    private function build_remote_order_index(array $settings): array
    {
        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';
        $page = 1;
        $limit = 50;
        $maxPages = 10;
        $byId = [];
        $byOrderNumber = [];

        while ($page <= $maxPages) {
            $requestEndpoint = add_query_arg(
                [
                    'page' => (string) $page,
                    'limit' => (string) $limit,
                ],
                $endpoint
            );
            $rejectUnsafeUrls = !$this->is_local_dev_endpoint($endpoint);
            $response = wp_remote_get(
                $requestEndpoint,
                [
                    'timeout' => 3,
                    'redirection' => 0,
                    'reject_unsafe_urls' => $rejectUnsafeUrls,
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-API-Key' => (string) $settings['api_key'],
                        'X-API-Secret' => (string) $settings['api_secret'],
                    ],
                ]
            );

            if (is_wp_error($response)) {
                return [
                    'ok' => false,
                    'message' => __('Could not read orders from Merchandillo.', 'merchandillo-woocommerce-bridge') . ' ' . $response->get_error_message(),
                    'by_id' => [],
                    'by_order_number' => [],
                ];
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'ok' => false,
                    'message' => sprintf(__('Could not read orders from Merchandillo (HTTP %d).', 'merchandillo-woocommerce-bridge'), $statusCode),
                    'by_id' => [],
                    'by_order_number' => [],
                ];
            }

            $body = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return [
                    'ok' => false,
                    'message' => __('Merchandillo returned an invalid response while reading orders.', 'merchandillo-woocommerce-bridge'),
                    'by_id' => [],
                    'by_order_number' => [],
                ];
            }

            $orders = isset($decoded['orders']) && is_array($decoded['orders']) ? $decoded['orders'] : [];
            foreach ($orders as $remoteOrder) {
                if (!is_array($remoteOrder)) {
                    continue;
                }

                $remoteId = isset($remoteOrder['id']) ? (int) $remoteOrder['id'] : 0;
                if ($remoteId > 0 && !isset($byId[$remoteId])) {
                    $byId[$remoteId] = $remoteOrder;
                }

                $remoteOrderNumber = isset($remoteOrder['order_number']) ? trim((string) $remoteOrder['order_number']) : '';
                if ('' !== $remoteOrderNumber && !isset($byOrderNumber[$remoteOrderNumber])) {
                    $byOrderNumber[$remoteOrderNumber] = $remoteOrder;
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

        return [
            'ok' => true,
            'message' => '',
            'by_id' => $byId,
            'by_order_number' => $byOrderNumber,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $byId
     * @param array<string,array<string,mixed>> $byOrderNumber
     * @return array<string,mixed>|null
     */
    private function find_remote_order_from_index(array $payload, array $byId, array $byOrderNumber): ?array
    {
        $targetId = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($targetId > 0 && isset($byId[$targetId])) {
            return $byId[$targetId];
        }

        $targetOrderNumber = isset($payload['order_number']) ? trim((string) $payload['order_number']) : '';
        if ('' !== $targetOrderNumber && isset($byOrderNumber[$targetOrderNumber])) {
            return $byOrderNumber[$targetOrderNumber];
        }

        return null;
    }

    private function render_admin_notice(string $message, string $noticeClass): void
    {
        if ('' === trim($message)) {
            return;
        }

        echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    /**
     * @return array{ok:bool,order_ids:array<int,int>,nonce:string}
     */
    private function bulk_push_launch_context_from_query(): array
    {
        $shouldLaunch = isset($_GET['merchandillo_bulk_push'])
            && '1' === (string) wp_unslash($_GET['merchandillo_bulk_push']);
        if (!$shouldLaunch) {
            return [
                'ok' => false,
                'order_ids' => [],
                'nonce' => '',
            ];
        }

        $rawIds = isset($_GET['merchandillo_bulk_ids']) ? wp_unslash($_GET['merchandillo_bulk_ids']) : '';
        $orderIds = $this->parse_bulk_order_ids_from_request($rawIds, self::BULK_MAX_ORDER_SELECTION);
        $nonce = isset($_GET['merchandillo_bulk_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['merchandillo_bulk_nonce'])) : '';

        if (empty($orderIds) || '' === $nonce) {
            return [
                'ok' => false,
                'order_ids' => [],
                'nonce' => '',
            ];
        }

        return [
            'ok' => true,
            'order_ids' => $orderIds,
            'nonce' => $nonce,
        ];
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
            $rejectUnsafeUrls = !$this->is_local_dev_endpoint($endpoint);
            $response = wp_remote_get(
                $requestEndpoint,
                [
                    'timeout' => 3,
                    'redirection' => 0,
                    'reject_unsafe_urls' => $rejectUnsafeUrls,
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
        $rejectUnsafeUrls = !$this->is_local_dev_endpoint($endpoint);
        $response = wp_remote_post(
            $endpoint,
            [
                'method' => 'POST',
                'timeout' => 8,
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

    private function bulk_push_redirect_url(): string
    {
        if (function_exists('wp_get_referer')) {
            $referer = wp_get_referer();
            if (is_string($referer) && '' !== $referer) {
                return $referer;
            }
        }

        return admin_url('edit.php?post_type=shop_order');
    }

    private function should_register_updater_hooks(): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        return defined('WP_CLI') && true === WP_CLI;
    }

    private function github_updater(): Merchandillo_Github_Updater
    {
        if (null === $this->githubUpdater) {
            $this->githubUpdater = new Merchandillo_Github_Updater();
        }

        return $this->githubUpdater;
    }
}
