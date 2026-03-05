<?php

declare(strict_types=1);

final class MerchandilloBulkOrderPushTest extends MerchandilloTestCase
{
    public function test_register_bulk_order_push_action_appends_bulk_action_label(): void
    {
        $bridge = $this->newBridge();

        $actions = $bridge->register_bulk_order_push_action(['trash' => 'Move to Trash']);

        $this->assertArrayHasKey('merchandillo_bulk_push_orders', $actions);
        $this->assertSame('Send to Merchandillo', $actions['merchandillo_bulk_push_orders']);
    }

    public function test_handle_bulk_order_push_action_returns_original_redirect_for_other_actions(): void
    {
        $bridge = $this->newBridge();
        $redirect = 'https://example.test/wp-admin/edit.php?post_type=shop_order';

        $result = $bridge->handle_bulk_order_push_action($redirect, 'mark_completed', [10, 20]);

        $this->assertSame($redirect, $result);
    }

    public function test_handle_bulk_order_push_action_sets_empty_status_when_selection_has_no_valid_ids(): void
    {
        $bridge = $this->newBridge();
        $redirect = 'https://example.test/wp-admin/edit.php?post_type=shop_order';

        $result = $bridge->handle_bulk_order_push_action($redirect, 'merchandillo_bulk_push_orders', [0, 'abc']);
        $query = $this->queryFromUrl($result);

        $this->assertSame('empty_selection', $query['merchandillo_bulk_status'] ?? '');
    }

    public function test_handle_bulk_order_push_action_builds_launcher_query_with_sanitized_order_ids(): void
    {
        $bridge = $this->newBridge();
        $redirect = 'https://example.test/wp-admin/edit.php?post_type=shop_order';

        $result = $bridge->handle_bulk_order_push_action(
            $redirect,
            'merchandillo_bulk_push_orders',
            ['12', '12', 13, 'invalid', 14]
        );
        $query = $this->queryFromUrl($result);

        $this->assertSame('1', $query['merchandillo_bulk_push'] ?? '');
        $this->assertSame('12,13,14', $query['merchandillo_bulk_ids'] ?? '');
        $this->assertSame('merchandillo_push_order', $query['merchandillo_bulk_nonce'] ?? '');
        $this->assertSame('0', $query['merchandillo_bulk_truncated'] ?? '');
    }

    public function test_handle_bulk_order_push_action_marks_truncated_when_above_limit(): void
    {
        $bridge = $this->newBridge();
        $redirect = 'https://example.test/wp-admin/edit.php?post_type=shop_order';
        $ids = range(1, 55);

        $result = $bridge->handle_bulk_order_push_action($redirect, 'merchandillo_bulk_push_orders', $ids);
        $query = $this->queryFromUrl($result);

        $this->assertSame('1', $query['merchandillo_bulk_truncated'] ?? '');

        $selected = explode(',', (string) ($query['merchandillo_bulk_ids'] ?? ''));
        $this->assertCount(50, $selected);
        $this->assertSame('1', $selected[0]);
        $this->assertSame('50', $selected[49]);
    }

    public function test_render_bulk_push_launcher_notice_outputs_modal_when_query_payload_is_present(): void
    {
        $this->resetModalFlags();
        $bridge = $this->newBridge();
        $_GET = [
            'merchandillo_bulk_push' => '1',
            'merchandillo_bulk_ids' => '21,22,23',
            'merchandillo_bulk_nonce' => 'nonce-value',
        ];

        ob_start();
        $bridge->render_bulk_push_launcher_notice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('mwb-bulk-order-push-modal', $output);
        $this->assertStringContainsString('merchandillo_bulk_compare_orders', $output);
        $this->assertStringContainsString('merchandillo_bulk_push_orders_now', $output);
        $this->assertStringContainsString('21,22,23', $output);
    }

    public function test_render_bulk_push_launcher_notice_outputs_error_notice_when_empty_selection_status_is_present(): void
    {
        $this->resetModalFlags();
        $bridge = $this->newBridge();
        $_GET = [
            'merchandillo_bulk_status' => 'empty_selection',
        ];

        ob_start();
        $bridge->render_bulk_push_launcher_notice();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('Bulk push skipped because no valid orders were selected.', $output);
    }

    public function test_maybe_handle_bulk_order_push_fallback_redirects_to_bulk_launcher_when_action_matches(): void
    {
        $bridge = $this->newBridge();
        $_REQUEST = [
            'action' => 'merchandillo_bulk_push_orders',
            'post' => ['31', '32', '32'],
        ];

        $bridge->maybe_handle_bulk_order_push_fallback();
        $query = $this->queryFromUrl((string) $GLOBALS['mwb_test_state']['last_redirect']);

        $this->assertSame('1', $query['merchandillo_bulk_push'] ?? '');
        $this->assertSame('31,32', $query['merchandillo_bulk_ids'] ?? '');
    }

    public function test_render_bulk_push_launcher_footer_outputs_modal_when_launch_context_is_valid(): void
    {
        $this->resetModalFlags();
        $bridge = $this->newBridge();
        $_GET = [
            'merchandillo_bulk_push' => '1',
            'merchandillo_bulk_ids' => '41,42',
            'merchandillo_bulk_nonce' => 'nonce-value',
        ];

        ob_start();
        $bridge->render_bulk_push_launcher_footer();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('mwb-bulk-order-push-modal', $output);
        $this->assertStringContainsString('41,42', $output);
    }

    /**
     * @return array<string,string>
     */
    private function queryFromUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return [];
        }

        $query = [];
        parse_str($parts['query'], $query);

        return is_array($query) ? $query : [];
    }

    private function resetModalFlags(): void
    {
        $reset = Closure::bind(
            static function (): void {
                Merchandillo_WooCommerce_Bridge::$manualPushModalRendered = false;
                Merchandillo_WooCommerce_Bridge::$bulkPushModalRendered = false;
            },
            null,
            Merchandillo_WooCommerce_Bridge::class
        );

        $reset();
    }
}
