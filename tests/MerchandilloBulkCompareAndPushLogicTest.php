<?php

declare(strict_types=1);

final class MerchandilloBulkCompareAndPushLogicTest extends MerchandilloTestCase
{
    public function test_build_bulk_compare_result_returns_not_found_identical_and_different_states(): void
    {
        $bridge = $this->newBridge();
        $orders = [
            101 => $this->buildOrderWithNumber(101, 'ORD-101'),
            102 => $this->buildOrderWithNumber(102, 'ORD-102'),
            103 => $this->buildOrderWithNumber(103, 'ORD-103'),
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $orders;
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $payloadBuilder = new Merchandillo_Order_Payload_Builder();
        $remoteOrderIdentical = $this->remoteOrderFromPayload($payloadBuilder->build($orders[101]));
        $remoteOrderDifferent = $this->remoteOrderFromPayload($payloadBuilder->build($orders[102]));
        $remoteOrderDifferent['total_amount'] = '999.99';

        $GLOBALS['mwb_test_state']['remote_get_response'] = [
            'response' => ['code' => 200],
            'body' => wp_json_encode(
                [
                    'orders' => [
                        $remoteOrderIdentical,
                        $remoteOrderDifferent,
                    ],
                    'totalPages' => 1,
                ]
            ),
        ];

        $method = new ReflectionMethod(Merchandillo_WooCommerce_Bridge::class, 'build_bulk_compare_result');
        /** @var array<string,mixed> $result */
        $result = $method->invoke($bridge, [101, 102, 103]);

        $this->assertTrue((bool) $result['ok']);
        $this->assertSame(3, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['identical']);
        $this->assertSame(1, $result['summary']['different']);
        $this->assertSame(1, $result['summary']['not_found']);
        $this->assertSame([101], $result['identical_order_ids']);
        $this->assertSame([102], $result['different_order_ids']);
        $this->assertSame([103], $result['not_found_order_ids']);
    }

    public function test_build_bulk_compare_result_fails_when_sync_is_disabled(): void
    {
        $bridge = $this->newBridge();
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '0',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];

        $method = new ReflectionMethod(Merchandillo_WooCommerce_Bridge::class, 'build_bulk_compare_result');
        /** @var array<string,mixed> $result */
        $result = $method->invoke($bridge, [1]);

        $this->assertFalse((bool) $result['ok']);
        $this->assertSame('Sync is disabled in plugin settings.', $result['message']);
    }

    public function test_handle_bulk_push_ajax_reports_partial_success_summary(): void
    {
        $bridge = $this->newBridge();
        $orders = [
            201 => $this->buildSampleOrder(201),
            202 => $this->buildSampleOrder(202),
        ];
        $GLOBALS['mwb_test_state']['wc_get_order_return'] = $orders;
        $GLOBALS['mwb_test_state']['options']['merchandillo_sync_options'] = [
            'enabled' => '1',
            'api_base_url' => 'https://data.merchandillo.com',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'log_errors' => '1',
        ];
        $GLOBALS['mwb_test_state']['remote_post_response'] = static function (string $url, array $args) {
            unset($url);
            $payload = json_decode((string) ($args['body'] ?? ''), true);
            $orderId = is_array($payload) && isset($payload['id']) ? (int) $payload['id'] : 0;
            if (202 === $orderId) {
                return [
                    'response' => ['code' => 500],
                    'body' => '{"error":"rejected"}',
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '',
            ];
        };

        $_POST = ['order_ids' => '201,202'];
        $_REQUEST = ['nonce' => 'nonce-value', 'order_ids' => '201,202'];

        ob_start();
        $bridge->handle_bulk_push_ajax();
        $output = (string) ob_get_clean();

        $response = json_decode($output, true);
        $this->assertIsArray($response);
        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame(2, $response['data']['summary']['requested']);
        $this->assertSame(1, $response['data']['summary']['pushed']);
        $this->assertSame(1, $response['data']['summary']['failed']);
        $this->assertCount(2, $response['data']['results']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function remoteOrderFromPayload(array $payload): array
    {
        return [
            'id' => (int) $payload['id'],
            'order_number' => (string) $payload['order_number'],
            'customer_name' => (string) $payload['customer_name'],
            'customer_email' => (string) $payload['customer_email'],
            'customer_phone' => (string) $payload['customer_phone'],
            'status' => (string) $payload['status'],
            'subtotal' => number_format((float) $payload['subtotal'], 2, '.', ''),
            'tax_amount' => number_format((float) $payload['tax_amount'], 2, '.', ''),
            'shipping_amount' => number_format((float) $payload['shipping_amount'], 2, '.', ''),
            'discount_amount' => number_format((float) $payload['discount_amount'], 2, '.', ''),
            'total_amount' => number_format((float) $payload['total_amount'], 2, '.', ''),
            'currency' => (string) $payload['currency'],
            'shipping_address' => wp_json_encode($payload['shipping_address']),
            'billing_address' => wp_json_encode($payload['billing_address']),
            'payment_method' => (string) $payload['payment_method'],
            'payment_status' => (string) $payload['payment_status'],
            'shipping_method' => (string) $payload['shipping_method'],
            'tracking_number' => (string) $payload['tracking_number'],
            'notes' => (string) $payload['notes'],
            'order_date' => (string) $payload['order_date'] . 'T00:00:00.000Z',
            'items' => $payload['items'],
        ];
    }

    private function buildOrderWithNumber(int $orderId, string $orderNumber): WC_Order
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
                'order_number' => $orderNumber,
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
}
