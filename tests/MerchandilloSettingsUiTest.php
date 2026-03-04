<?php

declare(strict_types=1);

final class MerchandilloSettingsUiTest extends MerchandilloTestCase
{
    public function test_settings_tab_renders_section_description(): void
    {
        $settingsTab = $this->newSettingsTab();

        ob_start();
        $settingsTab->render_section_description();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Use the Logs tab', $output);
    }

    public function test_settings_tab_renders_enabled_field_checkbox(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_enabled_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('checked="checked"', $output);
        $this->assertStringContainsString('Queue order sync', $output);
    }

    public function test_settings_tab_renders_api_base_url_field(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'http://localhost:8787',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_api_base_url_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('name="merchandillo_sync_options[api_base_url_mode]"', $output);
        $this->assertStringContainsString('Local Dev', $output);
        $this->assertStringContainsString('merchandillo.com', $output);
        $this->assertStringContainsString('name="merchandillo_sync_options[api_base_url_local]"', $output);
        $this->assertStringContainsString('http://localhost:8787', $output);
        $this->assertStringContainsString('selected="selected"', $output);
        $this->assertStringNotContainsString('Allowed values: https://data.merchandillo.com, http://host.docker.internal:{port}, http://localhost:{port}', $output);
    }

    public function test_settings_tab_hides_local_dev_url_input_when_merchandillo_mode_selected(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_api_base_url_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('id="mwb-api-base-url-local-wrap" style="display:none;"', $output);
        $this->assertStringContainsString('name="merchandillo_sync_options[api_base_url_mode]"', $output);
    }

    public function test_settings_tab_renders_api_key_field(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'test-key',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_api_key_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('test-key', $output);
    }

    public function test_settings_tab_renders_api_secret_field_mask(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'test-key',
            'api_secret' => 'secret-value',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_api_secret_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('****************', $output);
        $this->assertStringContainsString('Leave empty to keep the current secret', $output);
    }

    public function test_settings_tab_renders_log_errors_field_checked(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_log_errors_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('checked="checked"', $output);
        $this->assertStringContainsString('Write sync failures', $output);
    }

    public function test_admin_page_validates_tabs(): void
    {
        $adminPage = $this->newAdminPage();

        $_GET = [];
        $this->assertSame('settings', $adminPage->get_current_tab());

        $_GET = ['tab' => 'logs'];
        $this->assertSame('logs', $adminPage->get_current_tab());

        $_GET = ['tab' => 'invalid-tab'];
        $this->assertSame('logs', $adminPage->get_current_tab());

        $_GET = [];
        $this->assertSame('logs', $adminPage->get_current_tab());
    }

    public function test_render_settings_page_outputs_tabs_and_backlink(): void
    {
        $bridge = $this->newBridge();
        $_GET = ['tab' => 'settings'];

        ob_start();
        $bridge->render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('nav-tab-wrapper', $output);
        $this->assertStringContainsString('Register at Merchandillo.com', $output);
        $this->assertStringContainsString('Settings', $output);
        $this->assertStringContainsString('Logs', $output);
    }

    public function test_settings_navigation_link_uses_explicit_settings_tab_query(): void
    {
        $bridge = $this->newBridge();
        $_GET = ['tab' => 'logs'];

        ob_start();
        $bridge->render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('tab=settings', $output);
    }

    public function test_admin_page_settings_url_merges_extra_query_arguments(): void
    {
        $adminPage = $this->newAdminPage();

        $url = $adminPage->get_settings_page_url(['tab' => 'logs', 'foo' => 'bar']);

        $this->assertStringContainsString('page=merchandillo-woocommerce-bridge', $url);
        $this->assertStringContainsString('tab=logs', $url);
        $this->assertStringContainsString('foo=bar', $url);
    }

    public function test_settings_tab_renders_connection_test_button_when_credentials_exist(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'test-key',
            'api_secret' => 'secret-value',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_tab();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Test API Connection', $output);
    }

    public function test_settings_tab_hides_connection_test_button_when_credentials_missing(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_tab();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('enable API connection tests', $output);
        $this->assertStringNotContainsString('Test API Connection', $output);
    }

    public function test_settings_tab_renders_language_field_with_selected_value(): void
    {
        $settingsTab = $this->newSettingsTab();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'test-key',
            'api_secret' => 'secret-value',
            'ui_language' => 'el',
            'log_errors' => '1',
        ];

        ob_start();
        $settingsTab->render_ui_language_field();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('value="en"', $output);
        $this->assertStringContainsString('value="el"', $output);
        $this->assertStringContainsString('selected="selected"', $output);
    }

    public function test_render_settings_page_shows_api_test_success_notice(): void
    {
        $bridge = $this->newBridge();
        $_GET = [
            'tab' => 'settings',
            'api_test_result' => 'success',
            'api_test_http_status' => '200',
        ];

        ob_start();
        $bridge->render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('API connection test succeeded', $output);
    }

    public function test_render_settings_page_shows_api_test_missing_credentials_notice(): void
    {
        $bridge = $this->newBridge();
        $_GET = [
            'tab' => 'settings',
            'api_test_result' => 'missing_credentials',
        ];

        ob_start();
        $bridge->render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('API connection test could not run because API settings are incomplete', $output);
    }

    public function test_render_settings_page_uses_stored_api_test_result_when_query_args_missing(): void
    {
        $bridge = $this->newBridge();
        $_GET = [
            'tab' => 'settings',
        ];
        $GLOBALS['mwb_test_state']['options']['merchandillo_api_test_result_0'] = [
            'api_test_result' => 'success',
            'api_test_http_status' => '200',
        ];

        ob_start();
        $bridge->render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-success', $output);
        $this->assertStringContainsString('API connection test succeeded', $output);
        $this->assertSame([], $GLOBALS['mwb_test_state']['options']['merchandillo_api_test_result_0']);
    }
}
