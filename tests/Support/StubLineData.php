<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\WooCommerce\Order\LineData;

/**
 * A plain LineData for adapter tests, standing in for the WC_Order-item wrapper
 * so the mapping is exercised without WooCommerce.
 */
final readonly class StubLineData implements LineData
{
    public function __construct(
        private string $name,
        private float $quantity,
        private float $netTotal,
        private float $taxTotal,
        private ?float $taxRatePercent = null,
        private ?string $sku = null,
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
