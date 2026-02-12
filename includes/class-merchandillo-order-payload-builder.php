<?php

final class Merchandillo_Order_Payload_Builder implements Merchandillo_Order_Payload_Builder_Interface
{
    /**
     * @return array<string,mixed>
     */
    public function build(WC_Order $order): array
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
}
