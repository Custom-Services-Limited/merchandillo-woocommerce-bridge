<?php

interface Merchandillo_Order_Payload_Builder_Interface
{
    /**
     * @return array<string,mixed>
     */
    public function build(WC_Order $order): array;
}
