<?php declare(strict_types=1);

/**
 * The slice of WooCommerce's order classes that WcOrderData reads for its lines.
 * WooCommerce is not loaded in the offline suite, so these stand in for it with
 * the same class and method names, holding fixed values.
 */

class WC_Product
{
    public function __construct(private readonly string $sku)
    {
    }

    public function get_sku(): string
    {
        return $this->sku;
    }
}

class WC_Order_Item
{
    public function __construct(private readonly string $name)
    {
    }

    public function get_name(): string
    {
        return $this->name;
    }
}

class WC_Order_Item_Product extends WC_Order_Item
{
    /**
     * @param WC_Product|false|null $product what get_product() returns, as wc_get_product() may
     */
    public function __construct(
        string $name,
        private readonly int $quantity,
        private readonly string $total,
        private readonly string $totalTax,
        private readonly WC_Product|false|null $product,
    ) {
        parent::__construct($name);
    }

    public function get_quantity(): int
    {
        return $this->quantity;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_total_tax(): string
    {
        return $this->totalTax;
    }

    /** @return array{total: array<int, string>} */
    public function get_taxes(): array
    {
        return ['total' => []];
    }

    public function get_product(): WC_Product|false|null
    {
        return $this->product;
    }
}

class WC_Order
{
    /** @param array<string, list<WC_Order_Item>> $itemsByType */
    public function __construct(private readonly array $itemsByType)
    {
    }

    /** @return list<WC_Order_Item> */
    public function get_items(string $type = 'line_item'): array
    {
        return $this->itemsByType[$type] ?? [];
    }
}
