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

        $incomingApiBaseUrl = trim((string) ($incoming['api_base_url'] ?? ''));
        $apiBaseUrl = $this->sanitize_api_base_url($incomingApiBaseUrl);
        if ('' === $apiBaseUrl && '' !== $incomingApiBaseUrl) {
            $fallbackApiBaseUrl = $this->sanitize_api_base_url((string) ($existing['api_base_url'] ?? ''));
            if ('' === $fallbackApiBaseUrl) {
                $fallbackApiBaseUrl = (string) $this->defaults()['api_base_url'];
            }
            $apiBaseUrl = $fallbackApiBaseUrl;

            if (function_exists('add_settings_error')) {
                add_settings_error(
                    $this->optionName,
                    'invalid_api_base_url',
                    __(
                        'API Base URL must be one of: https://data.merchandillo.com, http://host.docker.internal:{port}, or http://localhost:{port}.',
                        'merchandillo-woocommerce-bridge'
                    ),
                    'error'
                );
            }
        }

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

    private function sanitize_api_base_url(string $value): string
    {
        $value = esc_url_raw(trim($value));
        if ('' === $value) {
            return '';
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        if ('' !== $path && '/' !== $path) {
            return '';
        }

        if ('https' === $scheme && 'data.merchandillo.com' === $host) {
            if (isset($parts['port']) && 443 !== (int) $parts['port']) {
                return '';
            }
            return 'https://data.merchandillo.com';
        }

        if ('http' === $scheme && in_array($host, ['host.docker.internal', 'localhost'], true)) {
            $port = isset($parts['port']) ? (int) $parts['port'] : 0;
            if ($port < 1 || $port > 65535) {
                return '';
            }

            return 'http://' . $host . ':' . $port;
        }

        return '';
    }
}
