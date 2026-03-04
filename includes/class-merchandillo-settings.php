<?php

final class Merchandillo_Settings implements Merchandillo_Settings_Interface
{
    private const API_BASE_URL_MODE_LOCAL_DEV = 'local_dev';
    private const API_BASE_URL_MODE_MERCHANDILLO = 'merchandillo_com';
    private const MERCHANDILLO_API_BASE_URL = 'https://data.merchandillo.com';

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
            'api_base_url' => self::MERCHANDILLO_API_BASE_URL,
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

        $incomingMode = isset($incoming['api_base_url_mode'])
            ? sanitize_key((string) $incoming['api_base_url_mode'])
            : '';
        if ('' === $incomingMode && isset($incoming['api_base_url'])) {
            $incomingMode = $this->detect_api_base_url_mode((string) $incoming['api_base_url']);
        }
        if (!in_array($incomingMode, [self::API_BASE_URL_MODE_LOCAL_DEV, self::API_BASE_URL_MODE_MERCHANDILLO], true)) {
            $incomingMode = $this->detect_api_base_url_mode((string) ($existing['api_base_url'] ?? ''));
        }

        if (self::API_BASE_URL_MODE_MERCHANDILLO === $incomingMode) {
            $apiBaseUrl = self::MERCHANDILLO_API_BASE_URL;
        } else {
            $incomingLocalUrl = trim((string) ($incoming['api_base_url_local'] ?? ''));
            if ('' === $incomingLocalUrl && isset($incoming['api_base_url'])) {
                // Backwards compatibility with older settings forms.
                $incomingLocalUrl = trim((string) $incoming['api_base_url']);
            }
            if ('' === $incomingLocalUrl && $this->is_local_dev_api_base_url((string) ($existing['api_base_url'] ?? ''))) {
                $incomingLocalUrl = (string) $existing['api_base_url'];
            }

            $apiBaseUrl = $this->sanitize_local_dev_api_base_url($incomingLocalUrl);
            if ('' === $apiBaseUrl) {
                $fallbackLocalUrl = $this->sanitize_local_dev_api_base_url((string) ($existing['api_base_url'] ?? ''));
                $apiBaseUrl = '' !== $fallbackLocalUrl ? $fallbackLocalUrl : 'http://localhost:8787';

                if (function_exists('add_settings_error')) {
                    add_settings_error(
                        $this->optionName,
                        'invalid_local_api_base_url',
                        __(
                            'Local Dev URL must be in the format http://host.docker.internal:{port} or http://localhost:{port}.',
                            'merchandillo-woocommerce-bridge'
                        ),
                        'error'
                    );
                }
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

    private function detect_api_base_url_mode(string $url): string
    {
        return $this->is_local_dev_api_base_url($url)
            ? self::API_BASE_URL_MODE_LOCAL_DEV
            : self::API_BASE_URL_MODE_MERCHANDILLO;
    }

    private function is_local_dev_api_base_url(string $value): bool
    {
        return '' !== $this->sanitize_local_dev_api_base_url($value);
    }

    private function sanitize_local_dev_api_base_url(string $value): string
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
