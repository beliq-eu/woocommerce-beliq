<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

/**
 * A read-only holder for one order line, filled by WcOrderData from a
 * WC_Order_Item. It carries the values already resolved off the item (net total,
 * tax total, the applied VAT rate where WC_Tax could supply it, the SKU) so the
 * adapter maps against plain data and needs no WooCommerce to run.
 */
final readonly class WcLineData implements LineData
{
    public function __construct(
        private string $name,
        private float $quantity,
        private float $netTotal,
        private float $taxTotal,
        private ?float $taxRatePercent,
        private ?string $sku,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getNetTotal(): float
    {
        return $this->netTotal;
    }

    public function getTaxTotal(): float
    {
        return $this->taxTotal;
    }

    public function getTaxRatePercent(): ?float
    {
        return $this->taxRatePercent;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }
}
