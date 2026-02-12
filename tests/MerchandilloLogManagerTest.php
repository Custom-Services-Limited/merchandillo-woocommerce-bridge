<?php

declare(strict_types=1);

final class MerchandilloLogManagerTest extends MerchandilloTestCase
{
    public function test_log_manager_get_filters_sanitizes_and_clamps_values(): void
    {
        $logManager = $this->newLogManager();
        $files = ['known.log' => '/tmp/known.log'];

        $filters = $logManager->get_filters(
            $files,
            [
                'log_file' => '../../not-allowed.log',
                'log_level' => 'NOT_REAL',
                'log_search' => '<b>network</b>',
                'log_limit' => '99999',
            ]
        );

        $this->assertSame('all', $filters['file']);
        $this->assertSame('', $filters['level']);
        $this->assertSame('network', $filters['search']);
        $this->assertSame(5000, $filters['limit']);
    }

    public function test_log_manager_normalize_entry_extracts_timestamp_and_level(): void
    {
        $logManager = $this->newLogManager();

        $entry = $logManager->normalize_entry('merchandillo.log', '[2026-02-12T10:11:12+00:00] ERROR Sync failed for order 51');

        $this->assertSame('merchandillo.log', $entry['file']);
        $this->assertSame('2026-02-12T10:11:12+00:00', $entry['timestamp']);
        $this->assertSame('error', $entry['level']);
        $this->assertStringContainsString('order 51', $entry['line']);
    }

    public function test_log_manager_read_last_lines_returns_file_tail(): void
    {
        $logManager = $this->newLogManager();
        $root = $this->createTempRoot();
        $filePath = $root . '/tail.log';
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = 'line-' . $i;
        }
        file_put_contents($filePath, implode("\n", $lines) . "\n");

        $tail = $logManager->read_last_lines($filePath, 5);

        $this->assertSame(['line-26', 'line-27', 'line-28', 'line-29', 'line-30'], $tail);
    }

    public function test_log_manager_get_filtered_entries_applies_level_search_and_limit(): void
    {
        $logManager = $this->newLogManager();
        $root = $this->createTempRoot();
        $fileOne = $root . '/one.log';
        $fileTwo = $root . '/two.log';

        file_put_contents(
            $fileOne,
            implode(
                "\n",
                [
                    '[2026-01-01T10:00:00+00:00] INFO Started',
                    '[2026-01-01T10:01:00+00:00] ERROR Order 100 failed',
                ]
            ) . "\n"
        );
        file_put_contents(
            $fileTwo,
            implode(
                "\n",
                [
                    '[2026-01-01T10:02:00+00:00] ERROR Order 101 failed',
                    '[2026-01-01T10:03:00+00:00] NOTICE Complete',
                ]
            ) . "\n"
        );

        $entries = $logManager->get_filtered_entries(
            ['one.log' => $fileOne, 'two.log' => $fileTwo],
            'all',
            'error',
            'Order',
            1
        );

        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertStringContainsString('Order', $entries[0]['line']);
    }

    public function test_log_manager_get_files_discovers_and_sorts_latest_first(): void
    {
        $logManager = $this->newLogManager();
        $root = $this->createTempRoot();
        $logDir = $root . '/wc-logs';
        mkdir($logDir, 0777, true);

        $older = $logDir . '/merchandillo-woocommerce-bridge-2026-01-01-a.log';
        $newer = $logDir . '/merchandillo-woocommerce-bridge-2026-01-02-b.log';
        $active = $logDir . '/merchandillo-woocommerce-bridge.log';
        file_put_contents($older, 'old');
        file_put_contents($newer, 'new');
        file_put_contents($active, 'active');
        touch($older, 1000);
        touch($newer, 2000);
        touch($active, 3000);

        $GLOBALS['mwb_test_state']['upload_dir'] = ['basedir' => $root];
        $GLOBALS['mwb_test_state']['wc_log_file_path'] = $active;

        $files = $logManager->get_files();
        $keys = array_keys($files);

        $this->assertCount(3, $files);
        $this->assertSame('merchandillo-woocommerce-bridge.log', $keys[0]);
        $this->assertArrayHasKey('merchandillo-woocommerce-bridge-2026-01-01-a.log', $files);
    }

    public function test_log_manager_clear_files_deletes_discovered_files(): void
    {
        $logManager = $this->newLogManager();
        $root = $this->createTempRoot();
        $logDir = $root . '/wc-logs';
        mkdir($logDir, 0777, true);

        $fileA = $logDir . '/merchandillo-woocommerce-bridge-2026-02-01-a.log';
        $fileB = $logDir . '/merchandillo-woocommerce-bridge-2026-02-02-b.log';
        file_put_contents($fileA, 'A');
        file_put_contents($fileB, 'B');
        $GLOBALS['mwb_test_state']['upload_dir'] = ['basedir' => $root];

        $result = $logManager->clear_files();

        $this->assertSame(['deleted' => 2, 'failed' => 0], $result);
        $this->assertFileDoesNotExist($fileA);
        $this->assertFileDoesNotExist($fileB);
    }

    public function test_log_manager_write_skips_when_log_errors_disabled(): void
    {
        $logManager = $this->newLogManager();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '0',
        ];

        $logManager->write('error', 'Should not be logged', ['order_id' => 1]);

        $this->assertCount(0, $GLOBALS['mwb_test_state']['logger_calls']);
    }
}
