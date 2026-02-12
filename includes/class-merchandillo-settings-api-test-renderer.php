<?php

final class Merchandillo_Settings_Api_Test_Renderer
{
    /** @var string */
    private $pageSlug;

    /** @var string */
    private $actionKey;

    /** @var string */
    private $nonceAction;

    public function __construct(string $pageSlug, string $actionKey, string $nonceAction)
    {
        $this->pageSlug = $pageSlug;
        $this->actionKey = $actionKey;
        $this->nonceAction = $nonceAction;
    }

    /**
     * @param array<string,string> $settings
     */
    public function render(array $settings): void
    {
        if (!$this->can_test_connection($settings)) {
            echo '<p class="description mwb-help">';
            echo esc_html__('Enter API Base URL, API Key, and API Secret to enable API connection tests.', 'merchandillo-woocommerce-bridge');
            echo '</p>';
            return;
        }

        $actionUrl = add_query_arg(
            [
                'page' => $this->pageSlug,
                'tab' => 'settings',
            ],
            admin_url('options-general.php')
        );

        echo '<div class="mwb-form-actions">';
        echo '<form method="post" action="' . esc_url($actionUrl) . '" class="mwb-inline-form">';
        wp_nonce_field($this->nonceAction);
        echo '<input type="hidden" name="page" value="' . esc_attr($this->pageSlug) . '" />';
        echo '<input type="hidden" name="tab" value="settings" />';
        echo '<input type="hidden" name="' . esc_attr($this->actionKey) . '" value="test_connection" />';
        echo '<button type="submit" class="button mwb-btn-secondary">' . esc_html__('Test API Connection', 'merchandillo-woocommerce-bridge') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * @param array<string,string> $settings
     */
    private function can_test_connection(array $settings): bool
    {
        return '' !== (string) $settings['api_base_url']
            && '' !== (string) $settings['api_key']
            && '' !== (string) $settings['api_secret'];
    }
}
