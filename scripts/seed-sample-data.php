<?php

if (!function_exists('wc_get_product') || !function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce is not active.');
}

/**
 * @return int
 */
function merchandillo_find_or_create_product(string $name, string $sku, float $price): int
{
    $existingId = wc_get_product_id_by_sku($sku);
    if ($existingId) {
        return (int) $existingId;
    }

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_regular_price((string) $price);
    $product->set_price((string) $price);
    $product->set_status('publish');

    return (int) $product->save();
}

/**
 * @return int
 */
function merchandillo_find_or_create_customer(): int
{
    $email = 'customer@example.com';
    $user = get_user_by('email', $email);
    if ($user) {
        return (int) $user->ID;
    }

    $userId = wp_create_user('merchandillo_customer', 'merchandillo_customer_123!', $email);
    if (is_wp_error($userId)) {
        WP_CLI::error('Failed to create sample customer: ' . $userId->get_error_message());
    }

    wp_update_user([
        'ID' => $userId,
        'first_name' => 'Demo',
        'last_name' => 'Customer',
    ]);

    return (int) $userId;
}

function merchandillo_create_sample_order(int $customerId, array $productIds, int $index): void
{
    $order = wc_create_order([
        'customer_id' => $customerId,
    ]);

    foreach ($productIds as $productId) {
        $product = wc_get_product($productId);
        if (!$product) {
            continue;
        }

        $quantity = $index % 2 === 0 ? 1 : 2;
        $order->add_product($product, $quantity);
    }

    $address = [
        'first_name' => 'Demo',
        'last_name' => 'Customer',
        'company' => '',
        'email' => 'customer@example.com',
        'phone' => '+30 210 1234567',
        'address_1' => '99 Example Street',
        'address_2' => 'Suite ' . ($index + 1),
        'city' => 'Athens',
        'state' => 'Attica',
        'postcode' => '10431',
        'country' => 'GR',
    ];

    $order->set_address($address, 'billing');
    $order->set_address($address, 'shipping');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on Delivery');
    $order->set_customer_note('Seeded sample order for Merchandillo integration testing.');
    $order->update_meta_data('_merchandillo_sample_order', '1');

    $order->calculate_totals();

    $statuses = ['pending', 'processing', 'completed'];
    $status = $statuses[$index % count($statuses)];
    $order->set_status($status, 'Seeded for local integration testing.', true);
    $order->save();
}

$productIds = [
    merchandillo_find_or_create_product('Merchandillo Sample T-Shirt', 'MERCH-TSHIRT', 24.90),
    merchandillo_find_or_create_product('Merchandillo Sample Mug', 'MERCH-MUG', 11.50),
];

$customerId = merchandillo_find_or_create_customer();

$existingSampleOrders = wc_get_orders([
    'limit' => -1,
    'return' => 'ids',
    'meta_key' => '_merchandillo_sample_order',
    'meta_value' => '1',
]);

$targetCount = 3;
$currentCount = is_array($existingSampleOrders) ? count($existingSampleOrders) : 0;

for ($i = $currentCount; $i < $targetCount; $i++) {
    merchandillo_create_sample_order($customerId, $productIds, $i);
}

WP_CLI::success('Sample products and orders are ready.');
