<?php

declare(strict_types=1);

final class MerchandilloGithubUpdaterTest extends MerchandilloTestCase
{
    public function test_register_hooks_registers_expected_filters_and_actions(): void
    {
        $updater = new Merchandillo_Github_Updater();
        $updater->register_hooks();

        $filtersByHook = [];
        foreach ($GLOBALS['mwb_test_state']['filters'] as $filter) {
            $filtersByHook[(string) $filter[0]] = (int) $filter[3];
        }

        $actionsByHook = [];
        foreach ($GLOBALS['mwb_test_state']['actions'] as $action) {
            $actionsByHook[(string) $action[0]] = (int) $action[3];
        }

        $this->assertArrayHasKey('update_plugins_github.com', $filtersByHook);
        $this->assertArrayHasKey('plugins_api', $filtersByHook);
        $this->assertArrayHasKey('upgrader_process_complete', $actionsByHook);
        $this->assertSame(4, $filtersByHook['update_plugins_github.com']);
        $this->assertSame(3, $filtersByHook['plugins_api']);
        $this->assertSame(2, $actionsByHook['upgrader_process_complete']);
    }

    public function test_filter_update_plugins_returns_payload_for_newer_release(): void
    {
        $GLOBALS['mwb_test_state']['remote_get_response'] = $this->release_response('v0.3.0');
        $updater = new Merchandillo_Github_Updater();

        $update = $updater->filter_update_plugins(
            false,
            ['Version' => '0.2.0', 'RequiresWP' => '6.0', 'RequiresPHP' => '7.4'],
            plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            []
        );

        $this->assertIsArray($update);
        $this->assertSame('merchandillo-woocommerce-bridge', $update['slug']);
        $this->assertSame('0.3.0', $update['version']);
        $this->assertSame('0.3.0', $update['new_version']);
        $this->assertSame('https://downloads.example.test/merchandillo-woocommerce-bridge-0.3.0.zip', $update['package']);
        $this->assertSame(1, count($GLOBALS['mwb_test_state']['remote_get_requests']));
        $this->assertSame(3, $GLOBALS['mwb_test_state']['remote_get_requests'][0][1]['timeout']);
        $this->assertSame(1, $GLOBALS['mwb_test_state']['remote_get_requests'][0][1]['redirection']);
    }

    public function test_filter_update_plugins_returns_original_when_release_is_not_newer(): void
    {
        $original = ['keep' => 'value'];
        $GLOBALS['mwb_test_state']['remote_get_response'] = $this->release_response('v0.2.0');
        $updater = new Merchandillo_Github_Updater();

        $update = $updater->filter_update_plugins(
            $original,
            ['Version' => '0.2.0'],
            plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            []
        );

        $this->assertSame($original, $update);
    }

    public function test_filter_update_plugins_returns_original_on_remote_error(): void
    {
        $original = ['keep' => 'value'];
        $GLOBALS['mwb_test_state']['remote_get_response'] = new WP_Error('network_error', 'timeout');
        $updater = new Merchandillo_Github_Updater();

        $update = $updater->filter_update_plugins(
            $original,
            ['Version' => '0.2.0'],
            plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            []
        );

        $this->assertSame($original, $update);
        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_get_requests']);
    }

