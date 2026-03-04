<?php

declare(strict_types=1);

final class MerchandilloLogsTabTest extends MerchandilloTestCase
{
    public function test_logs_tab_render_outputs_filters_actions_and_log_lines(): void
    {
        $logsTab = $this->newLogsTab();
        $root = $this->createTempRoot();
        $logDir = $root . '/wc-logs';
        mkdir($logDir, 0777, true);
        $logFile = $logDir . '/merchandillo-woocommerce-bridge-2026-02-12-test.log';
        file_put_contents(
            $logFile,
            "[2026-02-12T10:00:00+00:00] ERROR Sync failed for order #99\n"
        );

        $GLOBALS['mwb_test_state']['upload_dir'] = ['basedir' => $root];
        $_GET = [
            'tab' => 'logs',
            'log_file' => 'all',
            'log_level' => '',
            'log_search' => '',
            'log_limit' => '100',
        ];

        ob_start();
        $logsTab->render_tab($_GET);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Apply Filters', $output);
        $this->assertStringContainsString('Show Last 100 Lines', $output);
        $this->assertStringContainsString('Export Filtered Logs', $output);
        $this->assertStringContainsString('Clear Plugin Logs', $output);
        $this->assertStringContainsString('Sync failed for order #99', $output);
    }

    public function test_logs_tab_output_export_contains_filtered_content(): void
    {
        $logsTab = $this->newLogsTab();
        $root = $this->createTempRoot();
        $logDir = $root . '/wc-logs';
        mkdir($logDir, 0777, true);
        file_put_contents(
            $logDir . '/merchandillo-woocommerce-bridge-2026-02-12-test.log',
            implode(
                "\n",
                [
                    '[2026-02-12T10:00:00+00:00] INFO Start',
                    '[2026-02-12T10:01:00+00:00] ERROR Network timeout',
                ]
            ) . "\n"
        );
        $GLOBALS['mwb_test_state']['upload_dir'] = ['basedir' => $root];
        $_GET = [
            'log_file' => 'all',
            'log_level' => 'error',
            'log_search' => 'Network',
            'log_limit' => '10',
        ];

        ob_start();
        $logsTab->output_export($_GET);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Merchandillo WooCommerce Bridge Logs', $output);
        $this->assertStringContainsString('Level filter: error', $output);
        $this->assertStringContainsString('Network timeout', $output);
    }

    public function test_logs_tab_notice_warning_and_success_messages(): void
    {
        $logsTab = $this->newLogsTab();

        ob_start();
        $logsTab->render_notice(['logs_cleared' => '2', 'logs_failed' => '1']);
        $warningOutput = (string) ob_get_clean();
        $this->assertStringContainsString('could not be deleted', $warningOutput);

        ob_start();
        $logsTab->render_notice(['logs_cleared' => '3', 'logs_failed' => '0']);
        $successOutput = (string) ob_get_clean();
        $this->assertStringContainsString('Removed 3 log file(s).', $successOutput);
    }

    public function test_logs_tab_renders_json_context_in_scrollable_pretty_block(): void
    {
        $logsTab = $this->newLogsTab();
        $root = $this->createTempRoot();
        $logDir = $root . '/wc-logs';
        mkdir($logDir, 0777, true);
        $logFile = $logDir . '/merchandillo-woocommerce-bridge-2026-03-04-test.log';
        file_put_contents(
            $logFile,
            '[2026-03-04T12:43:52+00:00] INFO API connection test request/response. | {"endpoint":"http:\\/\\/host.docker.internal:8787\\/api\\/woocommerce\\/orders?page=1&limit=1","response_http_status":200,"response_body":"{\\"orders\\":[] ,\\"total\\":0}","result_code":"success"}' . "\n"
        );

        $GLOBALS['mwb_test_state']['upload_dir'] = ['basedir' => $root];
        $_GET = [
            'tab' => 'logs',
            'log_file' => 'all',
            'log_level' => '',
            'log_search' => '',
            'log_limit' => '100',
        ];

        ob_start();
        $logsTab->render_tab($_GET);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('class="mwb-log-json"', $output);
        $this->assertStringContainsString('"endpoint": "http://host.docker.internal:8787/api/woocommerce/orders?page=1&limit=1"', $output);
        $this->assertStringContainsString('"response_http_status": 200', $output);
        $this->assertStringContainsString('"response_body": {', $output);
        $this->assertStringContainsString('"orders": []', $output);
        $this->assertStringNotContainsString('\\"orders\\":[]', $output);
        $this->assertStringContainsString('API connection test request/response.', $output);
    }
}
