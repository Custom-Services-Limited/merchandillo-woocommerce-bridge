<?php

final class Merchandillo_Admin_Page_Renderer
{
    private const TAB_MEMORY_OPTION_PREFIX = 'merchandillo_wc_bridge_last_tab_';

    /** @var Merchandillo_Settings_Tab */
    private $settingsTab;

    /** @var Merchandillo_Logs_Tab */
    private $logsTab;

    /** @var string */
    private $pageSlug;

    /** @var Merchandillo_Api_Test_Notice_Renderer */
    private $apiTestNoticeRenderer;

    public function __construct(
        Merchandillo_Settings_Tab $settingsTab,
        Merchandillo_Logs_Tab $logsTab,
        string $pageSlug
    ) {
        $this->settingsTab = $settingsTab;
        $this->logsTab = $logsTab;
        $this->pageSlug = $pageSlug;
        $this->apiTestNoticeRenderer = new Merchandillo_Api_Test_Notice_Renderer();
    }

    /**
     * @param array<string,mixed> $request
     */
    public function render(array $request): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = $this->get_current_tab($request);

        echo '<div class="wrap mwb-admin">';
        echo '<div class="mwb-hero">';
        echo '<div class="mwb-hero-content">';
        echo '<h1 class="mwb-title">' . esc_html__('Merchandillo Bridge for WooCommerce', 'merchandillo-woocommerce-bridge') . '</h1>';
        echo '<p class="mwb-subtitle">' . esc_html__('Sync order changes with confidence using modern controls for API credentials and live log diagnostics.', 'merchandillo-woocommerce-bridge') . '</p>';
        echo '</div>';
        echo '<a class="button button-primary mwb-hero-link" href="' . esc_url('https://merchandillo.com') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Register at Merchandillo.com', 'merchandillo-woocommerce-bridge') . '</a>';
        echo '</div>';

        $this->render_navigation_tabs($tab);

        echo '<div class="mwb-content">';
        $this->apiTestNoticeRenderer->render($request);
        $this->logsTab->render_notice($request);
        if ('logs' === $tab) {
            $this->logsTab->render_tab($request);
        } else {
            $this->settingsTab->render_tab();
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $request
     */
    public function get_current_tab(array $request): string
    {
        $tab = isset($request['tab']) ? sanitize_key((string) wp_unslash($request['tab'])) : '';

        if (in_array($tab, ['settings', 'logs'], true)) {
            $this->persist_last_tab($tab);
            return $tab;
        }

        $storedTab = sanitize_key((string) get_option($this->tab_memory_option_name(), 'settings'));

        return in_array($storedTab, ['settings', 'logs'], true) ? $storedTab : 'settings';
    }

    /**
     * @param array<string,mixed> $extraArgs
     */
    public function get_settings_page_url(array $extraArgs = []): string
    {
        return $this->logsTab->get_page_url($extraArgs);
    }

    private function render_navigation_tabs(string $activeTab): void
    {
        $baseUrl = $this->get_settings_page_url();
        $tabs = [
            'settings' => __('Settings', 'merchandillo-woocommerce-bridge'),
            'logs' => __('Logs', 'merchandillo-woocommerce-bridge'),
        ];

        echo '<h2 class="nav-tab-wrapper mwb-tab-wrapper">';
        foreach ($tabs as $tabKey => $tabLabel) {
            $tabUrl = add_query_arg('tab', $tabKey, $baseUrl);
            $className = 'nav-tab mwb-tab' . ('settings' === $tabKey && 'settings' === $activeTab ? ' nav-tab-active' : '') . ('logs' === $tabKey && 'logs' === $activeTab ? ' nav-tab-active' : '');
            echo '<a href="' . esc_url($tabUrl) . '" class="' . esc_attr($className) . '">' . esc_html($tabLabel) . '</a>';
        }
        echo '</h2>';
    }

    private function tab_memory_option_name(): string
    {
        return self::TAB_MEMORY_OPTION_PREFIX . sanitize_key($this->pageSlug);
    }

    private function persist_last_tab(string $tab): void
    {
        $optionName = $this->tab_memory_option_name();
        if (function_exists('update_option')) {
            update_option($optionName, $tab);
            return;
        }

        add_option($optionName, $tab);
    }
}
