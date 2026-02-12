<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class MerchandilloTestCase extends TestCase
{
    /** @var array<int,string> */
    private $tempRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        mwb_test_reset_state();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $this->resetSingletonInstance();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removePath($root);
        }
        $this->tempRoots = [];
        parent::tearDown();
    }

    private function resetSingletonInstance(): void
    {
        $reset = Closure::bind(
            static function (): void {
                Merchandillo_WooCommerce_Bridge::$instance = null;
            },
            null,
            Merchandillo_WooCommerce_Bridge::class
        );

        $reset();
    }

    protected function newBridge(): Merchandillo_WooCommerce_Bridge
    {
        $reflection = new ReflectionClass(Merchandillo_WooCommerce_Bridge::class);
        /** @var Merchandillo_WooCommerce_Bridge $bridge */
        $bridge = $reflection->newInstanceWithoutConstructor();

        return $bridge;
    }

    protected function newSettings(): Merchandillo_Settings
    {
        return new Merchandillo_Settings('merchandillo_sync_options');
    }

    protected function newLogManager(): Merchandillo_Log_Manager
    {
        return new Merchandillo_Log_Manager($this->newSettings(), 'merchandillo-woocommerce-bridge');
    }

    protected function newSettingsTab(): Merchandillo_Settings_Tab
    {
        return new Merchandillo_Settings_Tab(
            $this->newSettings(),
            'merchandillo-woocommerce-bridge',
            'merchandillo_logs_action',
            'merchandillo_logs_action'
        );
    }

    protected function newLogsTab(): Merchandillo_Logs_Tab
    {
        return new Merchandillo_Logs_Tab($this->newLogManager(), 'merchandillo-woocommerce-bridge', 'merchandillo_logs_action', 'merchandillo_logs_action');
    }

    protected function newApiConnectionTester(): Merchandillo_Api_Connection_Tester
    {
        return new Merchandillo_Api_Connection_Tester(
            $this->newSettings(),
            $this->newLogManager()
        );
    }

    protected function newTranslationManager(): Merchandillo_Translation_Manager
    {
        return new Merchandillo_Translation_Manager($this->newSettings());
    }

    protected function newAdminPage(): Merchandillo_Admin_Page
    {
        return new Merchandillo_Admin_Page(
            $this->newSettings(),
            $this->newSettingsTab(),
            $this->newLogsTab(),
            $this->newApiConnectionTester(),
            'merchandillo-woocommerce-bridge',
            'merchandillo_logs_action',
            'merchandillo_logs_action'
        );
    }

    protected function createTempRoot(): string
    {
        $root = sys_get_temp_dir() . '/mwb-tests-' . uniqid('', true);
        mkdir($root, 0777, true);
        $this->tempRoots[] = $root;

        return $root;
    }

    protected function buildSampleOrder(int $orderId): WC_Order
    {
        $product = new WC_Product(7001, 'SKU-7001');
        $item = new WC_Order_Item_Product(
            2,
            40.0,
            3.2,
            40.0,
            'Premium Shirt',
            $product,
            [
                new WC_Meta_Data('Color', 'black'),
                new WC_Meta_Data('_internal', 'skip-me'),
            ]
        );

        return new WC_Order(
            $orderId,
            [
                'billing_address' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'address_1' => '1 Main St',
                    'city' => 'Austin',
                    'postcode' => '78701',
                    'country' => 'US',
                    'state' => 'TX',
                ],
                'shipping_address' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'address_1' => '2 Market St',
                    'city' => 'San Francisco',
                    'postcode' => '94105',
                    'country' => 'US',
                    'state' => 'CA',
                ],
                'items' => [$item],
                'meta' => [
                    '_tracking_number' => 'TRACK-123',
                    '_tracking_url' => 'https://track.example/123',
                    '_shipping_courier' => 'UPS',
                ],
                'date_created' => new MWB_Test_Date(strtotime('2026-02-12 10:00:00 UTC')),
                'order_number' => 'ORD-123',
                'formatted_billing_full_name' => 'Jane Doe',
                'billing_first_name' => 'Jane',
                'billing_last_name' => 'Doe',
                'billing_email' => 'jane@example.com',
                'billing_phone' => '+15555550123',
                'total' => 43.2,
                'total_tax' => 3.2,
                'shipping_total' => 0.0,
                'discount_total' => 0.0,
                'currency' => 'USD',
                'status' => 'processing',
                'payment_method_title' => 'Credit Card',
                'is_paid' => true,
                'shipping_method' => 'UPS Ground',
                'customer_note' => 'Leave at front desk.',
            ]
        );
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $this->removePath($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }
}
