<?php

final class Merchandillo_Github_Updater
{
    private const PLUGIN_SLUG = 'merchandillo-woocommerce-bridge';
    private const UPDATE_URI = 'https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge';
    private const RELEASES_API_URL = 'https://api.github.com/repos/Custom-Services-Limited/merchandillo-woocommerce-bridge/releases/latest';
    private const RELEASES_PAGE_URL = 'https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge/releases';
    private const RELEASE_CACHE_KEY = 'merchandillo_wc_bridge_github_release_v1';
    private const RELEASE_CACHE_TTL = 3600;
    private const REQUEST_TIMEOUT = 3;
    private const MINIMUM_WP_VERSION = '6.0';
    private const MINIMUM_PHP_VERSION = '7.4';
    private const ZIP_ASSET_PATTERN = '/^merchandillo-woocommerce-bridge-.*\.zip$/i';

    /** @var string */
    private $pluginFile;

    public function __construct(?string $pluginFile = null)
    {
        $this->pluginFile = null === $pluginFile
            ? plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE)
            : $pluginFile;
    }

    public function register_hooks(): void
    {
        add_filter('update_plugins_github.com', [$this, 'filter_update_plugins'], 10, 4);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'handle_upgrader_process_complete'], 10, 2);
    }

    /**
     * @param mixed $update
     * @param array<string,mixed> $pluginData
     * @param array<int,string> $locales
     * @return mixed
     */
    public function filter_update_plugins($update, array $pluginData, string $pluginFile, array $locales)
    {
        unset($locales);

        if ($this->pluginFile !== $pluginFile) {
            return $update;
        }

        $release = $this->release_data();
        if (null === $release) {
            return $update;
        }

        $installedVersion = $this->installed_version($pluginData);
        if (!version_compare($release['version'], $installedVersion, '>')) {
            return $update;
        }

        return [
            'id' => self::UPDATE_URI,
            'slug' => self::PLUGIN_SLUG,
            'plugin' => $this->pluginFile,
            'version' => $release['version'],
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $this->plugin_header_value($pluginData, ['RequiresWP', 'Requires at least'], self::MINIMUM_WP_VERSION),
            'requires_php' => $this->plugin_header_value($pluginData, ['RequiresPHP', 'Requires PHP'], self::MINIMUM_PHP_VERSION),
        ];
    }

    /**
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public function filter_plugins_api($result, string $action, $args)
    {
        if ('plugin_information' !== $action || !is_object($args)) {
            return $result;
        }

        $slug = isset($args->slug) ? (string) $args->slug : '';
        if (self::PLUGIN_SLUG !== $slug) {
            return $result;
        }

        $release = $this->release_data();
        $installedVersion = defined('MERCHANDILLO_WC_BRIDGE_VERSION')
            ? (string) MERCHANDILLO_WC_BRIDGE_VERSION
            : '0.0.0';

        $version = null !== $release ? $release['version'] : $installedVersion;
        $downloadLink = null !== $release ? $release['package'] : '';
        $releaseUrl = null !== $release ? $release['url'] : self::RELEASES_PAGE_URL;
        $lastUpdated = null !== $release ? $this->format_last_updated($release['published_at']) : gmdate('Y-m-d H:i:s');

        return (object) [
            'name' => __('Merchandillo Bridge for WooCommerce', 'merchandillo-woocommerce-bridge'),
            'slug' => self::PLUGIN_SLUG,
            'version' => $version,
            'author' => '<a href="https://merchandillo.com">Merchandillo</a>',
            'homepage' => self::UPDATE_URI,
            'requires' => self::MINIMUM_WP_VERSION,
            'requires_php' => self::MINIMUM_PHP_VERSION,
            'last_updated' => $lastUpdated,
            'download_link' => $downloadLink,
            'external' => true,
            'sections' => [
                'description' => __('Sync WooCommerce order changes to Merchandillo via API key/secret without interrupting checkout flows.', 'merchandillo-woocommerce-bridge'),
                'changelog' => $this->render_changelog($release),
                'homepage' => '<a href="' . esc_url($releaseUrl) . '">' . esc_html($releaseUrl) . '</a>',
            ],
        ];
    }

    /**
     * @param mixed $upgrader
     * @param array<string,mixed> $hookExtra
     */
    public function handle_upgrader_process_complete($upgrader, array $hookExtra): void
    {
        unset($upgrader);

        if (($hookExtra['type'] ?? '') !== 'plugin' || ($hookExtra['action'] ?? '') !== 'update') {
            return;
        }

        $updatedPlugins = [];
        if (isset($hookExtra['plugins']) && is_array($hookExtra['plugins'])) {
            foreach ($hookExtra['plugins'] as $plugin) {
                if (is_string($plugin)) {
                    $updatedPlugins[] = $plugin;
                }
            }
        }

        if (isset($hookExtra['plugin']) && is_string($hookExtra['plugin'])) {
            $updatedPlugins[] = $hookExtra['plugin'];
        }

        if (!in_array($this->pluginFile, $updatedPlugins, true)) {
            return;
        }

        if (function_exists('delete_site_transient')) {
            delete_site_transient(self::RELEASE_CACHE_KEY);
        }
    }

    /**
     * @return array{version:string,package:string,url:string,published_at:string,body:string}|null
     */
    private function release_data(): ?array
    {
        if (function_exists('get_site_transient')) {
            $cached = get_site_transient(self::RELEASE_CACHE_KEY);
            $validatedCached = $this->is_valid_release_payload($cached);
            if (null !== $validatedCached) {
                return $validatedCached;
            }
        }

        $release = $this->fetch_release_data();
        if (null === $release) {
            return null;
        }

        if (function_exists('set_site_transient')) {
            set_site_transient(self::RELEASE_CACHE_KEY, $release, self::RELEASE_CACHE_TTL);
        }

        return $release;
    }

    /**
     * @return array{version:string,package:string,url:string,published_at:string,body:string}|null
     */
    private function fetch_release_data(): ?array
    {
        $response = wp_remote_get(
            self::RELEASES_API_URL,
            [
                'timeout' => self::REQUEST_TIMEOUT,
                'redirection' => 1,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'MerchandilloWooCommerceBridgeUpdater/1.0',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($decoded['draft']) || !empty($decoded['prerelease'])) {
            return null;
        }

        $version = $this->normalize_version((string) ($decoded['tag_name'] ?? ''));
        if ('' === $version) {
            return null;
        }

        $packageUrl = $this->release_asset_zip_url($decoded['assets'] ?? []);
        if ('' === $packageUrl) {
            return null;
        }

        return [
            'version' => $version,
            'package' => $packageUrl,
            'url' => isset($decoded['html_url']) && is_string($decoded['html_url']) && '' !== trim($decoded['html_url'])
                ? trim($decoded['html_url'])
                : self::RELEASES_PAGE_URL,
            'published_at' => isset($decoded['published_at']) && is_string($decoded['published_at']) ? $decoded['published_at'] : '',
            'body' => isset($decoded['body']) && is_string($decoded['body']) ? $decoded['body'] : '',
        ];
    }

    /**
     * @param mixed $assets
     */
    private function release_asset_zip_url($assets): string
    {
        if (!is_array($assets)) {
            return '';
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

            if (1 !== preg_match(self::ZIP_ASSET_PATTERN, $name)) {
                continue;
            }

            if ($this->is_http_url($url)) {
                return $url;
            }
        }

        return '';
    }

    private function is_http_url(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function normalize_version(string $value): string
    {
        $version = ltrim(trim($value), "vV \t\n\r\0\x0B");
        if ('' === $version) {
            return '';
        }

        return $version;
    }

    /**
     * @param array<string,mixed> $pluginData
     */
    private function installed_version(array $pluginData): string
    {
        if (isset($pluginData['Version']) && is_string($pluginData['Version']) && '' !== trim($pluginData['Version'])) {
            return trim($pluginData['Version']);
        }

        if (defined('MERCHANDILLO_WC_BRIDGE_VERSION')) {
            return (string) MERCHANDILLO_WC_BRIDGE_VERSION;
        }

        return '0.0.0';
    }

    /**
     * @param array<string,mixed> $pluginData
     * @param array<int,string> $keys
     */
    private function plugin_header_value(array $pluginData, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (!isset($pluginData[$key]) || !is_string($pluginData[$key])) {
                continue;
            }

            $value = trim($pluginData[$key]);
            if ('' !== $value) {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param mixed $value
     * @return array{version:string,package:string,url:string,published_at:string,body:string}|null
     */
    private function is_valid_release_payload($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $requiredKeys = ['version', 'package', 'url', 'published_at', 'body'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $value) || !is_string($value[$key])) {
                return null;
            }
        }

        if ('' === trim($value['version']) || '' === trim($value['package']) || '' === trim($value['url'])) {
            return null;
        }

        return [
            'version' => trim($value['version']),
            'package' => trim($value['package']),
            'url' => trim($value['url']),
            'published_at' => trim($value['published_at']),
            'body' => (string) $value['body'],
        ];
    }

    /**
     * @param array{version:string,package:string,url:string,published_at:string,body:string}|null $release
     */
    private function render_changelog(?array $release): string
    {
        if (null === $release) {
            return esc_html__('Release notes are available on GitHub.', 'merchandillo-woocommerce-bridge');
        }

        $body = trim($release['body']);
        if ('' === $body) {
            return esc_html__('No release notes were published for this release.', 'merchandillo-woocommerce-bridge');
        }

        return nl2br(esc_html($body));
    }

    private function format_last_updated(string $rawDate): string
    {
        $timestamp = strtotime($rawDate);
        if (false === $timestamp) {
            return gmdate('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
