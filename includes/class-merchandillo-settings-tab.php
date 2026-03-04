<?php

final class Merchandillo_Settings_Tab
{
    /** @var Merchandillo_Settings_Interface */
    private $settings;

    /** @var string */
    private $pageSlug;

    /** @var Merchandillo_Settings_Api_Test_Renderer */
    private $apiTestRenderer;

    public function __construct(
        Merchandillo_Settings_Interface $settings,
        string $pageSlug,
        string $actionKey = 'merchandillo_logs_action',
        string $nonceAction = 'merchandillo_logs_action'
    )
    {
        $this->settings = $settings;
        $this->pageSlug = $pageSlug;
        $this->apiTestRenderer = new Merchandillo_Settings_Api_Test_Renderer($pageSlug, $actionKey, $nonceAction);
    }

    public function register_fields(): void
    {
        add_settings_section(
            'merchandillo_sync_main',
            __('Platform Credentials', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_section_description'],
            $this->pageSlug
        );

        add_settings_field(
            'enabled',
            __('Enable Sync', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_enabled_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_base_url',
            __('API Base URL', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_base_url_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_key_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_secret',
            __('API Secret', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_secret_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'ui_language',
            __('Language', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_ui_language_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'log_errors',
            __('Log Errors', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_log_errors_field'],
            $this->pageSlug,
            'merchandillo_sync_main'
        );
    }

    public function render_tab(): void
    {
        $settings = $this->settings->get();

        echo '<div class="mwb-card">';
        echo '<form method="post" action="options.php" class="mwb-settings-form">';
        settings_fields('merchandillo_sync_settings_group');
        do_settings_sections($this->pageSlug);
        echo '<div class="mwb-form-actions">';
        echo get_submit_button(__('Save Settings', 'merchandillo-woocommerce-bridge'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>';
        $this->apiTestRenderer->render($settings);
        echo '</div>';
    }

    public function render_section_description(): void
    {
        echo '<p class="mwb-section-description">' . esc_html__('Configure API credentials for pushing order updates to Merchandillo. Failed sync attempts are logged and never break WooCommerce checkout. Use the Logs tab to inspect, export, and clear plugin logs.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_enabled_field(): void
    {
        $settings = $this->settings->get();
        $checked = '1' === (string) $settings['enabled'];

        echo '<label class="mwb-check">';
        echo '<input type="checkbox" name="' . esc_attr($this->settings->option_name()) . '[enabled]" value="1" ' . checked($checked, true, false) . ' />';
        echo '<span>' . esc_html__('Queue order sync on create/update/status change', 'merchandillo-woocommerce-bridge') . '</span>';
        echo '</label>';
    }

    public function render_api_base_url_field(): void
    {
        $settings = $this->settings->get();
        $value = (string) $settings['api_base_url'];
        $isLocalDev = 0 === strpos($value, 'http://localhost:') || 0 === strpos($value, 'http://host.docker.internal:');
        $mode = $isLocalDev ? 'local_dev' : 'merchandillo_com';
        $localDevValue = $isLocalDev ? $value : 'http://localhost:8787';
        $fieldNamePrefix = $this->settings->option_name();

        echo '<select id="mwb-api-base-url-mode" class="mwb-input mwb-select" name="' . esc_attr($fieldNamePrefix) . '[api_base_url_mode]">';
        echo '<option value="local_dev"' . selected($mode, 'local_dev', false) . '>' . esc_html__('Local Dev', 'merchandillo-woocommerce-bridge') . '</option>';
        echo '<option value="merchandillo_com"' . selected($mode, 'merchandillo_com', false) . '>' . esc_html__('merchandillo.com', 'merchandillo-woocommerce-bridge') . '</option>';
        echo '</select>';

        $localStyle = 'local_dev' === $mode ? '' : ' style="display:none;"';
        echo '<div id="mwb-api-base-url-local-wrap"' . $localStyle . '>';
        echo '<label for="mwb-api-base-url-local" class="mwb-label">' . esc_html__('Local Dev URL', 'merchandillo-woocommerce-bridge') . '</label>';
        echo '<input id="mwb-api-base-url-local" type="url" class="regular-text code mwb-input" name="' . esc_attr($fieldNamePrefix) . '[api_base_url_local]" value="' . esc_attr($localDevValue) . '" placeholder="http://localhost:8787" />';
        echo '<p class="description mwb-help">' . esc_html__('Example: http://localhost:8787', 'merchandillo-woocommerce-bridge') . '</p>';
        echo '</div>';

        echo '<script>(function(){var mode=document.getElementById("mwb-api-base-url-mode");var localWrap=document.getElementById("mwb-api-base-url-local-wrap");if(!mode||!localWrap){return;}function sync(){localWrap.style.display=(mode.value==="local_dev")?"":"none";}mode.addEventListener("change",sync);sync();})();</script>';
    }

    public function render_api_key_field(): void
    {
        $settings = $this->settings->get();
        $value = (string) $settings['api_key'];

        echo '<input type="text" class="regular-text code mwb-input" name="' . esc_attr($this->settings->option_name()) . '[api_key]" value="' . esc_attr($value) . '" autocomplete="off" />';
    }

    public function render_api_secret_field(): void
    {
        $settings = $this->settings->get();
        $hasSecret = '' !== (string) $settings['api_secret'];

        echo '<input type="password" class="regular-text code mwb-input" name="' . esc_attr($this->settings->option_name()) . '[api_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr($hasSecret ? '****************' : '') . '" />';
        echo '<p class="description mwb-help">' . esc_html__('Leave empty to keep the current secret.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_log_errors_field(): void
    {
        $settings = $this->settings->get();
        $checked = '1' === (string) $settings['log_errors'];

        echo '<label class="mwb-check">';
        echo '<input type="checkbox" name="' . esc_attr($this->settings->option_name()) . '[log_errors]" value="1" ' . checked($checked, true, false) . ' />';
        echo '<span>' . esc_html__('Write sync failures to WooCommerce logs', 'merchandillo-woocommerce-bridge') . '</span>';
        echo '</label>';
    }

    public function render_ui_language_field(): void
    {
        $settings = $this->settings->get();
        $selectedLanguage = isset($settings['ui_language']) ? sanitize_key((string) $settings['ui_language']) : 'en';
        if (!in_array($selectedLanguage, ['en', 'el'], true)) {
            $selectedLanguage = 'en';
        }

        echo '<select class="mwb-input mwb-select" name="' . esc_attr($this->settings->option_name()) . '[ui_language]">';
        echo '<option value="en"' . selected($selectedLanguage, 'en', false) . '>' . esc_html__('English', 'merchandillo-woocommerce-bridge') . '</option>';
        echo '<option value="el"' . selected($selectedLanguage, 'el', false) . '>' . esc_html__('Greek', 'merchandillo-woocommerce-bridge') . '</option>';
        echo '</select>';
    }
}
