<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Merchandillo_WooCommerce_Bridge
{
    private const OPTION_NAME = 'merchandillo_sync_options';
    private const CRON_HOOK = 'merchandillo_sync_order_event';
    private const LOG_SOURCE = 'merchandillo-woocommerce-bridge';
    private const SETTINGS_PAGE_SLUG = 'merchandillo-woocommerce-bridge';
    private const LOG_ACTION_KEY = 'merchandillo_logs_action';
    private const LOG_NONCE_ACTION = 'merchandillo_logs_action';

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        if (!get_option(self::OPTION_NAME)) {
            add_option(self::OPTION_NAME, self::default_settings());
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'bootstrap']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_log_actions']);
        add_action(self::CRON_HOOK, [$this, 'sync_order_now'], 10, 1);
        add_filter('plugin_action_links_' . plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), [$this, 'add_settings_link']);
    }

    public function bootstrap(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_new_order', [$this, 'queue_order_sync'], 20, 1);
        add_action('woocommerce_update_order', [$this, 'queue_order_sync'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 20, 4);
    }

    /**
     * @return array<string,string>
     */
    private static function default_settings(): array
    {
        return [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => '',
            'api_secret' => '',
            'log_errors' => '1',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_settings(): array
    {
        $raw = get_option(self::OPTION_NAME, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        /** @var array<string,string> $settings */
        $settings = wp_parse_args($raw, self::default_settings());

        return $settings;
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            __('Merchandillo Sync', 'merchandillo-woocommerce-bridge'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'merchandillo_sync_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => self::default_settings(),
            ]
        );

        add_settings_section(
            'merchandillo_sync_main',
            __('Platform Credentials', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_settings_section_description'],
            self::SETTINGS_PAGE_SLUG
        );

        add_settings_field(
            'enabled',
            __('Enable Sync', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_enabled_field'],
            self::SETTINGS_PAGE_SLUG,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_base_url',
            __('API Base URL', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_base_url_field'],
            self::SETTINGS_PAGE_SLUG,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_key_field'],
            self::SETTINGS_PAGE_SLUG,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'api_secret',
            __('API Secret', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_api_secret_field'],
            self::SETTINGS_PAGE_SLUG,
            'merchandillo_sync_main'
        );

        add_settings_field(
            'log_errors',
            __('Log Errors', 'merchandillo-woocommerce-bridge'),
            [$this, 'render_log_errors_field'],
            self::SETTINGS_PAGE_SLUG,
            'merchandillo_sync_main'
        );
    }

    /**
     * @param mixed $input
     * @return array<string,string>
     */
    public function sanitize_settings($input): array
    {
        $existing = $this->get_settings();
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

        return [
            'enabled' => !empty($incoming['enabled']) ? '1' : '0',
            'api_base_url' => $apiBaseUrl,
            'api_key' => sanitize_text_field($apiKey),
            'api_secret' => sanitize_text_field($apiSecret),
            'log_errors' => !empty($incoming['log_errors']) ? '1' : '0',
        ];
    }

    public function handle_log_actions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key((string) wp_unslash($_REQUEST['page'])) : '';
        if (self::SETTINGS_PAGE_SLUG !== $page) {
            return;
        }

        $action = isset($_REQUEST[self::LOG_ACTION_KEY]) ? sanitize_key((string) wp_unslash($_REQUEST[self::LOG_ACTION_KEY])) : '';
        if ('' === $action) {
            return;
        }

        check_admin_referer(self::LOG_NONCE_ACTION);

        if ('export' === $action) {
            $this->export_logs();
            exit;
        }

        if ('clear' !== $action) {
            return;
        }

        $result = $this->clear_log_files();
        $redirectUrl = add_query_arg(
            [
                'page' => self::SETTINGS_PAGE_SLUG,
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = $this->get_current_tab();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Merchandillo Bridge for WooCommerce', 'merchandillo-woocommerce-bridge') . '</h1>';
        $this->render_navigation_tabs($tab);
        $this->render_logs_notice();

        if ('logs' === $tab) {
            $this->render_logs_tab();
        } else {
            $this->render_settings_tab();
        }

        echo '<p class="description" style="margin-top:16px;">';
        echo wp_kses(
            sprintf(
                __('Need an account? <a href="%s" target="_blank" rel="noopener noreferrer">Register at Merchandillo.com</a>.', 'merchandillo-woocommerce-bridge'),
                esc_url('https://merchandillo.com')
            ),
            [
                'a' => [
                    'href' => [],
                    'target' => [],
                    'rel' => [],
                ],
            ]
        );
        echo '</p>';
        echo '</div>';
    }

    private function render_navigation_tabs(string $activeTab): void
    {
        $baseUrl = $this->get_settings_page_url();
        $tabs = [
            'settings' => __('Settings', 'merchandillo-woocommerce-bridge'),
            'logs' => __('Logs', 'merchandillo-woocommerce-bridge'),
        ];

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tabKey => $tabLabel) {
            $tabUrl = 'settings' === $tabKey ? $baseUrl : add_query_arg('tab', $tabKey, $baseUrl);
            $className = 'nav-tab' . ('settings' === $tabKey && 'settings' === $activeTab ? ' nav-tab-active' : '') . ('logs' === $tabKey && 'logs' === $activeTab ? ' nav-tab-active' : '');
            echo '<a href="' . esc_url($tabUrl) . '" class="' . esc_attr($className) . '">' . esc_html($tabLabel) . '</a>';
        }
        echo '</h2>';
    }

    private function render_settings_tab(): void
    {
        echo '<form method="post" action="options.php">';
        settings_fields('merchandillo_sync_settings_group');
        do_settings_sections(self::SETTINGS_PAGE_SLUG);
        submit_button();
        echo '</form>';
    }

    private function render_logs_notice(): void
    {
        $cleared = isset($_GET['logs_cleared']) ? absint((string) wp_unslash($_GET['logs_cleared'])) : 0;
        $failed = isset($_GET['logs_failed']) ? absint((string) wp_unslash($_GET['logs_failed'])) : 0;
        if ($cleared <= 0 && $failed <= 0 && !isset($_GET['logs_cleared'])) {
            return;
        }

        if ($failed > 0) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html(
                sprintf(
                    __('Removed %1$d log file(s), but %2$d could not be deleted.', 'merchandillo-woocommerce-bridge'),
                    $cleared,
                    $failed
                )
            );
            echo '</p></div>';
            return;
        }

        if ($cleared > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(
                sprintf(
                    __('Removed %d log file(s).', 'merchandillo-woocommerce-bridge'),
                    $cleared
                )
            );
            echo '</p></div>';
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>';
        echo esc_html__('No plugin log files were found to clear.', 'merchandillo-woocommerce-bridge');
        echo '</p></div>';
    }

    private function get_current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'settings';

        return in_array($tab, ['settings', 'logs'], true) ? $tab : 'settings';
    }

    private function get_settings_page_url(array $extraArgs = []): string
    {
        return add_query_arg(
            array_merge(
                [
                    'page' => self::SETTINGS_PAGE_SLUG,
                ],
                $extraArgs
            ),
            admin_url('options-general.php')
        );
    }

    public function render_settings_section_description(): void
    {
        echo '<p>' . esc_html__('Configure the API credentials for pushing order updates to Merchandillo. Failed sync attempts are logged and never break WooCommerce checkout. Use the Logs tab to inspect, export, and clear plugin logs.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_enabled_field(): void
    {
        $settings = $this->get_settings();
        $checked = '1' === (string) $settings['enabled'];

        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[enabled]" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html__('Queue order sync on create/update/status change', 'merchandillo-woocommerce-bridge');
        echo '</label>';
    }

    public function render_api_base_url_field(): void
    {
        $settings = $this->get_settings();
        $value = (string) $settings['api_base_url'];

        echo '<input type="url" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_base_url]" value="' . esc_attr($value) . '" placeholder="https://data.merchandillo.com" />';
        echo '<p class="description">' . esc_html__('Example: https://data.merchandillo.com', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_api_key_field(): void
    {
        $settings = $this->get_settings();
        $value = (string) $settings['api_key'];

        echo '<input type="text" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_key]" value="' . esc_attr($value) . '" autocomplete="off" />';
    }

    public function render_api_secret_field(): void
    {
        $settings = $this->get_settings();
        $hasSecret = '' !== (string) $settings['api_secret'];

        echo '<input type="password" class="regular-text code" name="' . esc_attr(self::OPTION_NAME) . '[api_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr($hasSecret ? '****************' : '') . '" />';
        echo '<p class="description">' . esc_html__('Leave empty to keep the current secret.', 'merchandillo-woocommerce-bridge') . '</p>';
    }

    public function render_log_errors_field(): void
    {
        $settings = $this->get_settings();
        $checked = '1' === (string) $settings['log_errors'];

        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[log_errors]" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html__('Write sync failures to WooCommerce logs', 'merchandillo-woocommerce-bridge');
        echo '</label>';
    }

    private function render_logs_tab(): void
    {
        $logFiles = $this->get_log_files();
        $filters = $this->get_log_filters($logFiles);
        $entries = $this->get_filtered_log_entries(
            $logFiles,
            $filters['file'],
            $filters['level'],
            $filters['search'],
            (int) $filters['limit']
        );

        $logsTabUrl = $this->get_settings_page_url(['tab' => 'logs']);
        $lastHundredUrl = add_query_arg(
            [
                'tab' => 'logs',
                'log_file' => 'all',
                'log_level' => '',
                'log_search' => '',
                'log_limit' => '100',
            ],
            $logsTabUrl
        );
        $exportUrl = wp_nonce_url(
            add_query_arg(
                [
                    'tab' => 'logs',
                    self::LOG_ACTION_KEY => 'export',
                    'log_file' => $filters['file'],
                    'log_level' => $filters['level'],
                    'log_search' => $filters['search'],
                    'log_limit' => (string) $filters['limit'],
                ],
                $logsTabUrl
            ),
            self::LOG_NONCE_ACTION
        );

        echo '<p>' . esc_html__('Inspect, filter, export, and clear plugin log files generated by sync attempts.', 'merchandillo-woocommerce-bridge') . '</p>';

        echo '<form method="get" action="' . esc_url(admin_url('options-general.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SETTINGS_PAGE_SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="logs" />';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';

        echo '<p style="margin:0;">';
        echo '<label for="merchandillo-log-file">' . esc_html__('Log File', 'merchandillo-woocommerce-bridge') . '</label><br />';
        echo '<select id="merchandillo-log-file" name="log_file">';
        echo '<option value="all"' . selected($filters['file'], 'all', false) . '>' . esc_html__('All files', 'merchandillo-woocommerce-bridge') . '</option>';
        foreach ($logFiles as $fileName => $path) {
            $fileLabel = $this->format_log_file_option_label($fileName, $path);
            echo '<option value="' . esc_attr($fileName) . '"' . selected($filters['file'], $fileName, false) . '>' . esc_html($fileLabel) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p style="margin:0;">';
        echo '<label for="merchandillo-log-level">' . esc_html__('Level', 'merchandillo-woocommerce-bridge') . '</label><br />';
        echo '<select id="merchandillo-log-level" name="log_level">';
        echo '<option value=""' . selected($filters['level'], '', false) . '>' . esc_html__('All levels', 'merchandillo-woocommerce-bridge') . '</option>';
        foreach ($this->get_allowed_log_levels() as $level) {
            echo '<option value="' . esc_attr($level) . '"' . selected($filters['level'], $level, false) . '>' . esc_html(strtoupper($level)) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p style="margin:0;">';
        echo '<label for="merchandillo-log-search">' . esc_html__('Contains Text', 'merchandillo-woocommerce-bridge') . '</label><br />';
        echo '<input id="merchandillo-log-search" type="text" name="log_search" value="' . esc_attr($filters['search']) . '" class="regular-text" />';
        echo '</p>';

        echo '<p style="margin:0;">';
        echo '<label for="merchandillo-log-limit">' . esc_html__('Line Limit', 'merchandillo-woocommerce-bridge') . '</label><br />';
        echo '<input id="merchandillo-log-limit" type="number" min="1" max="5000" step="1" name="log_limit" value="' . esc_attr((string) $filters['limit']) . '" style="width:110px;" />';
        echo '</p>';

        echo '<p style="margin:0;">';
        submit_button(__('Apply Filters', 'merchandillo-woocommerce-bridge'), 'secondary', 'submit', false);
        echo '</p>';

        echo '</div>';
        echo '</form>';

        echo '<p>';
        echo '<a href="' . esc_url($lastHundredUrl) . '" class="button">' . esc_html__('Show Last 100 Lines', 'merchandillo-woocommerce-bridge') . '</a> ';
        echo '<a href="' . esc_url($exportUrl) . '" class="button">' . esc_html__('Export Filtered Logs', 'merchandillo-woocommerce-bridge') . '</a> ';
        echo '<form method="post" action="' . esc_url($logsTabUrl) . '" style="display:inline-block;">';
        wp_nonce_field(self::LOG_NONCE_ACTION);
        echo '<input type="hidden" name="' . esc_attr(self::LOG_ACTION_KEY) . '" value="clear" />';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SETTINGS_PAGE_SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="logs" />';
        submit_button(
            __('Clear Plugin Logs', 'merchandillo-woocommerce-bridge'),
            'delete',
            'submit',
            false,
            [
                'onclick' => "return confirm('" . esc_js(__('Are you sure you want to delete all plugin log files?', 'merchandillo-woocommerce-bridge')) . "');",
            ]
        );
        echo '</form>';
        echo '</p>';

        echo '<p class="description">';
        echo esc_html(
            sprintf(
                __('Showing %1$d entry/entries with the current filters (limit: %2$d).', 'merchandillo-woocommerce-bridge'),
                count($entries),
                (int) $filters['limit']
            )
        );
        echo '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:21%;">' . esc_html__('Timestamp', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th style="width:10%;">' . esc_html__('Level', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th>' . esc_html__('Log Line', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '<th style="width:21%;">' . esc_html__('File', 'merchandillo-woocommerce-bridge') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if (empty($entries)) {
            echo '<tr><td colspan="4">' . esc_html__('No log entries matched the current filters.', 'merchandillo-woocommerce-bridge') . '</td></tr>';
        } else {
            foreach (array_reverse($entries) as $entry) {
                $timestamp = '' === $entry['timestamp'] ? '—' : $entry['timestamp'];
                $level = '' === $entry['level'] ? '—' : strtoupper($entry['level']);
                echo '<tr>';
                echo '<td><code>' . esc_html($timestamp) . '</code></td>';
                echo '<td><strong>' . esc_html($level) . '</strong></td>';
                echo '<td><code style="white-space:pre-wrap;word-break:break-word;">' . esc_html($entry['line']) . '</code></td>';
                echo '<td><code>' . esc_html($entry['file']) . '</code></td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * @param array<string,string> $logFiles
     * @return array{file:string,level:string,search:string,limit:int}
     */
    private function get_log_filters(array $logFiles): array
    {
        $file = isset($_GET['log_file']) ? sanitize_file_name((string) wp_unslash($_GET['log_file'])) : 'all';
        if ('all' !== $file && !isset($logFiles[$file])) {
            $file = 'all';
        }

        $level = isset($_GET['log_level']) ? strtolower(sanitize_key((string) wp_unslash($_GET['log_level']))) : '';
        if ('' !== $level && !in_array($level, $this->get_allowed_log_levels(), true)) {
            $level = '';
        }

        $search = isset($_GET['log_search']) ? sanitize_text_field((string) wp_unslash($_GET['log_search'])) : '';

        $limit = isset($_GET['log_limit']) ? absint((string) wp_unslash($_GET['log_limit'])) : 100;
        if ($limit <= 0) {
            $limit = 100;
        }
        $limit = min($limit, 5000);

        return [
            'file' => $file,
            'level' => $level,
            'search' => $search,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function get_allowed_log_levels(): array
    {
        return ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    }

    /**
     * @return array<string,string>
     */
    private function get_log_files(): array
    {
        $paths = [];
        $uploadDir = wp_upload_dir();
        $baseDir = isset($uploadDir['basedir']) ? (string) $uploadDir['basedir'] : '';

        if ('' !== $baseDir) {
            $logDir = trailingslashit($baseDir) . 'wc-logs';
            if (is_dir($logDir)) {
                $matches = glob(trailingslashit($logDir) . self::LOG_SOURCE . '-*.log');
                if (is_array($matches)) {
                    foreach ($matches as $match) {
                        if (is_string($match)) {
                            $paths[] = $match;
                        }
                    }
                }
            }
        }

        if (function_exists('wc_get_log_file_path')) {
            $activePath = (string) wc_get_log_file_path(self::LOG_SOURCE);
            if ('' !== $activePath) {
                $paths[] = $activePath;
            }
        }

        $files = [];
        foreach (array_values(array_unique($paths)) as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }

            $fileName = basename($path);
            $files[$fileName] = $path;
        }

        uksort(
            $files,
            static function (string $left, string $right) use ($files): int {
                $leftPath = isset($files[$left]) ? $files[$left] : '';
                $rightPath = isset($files[$right]) ? $files[$right] : '';
                $leftTime = '' === $leftPath ? 0 : (int) filemtime($leftPath);
                $rightTime = '' === $rightPath ? 0 : (int) filemtime($rightPath);

                if ($leftTime === $rightTime) {
                    return strcmp($right, $left);
                }

                return $rightTime <=> $leftTime;
            }
        );

        return $files;
    }

    /**
     * @param array<string,string> $logFiles
     * @return array<string,string>
     */
    private function resolve_target_log_files(array $logFiles, string $selectedFile): array
    {
        if ('all' === $selectedFile) {
            return array_reverse($logFiles, true);
        }

        if (!isset($logFiles[$selectedFile])) {
            return [];
        }

        return [$selectedFile => $logFiles[$selectedFile]];
    }

    /**
     * @param array<string,string> $logFiles
     * @return array<int,array{file:string,timestamp:string,level:string,line:string}>
     */
    private function get_filtered_log_entries(
        array $logFiles,
        string $selectedFile,
        string $levelFilter,
        string $searchFilter,
        int $lineLimit
    ): array {
        $targetFiles = $this->resolve_target_log_files($logFiles, $selectedFile);
        if (empty($targetFiles) || $lineLimit <= 0) {
            return [];
        }

        $entries = [];
        $sampleSize = max(500, $lineLimit * 8);
        foreach ($targetFiles as $fileName => $path) {
            $lines = $this->read_last_lines($path, $sampleSize);
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ('' === $line) {
                    continue;
                }

                $entry = $this->normalize_log_entry($fileName, $line);
                if ('' !== $levelFilter && $entry['level'] !== $levelFilter) {
                    continue;
                }

                if ('' !== $searchFilter && false === stripos($entry['line'], $searchFilter)) {
                    continue;
                }

                $entries[] = $entry;
            }
        }

        if (count($entries) > $lineLimit) {
            $entries = array_slice($entries, -$lineLimit);
        }

        return $entries;
    }

    /**
     * @return array{file:string,timestamp:string,level:string,line:string}
     */
    private function normalize_log_entry(string $fileName, string $line): array
    {
        $entry = [
            'file' => $fileName,
            'timestamp' => '',
            'level' => '',
            'line' => $line,
        ];

        if (preg_match('/^\[?([0-9]{4}-[0-9]{2}-[0-9]{2}[^\]\s]*)\]?/', $line, $timeMatch)) {
            $entry['timestamp'] = (string) ($timeMatch[1] ?? '');
        }

        if (preg_match('/\b(emergency|alert|critical|error|warning|notice|info|debug)\b/i', $line, $levelMatch)) {
            $entry['level'] = strtolower((string) ($levelMatch[1] ?? ''));
        }

        return $entry;
    }

    /**
     * @return array<int,string>
     */
    private function read_last_lines(string $filePath, int $lineLimit): array
    {
        if ($lineLimit <= 0 || !is_readable($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            return [];
        }

        if (0 !== fseek($handle, 0, SEEK_END)) {
            fclose($handle);
            return [];
        }

        $fileSize = ftell($handle);
        if (false === $fileSize || $fileSize <= 0) {
            fclose($handle);
            return [];
        }

        $buffer = '';
        $position = 0;
        $chunkSize = 8192;

        while ($position < $fileSize && substr_count($buffer, "\n") <= $lineLimit) {
            $bytesToRead = min($chunkSize, $fileSize - $position);
            $position += $bytesToRead;

            if (0 !== fseek($handle, -$position, SEEK_END)) {
                break;
            }

            $chunk = fread($handle, $bytesToRead);
            if (false === $chunk || '' === $chunk) {
                break;
            }

            $buffer = $chunk . $buffer;
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $buffer);
        if (!is_array($lines)) {
            return [];
        }

        if (!empty($lines) && '' === end($lines)) {
            array_pop($lines);
        }

        if (empty($lines)) {
            return [];
        }

        return array_slice($lines, -$lineLimit);
    }

    /**
     * @return array{deleted:int,failed:int}
     */
    private function clear_log_files(): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($this->get_log_files() as $path) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }

    private function export_logs(): void
    {
        $logFiles = $this->get_log_files();
        $filters = $this->get_log_filters($logFiles);
        $entries = $this->get_filtered_log_entries(
            $logFiles,
            $filters['file'],
            $filters['level'],
            $filters['search'],
            (int) $filters['limit']
        );

        $fileName = 'merchandillo-logs-' . gmdate('Ymd-His') . '.txt';
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        echo "Merchandillo WooCommerce Bridge Logs\n";
        echo 'Generated (UTC): ' . gmdate('c') . "\n";
        echo 'File filter: ' . ('all' === $filters['file'] ? 'all files' : $filters['file']) . "\n";
        echo 'Level filter: ' . ('' === $filters['level'] ? 'all' : $filters['level']) . "\n";
        echo 'Search filter: ' . ('' === $filters['search'] ? '-' : $filters['search']) . "\n";
        echo 'Line limit: ' . (string) $filters['limit'] . "\n\n";

        if (empty($entries)) {
            echo "No log entries matched the current filters.\n";
            return;
        }

        foreach ($entries as $entry) {
            $level = '' === $entry['level'] ? 'UNKNOWN' : strtoupper($entry['level']);
            echo '[' . $entry['file'] . '] ';
            if ('' !== $entry['timestamp']) {
                echo '[' . $entry['timestamp'] . '] ';
            }
            echo '[' . $level . '] ' . $entry['line'] . "\n";
        }
    }

    private function format_log_file_option_label(string $fileName, string $path): string
    {
        $size = is_file($path) ? size_format((int) filesize($path)) : '0 B';
        $updatedAt = is_file($path) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) filemtime($path)) : '';
        if ('' === $updatedAt) {
            return $fileName . ' (' . $size . ')';
        }

        return $fileName . ' (' . $size . ', ' . $updatedAt . ')';
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function add_settings_link(array $links): array
    {
        $url = $this->get_settings_page_url();
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'merchandillo-woocommerce-bridge') . '</a>';

        return $links;
    }

    public function handle_status_change(int $orderId, string $oldStatus, string $newStatus): void
    {
        unset($oldStatus, $newStatus);
        $this->queue_order_sync($orderId);
    }

    public function queue_order_sync(int $orderId): void
    {
        $orderId = absint($orderId);
        if ($orderId <= 0) {
            return;
        }

        $settings = $this->get_settings();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        if ('' === (string) $settings['api_base_url'] || '' === (string) $settings['api_key'] || '' === (string) $settings['api_secret']) {
            $this->log('warning', 'There was a problem syncing that order because API settings are missing.', ['order_id' => $orderId]);
            return;
        }

        if (false !== wp_next_scheduled(self::CRON_HOOK, [$orderId])) {
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 5, self::CRON_HOOK, [$orderId]);
        if (false === $scheduled) {
            // Fall back to immediate execution if event scheduling fails.
            $this->sync_order_now($orderId);
        }
    }

    public function sync_order_now(int $orderId): void
    {
        $orderId = absint($orderId);
        if ($orderId <= 0) {
            return;
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $settings = $this->get_settings();
        if ('1' !== (string) $settings['enabled']) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            $this->log('warning', 'There was a problem syncing that order because it could not be loaded.', ['order_id' => $orderId]);
            return;
        }

        $endpoint = rtrim((string) $settings['api_base_url'], '/') . '/api/woocommerce/orders';

        $payload = $this->build_order_payload($order);

        $response = wp_remote_post(
            $endpoint,
            [
                'method' => 'POST',
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => (string) $settings['api_key'],
                    'X-API-Secret' => (string) $settings['api_secret'],
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            $this->log('error', 'There was a problem syncing that order to Merchandillo.', [
                'order_id' => $orderId,
                'error' => $response->get_error_message(),
            ]);
            return;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->log('error', 'There was a problem syncing that order to Merchandillo.', [
                'order_id' => $orderId,
                'http_status' => $statusCode,
                'response_body' => substr((string) wp_remote_retrieve_body($response), 0, 1000),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function build_order_payload(WC_Order $order): array
    {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');

        $subtotal = 0.0;
        $items = [];

        foreach ($order->get_items('line_item') as $lineItem) {
            if (!$lineItem instanceof WC_Order_Item_Product) {
                continue;
            }

            $quantity = max(1, (int) $lineItem->get_quantity());
            $lineTotal = (float) $lineItem->get_total();
            $unitPrice = $lineTotal / $quantity;
            $product = $lineItem->get_product();

            $entry = [
                'product_id' => $product ? (int) $product->get_id() : 0,
                'product_sku' => $product ? (string) $product->get_sku() : '',
                'product_name' => (string) $lineItem->get_name(),
                'quantity' => (int) $lineItem->get_quantity(),
                'price' => round($unitPrice, 2),
                'total' => round($lineTotal, 2),
                'tax_amount' => round((float) $lineItem->get_total_tax(), 2),
            ];

            $options = $this->extract_item_options($lineItem);
            if (!empty($options)) {
                $entry['product_options'] = $options;
            }

            $items[] = $entry;
            $subtotal += (float) $lineItem->get_subtotal();
        }

        $dateCreated = $order->get_date_created();
        $trackingNumber = (string) $order->get_meta('_tracking_number', true);
        $trackingUrl = (string) $order->get_meta('_tracking_url', true);
        $courier = (string) $order->get_meta('_shipping_courier', true);
        $orderNumber = (string) $order->get_order_number();
        if ('' === trim($orderNumber)) {
            $orderNumber = (string) $order->get_id();
        }

        $customerName = trim((string) $order->get_formatted_billing_full_name());
        if ('' === $customerName) {
            $customerName = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        }
        if ('' === $customerName) {
            $customerName = 'Customer #' . (string) $order->get_id();
        }

        return [
            'id' => (int) $order->get_id(),
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'customer_email' => (string) $order->get_billing_email(),
            'customer_phone' => (string) $order->get_billing_phone(),
            'total_amount' => round((float) $order->get_total(), 2),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round((float) $order->get_total_tax(), 2),
            'shipping_amount' => round((float) $order->get_shipping_total(), 2),
            'discount_amount' => round((float) $order->get_discount_total(), 2),
            'currency' => (string) $order->get_currency(),
            'status' => (string) $order->get_status(),
            'payment_method' => (string) $order->get_payment_method_title(),
            'payment_status' => $order->is_paid() ? 'paid' : 'pending',
            'shipping_method' => (string) $order->get_shipping_method(),
            'tracking_number' => $trackingNumber,
            'courier' => $courier,
            'tracking_url' => $trackingUrl,
            'order_date' => $dateCreated ? (string) $dateCreated->date('Y-m-d') : gmdate('Y-m-d'),
            'notes' => (string) $order->get_customer_note(),
            'shipping_address' => $this->map_address($shipping),
            'billing_address' => $this->map_address($billing),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,string>
     */
    private function map_address(array $address): array
    {
        return [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'address_1' => (string) ($address['address_1'] ?? ''),
            'address_2' => (string) ($address['address_2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'postcode' => (string) ($address['postcode'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
            'zone' => (string) ($address['state'] ?? ''),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function extract_item_options(WC_Order_Item_Product $lineItem): array
    {
        $options = [];
        foreach ($lineItem->get_meta_data() as $meta) {
            $key = (string) $meta->key;
            if ('' === $key || 0 === strpos($key, '_')) {
                continue;
            }

            $rawValue = $meta->value;
            if (is_scalar($rawValue)) {
                $value = (string) $rawValue;
            } else {
                $encoded = wp_json_encode($rawValue);
                $value = false === $encoded ? '' : $encoded;
            }

            if ('' === trim($value)) {
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $settings = $this->get_settings();
        if ('1' !== (string) $settings['log_errors']) {
            return;
        }

        $normalizedLevel = in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)
            ? $level
            : 'error';

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log(
                $normalizedLevel,
                $message . (empty($context) ? '' : ' | ' . wp_json_encode($context)),
                ['source' => self::LOG_SOURCE]
            );
            return;
        }

        $line = '[' . self::LOG_SOURCE . '] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . wp_json_encode($context);
        }

        error_log($line);
    }
}
