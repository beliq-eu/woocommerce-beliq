<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

/**
 * One order line (a product, a shipping row, or a fee) as the adapter reads it.
 * The net total is the WooCommerce line total, which is stored net of tax
 * regardless of the store's "prices include tax" setting. The rate is the one
 * WooCommerce applied when the wrapper could read it from the tax table;
 * otherwise it is null and the adapter recovers it from the tax and net amounts.
 */
interface LineData
{
    public function getName(): string;

    public function getQuantity(): float;

    /** Line total, net of tax. */
    public function getNetTotal(): float;

    /** Total tax on the line, summed across all applicable rates. */
    public function getTaxTotal(): float;

    /** The applied VAT rate as a percentage, or null to recover it from amounts. */
    public function getTaxRatePercent(): ?float;

    public function getSku(): ?string;
}