    public function test_filter_update_plugins_returns_original_when_release_json_is_invalid(): void
    {
        $original = ['keep' => 'value'];
        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 200],
            'body' => '{invalid-json',
        ];
        $updater = new Merchandillo_Github_Updater();

        $update = $updater->filter_update_plugins(
            $original,
            ['Version' => '0.2.0'],
            plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            []
        );

        $this->assertSame($original, $update);
    }

    public function test_filter_update_plugins_returns_original_when_release_zip_asset_is_missing(): void
    {
        $original = ['keep' => 'value'];
        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 200],
            'body' => wp_json_encode(
                [
                    'tag_name' => 'v0.3.0',
                    'html_url' => 'https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge/releases/tag/v0.3.0',
                    'assets' => [
                        [
                            'name' => 'source-code.zip',
                            'browser_download_url' => 'https://downloads.example.test/source-code.zip',
                        ],
                    ],
                ]
            ),
        ];
        $updater = new Merchandillo_Github_Updater();

        $update = $updater->filter_update_plugins(
            $original,
            ['Version' => '0.2.0'],
            plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE),
            []
        );

        $this->assertSame($original, $update);
    }

    public function test_filter_plugins_api_returns_plugin_information_for_matching_slug(): void
    {
        $GLOBALS['mwb_test_state']['remote_get_response'] = $this->release_response('v0.3.0');
        $updater = new Merchandillo_Github_Updater();

        $result = $updater->filter_plugins_api(false, 'plugin_information', (object) ['slug' => 'merchandillo-woocommerce-bridge']);

        $this->assertIsObject($result);
        $this->assertSame('merchandillo-woocommerce-bridge', $result->slug);
        $this->assertSame('0.3.0', $result->version);
        $this->assertSame('https://downloads.example.test/merchandillo-woocommerce-bridge-0.3.0.zip', $result->download_link);
        $this->assertIsArray($result->sections);
        $this->assertArrayHasKey('changelog', $result->sections);
    }

    public function test_filter_plugins_api_ignores_other_slug(): void
    {
        $original = ['keep' => 'value'];
        $updater = new Merchandillo_Github_Updater();

        $result = $updater->filter_plugins_api($original, 'plugin_information', (object) ['slug' => 'other-plugin']);

        $this->assertSame($original, $result);
        $this->assertCount(0, $GLOBALS['mwb_test_state']['remote_get_requests']);
    }

    public function test_release_data_is_cached_after_first_request(): void
    {
        $GLOBALS['mwb_test_state']['remote_get_response'] = $this->release_response('v0.3.0');
        $updater = new Merchandillo_Github_Updater();

        $updater->filter_update_plugins(false, ['Version' => '0.2.0'], plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), []);
        $updater->filter_update_plugins(false, ['Version' => '0.2.0'], plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE), []);

        $this->assertCount(1, $GLOBALS['mwb_test_state']['remote_get_requests']);
        $this->assertArrayHasKey('merchandillo_wc_bridge_github_release_v1', $GLOBALS['mwb_test_state']['site_transients']);
    }

    public function test_upgrader_process_complete_clears_release_cache_for_this_plugin(): void
    {
        $GLOBALS['mwb_test_state']['site_transients']['merchandillo_wc_bridge_github_release_v1'] = [
            'version' => '0.3.0',
            'package' => 'https://downloads.example.test/merchandillo-woocommerce-bridge-0.3.0.zip',
            'url' => 'https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge/releases/tag/v0.3.0',
            'published_at' => '2026-03-01T12:00:00Z',
            'body' => 'Release notes',
        ];

        $updater = new Merchandillo_Github_Updater();
        $updater->handle_upgrader_process_complete(
            null,
            [
                'action' => 'update',
                'type' => 'plugin',
                'plugins' => [plugin_basename(MERCHANDILLO_WC_BRIDGE_FILE)],
            ]
        );

        $this->assertArrayNotHasKey('merchandillo_wc_bridge_github_release_v1', $GLOBALS['mwb_test_state']['site_transients']);
    }

    /**
     * @return array<string,mixed>
     */
    private function release_response(string $tag): array
    {
        $version = ltrim($tag, 'vV');

        return [
            'response' => ['code' => 200],
            'body' => wp_json_encode(
                [
                    'tag_name' => $tag,
                    'published_at' => '2026-03-01T12:00:00Z',
                    'html_url' => 'https://github.com/Custom-Services-Limited/merchandillo-woocommerce-bridge/releases/tag/' . $tag,
                    'body' => 'Release notes for ' . $tag,
                    'assets' => [
                        [
                            'name' => 'merchandillo-woocommerce-bridge-' . $version . '.zip',
                            'browser_download_url' => 'https://downloads.example.test/merchandillo-woocommerce-bridge-' . $version . '.zip',
                        ],
                    ],
                ]
            ),
        ];
    }
}
