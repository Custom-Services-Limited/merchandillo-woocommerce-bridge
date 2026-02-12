<?php

final class Merchandillo_Admin_Page
{
    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var Merchandillo_Settings_Tab */
    private $settingsTab;

    /** @var Merchandillo_Logs_Tab */
    private $logsTab;

    /** @var Merchandillo_Api_Connection_Tester_Interface */
    private $apiConnectionTester;

    /** @var string */
    private $pageSlug;

    /** @var string */
    private $actionKey;

    /** @var string */
    private $nonceAction;

    /** @var Merchandillo_Admin_Page_Renderer */
    private $renderer;

    public function __construct(
        Merchandillo_Settings_Interface $settings,
        Merchandillo_Settings_Tab $settingsTab,
        Merchandillo_Logs_Tab $logsTab,
        Merchandillo_Api_Connection_Tester_Interface $apiConnectionTester,
        string $pageSlug,
        string $actionKey,
        string $nonceAction
    ) {
        $this->settings = $settings;
        $this->settingsTab = $settingsTab;
        $this->logsTab = $logsTab;
        $this->apiConnectionTester = $apiConnectionTester;
        $this->pageSlug = $pageSlug;
        $this->actionKey = $actionKey;
        $this->nonceAction = $nonceAction;
        $this->renderer = new Merchandillo_Admin_Page_Renderer($settingsTab, $logsTab, $pageSlug);
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            'manage_options',
            $this->pageSlug,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'merchandillo_sync_settings_group',
            $this->settings->option_name(),
            [
                'type' => 'array',
                'sanitize_callback' => [$this->settings, 'sanitize'],
                'default' => $this->settings->defaults(),
            ]
        );

        $this->settingsTab->register_fields();
    }

    public function handle_log_actions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key((string) wp_unslash($_REQUEST['page'])) : '';
        if ($this->pageSlug !== $page) {
            return;
        }

        $action = isset($_REQUEST[$this->actionKey]) ? sanitize_key((string) wp_unslash($_REQUEST[$this->actionKey])) : '';
        if ('' === $action) {
            return;
        }

        check_admin_referer($this->nonceAction);

        if ('export' === $action) {
            $this->logsTab->output_export($_GET);
            exit;
        }

        if ('test_connection' === $action) {
            $result = $this->apiConnectionTester->run();
            $redirectArgs = [
                'page' => $this->pageSlug,
                'tab' => 'settings',
                'api_test_result' => $result['code'],
            ];
            if ((int) $result['http_status'] > 0) {
                $redirectArgs['api_test_http_status'] = (string) $result['http_status'];
            }
            $redirectUrl = add_query_arg($redirectArgs, admin_url('options-general.php'));
            wp_safe_redirect($redirectUrl);
            exit;
        }

        if ('clear' !== $action) {
            return;
        }

        $result = $this->logsTab->clear_files();
        $redirectUrl = add_query_arg(
            [
                'page' => $this->pageSlug,
                'tab' => 'logs',
                'logs_cleared' => (string) $result['deleted'],
                'logs_failed' => (string) $result['failed'],
            ],
            admin_url('options-general.php')
        );
        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function render_settings_page(): void
    {
        $this->renderer->render($_GET);
    }

    public function enqueue_admin_assets(string $hookSuffix): void
    {
        if ('settings_page_' . $this->pageSlug !== $hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'merchandillo-wc-bridge-admin',
            plugins_url('assets/admin.css', MERCHANDILLO_WC_BRIDGE_FILE),
            [],
            defined('MERCHANDILLO_WC_BRIDGE_VERSION') ? MERCHANDILLO_WC_BRIDGE_VERSION : '1.0.0'
        );
    }

    public function get_current_tab(): string
    {
        return $this->renderer->get_current_tab($_GET);
    }

    /**
     * @param array<string,mixed> $extraArgs
     */
    public function get_settings_page_url(array $extraArgs = []): string
    {
        return $this->renderer->get_settings_page_url($extraArgs);
    }
}
