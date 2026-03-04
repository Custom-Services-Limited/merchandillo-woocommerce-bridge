<?php

declare(strict_types=1);

final class MerchandilloTranslationManagerTest extends MerchandilloTestCase
{
    public function test_register_hooks_adds_gettext_filter(): void
    {
        $manager = $this->newTranslationManager();
        $manager->register_hooks();

        $this->assertCount(1, $GLOBALS['mwb_test_state']['filters']);
        $this->assertSame('gettext', $GLOBALS['mwb_test_state']['filters'][0][0]);
    }

    public function test_translate_gettext_returns_greek_when_language_is_el(): void
    {
        $manager = $this->newTranslationManager();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'el',
            'log_errors' => '1',
        ];

        $translated = $manager->translate_gettext('Settings', 'Settings', 'merchandillo-woocommerce-bridge');

        $this->assertSame('Ρυθμίσεις', $translated);
    }

    public function test_translate_gettext_returns_original_when_language_is_en(): void
    {
        $manager = $this->newTranslationManager();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];

        $translated = $manager->translate_gettext('Settings', 'Settings', 'merchandillo-woocommerce-bridge');

        $this->assertSame('Settings', $translated);
    }

    public function test_translate_gettext_ignores_other_domains(): void
    {
        $manager = $this->newTranslationManager();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'el',
            'log_errors' => '1',
        ];

        $translated = $manager->translate_gettext('Settings', 'Settings', 'other-domain');

        $this->assertSame('Settings', $translated);
    }

    public function test_translate_gettext_honors_latest_ui_language_setting_without_stale_cache(): void
    {
        $manager = $this->newTranslationManager();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'el',
            'log_errors' => '1',
        ];

        $first = $manager->translate_gettext('Settings', 'Settings', 'merchandillo-woocommerce-bridge');
        $this->assertSame('Ρυθμίσεις', $first);

        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options']['ui_language'] = 'en';
        $second = $manager->translate_gettext('Settings', 'Settings', 'merchandillo-woocommerce-bridge');
        $this->assertSame('Settings', $second);
    }
}
