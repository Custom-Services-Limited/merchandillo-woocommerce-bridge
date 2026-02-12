<?php

final class Merchandillo_Settings implements Merchandillo_Settings_Interface
{
    /** @var string */
    private $optionName;

    public function __construct(string $optionName)
    {
        $this->optionName = $optionName;
    }

    /**
     * @return array<string,string>
     */
    public static function default_settings(): array
    {
        return [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'ui_language' => 'en',
            'log_errors' => '1',
        ];
    }

    public function option_name(): string
    {
        return $this->optionName;
    }

    /**
     * @return array<string,string>
     */
    public function defaults(): array
    {
        return self::default_settings();
    }

    /**
     * @return array<string,string>
     */
    public function get(): array
    {
        $raw = get_option($this->optionName, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        /** @var array<string,string> $settings */
        $settings = wp_parse_args($raw, $this->defaults());

        return $settings;
    }

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize($input): array
    {
        $existing = $this->get();
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

        $uiLanguage = sanitize_key((string) ($incoming['ui_language'] ?? (string) $existing['ui_language']));
        if (!in_array($uiLanguage, ['en', 'el'], true)) {
            $uiLanguage = 'en';
        }

        return [
            'enabled' => !empty($incoming['enabled']) ? '1' : '0',
            'api_base_url' => $apiBaseUrl,
            'api_key' => sanitize_text_field($apiKey),
            'api_secret' => sanitize_text_field($apiSecret),
            'ui_language' => $uiLanguage,
            'log_errors' => !empty($incoming['log_errors']) ? '1' : '0',
        ];
    }
}
