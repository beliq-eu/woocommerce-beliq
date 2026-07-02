<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

use Beliq\Core\Invoice\Address;
use Beliq\Core\Invoice\Party;
use Beliq\Core\Invoice\SourceLine;
use Beliq\Core\Invoice\SourceOrder;
use Beliq\WooCommerce\Config\PluginConfig;

/**
 * Builds a normalized SourceOrder from a WooCommerce order. This is the
 * WooCommerce-specific half of the plugin; everything downstream (the mapper,
 * the client) is platform-agnostic.
 *
 * The order is read through the OrderData / LineData seam rather than WC_Order
 * directly, so this mapping runs and is tested without booting WordPress.
 * WooCommerce stores line totals net of tax, so a line's net is its total
 * as-is; the rate is the one WooCommerce applied when the wrapper resolved it,
 * otherwise it is recovered from the tax and net amounts. The seller comes from
 * plugin config, the buyer from the order's billing fields.
 */
final class WooOrderAdapter
{
    public function toSourceOrder(OrderData $order, PluginConfig $config): SourceOrder
    {
        $lines = [];
        foreach ($order->getProductLines() as $line) {
            $lines[] = $this->toSourceLine($line);
        }
        foreach ($order->getShippingLines() as $line) {
            $lines[] = $this->toSourceLine($line);
        }
        foreach ($order->getFeeLines() as $line) {
            $lines[] = $this->toSourceLine($line);
        }

        $number = $order->getOrderNumber();
        $company = $this->emptyToNull($order->getBillingCompany());
        $vatId = $this->emptyToNull($order->getBuyerVatId());

        return new SourceOrder(
            number: $number,
            issueDate: $order->getIssueDateYmd(),
            currencyCode: $order->getCurrency(),
            seller: $config->seller,
            buyer: $this->buildBuyer($order, $company, $vatId),
            lines: $lines,
            paymentMeans: $config->paymentMeans?->withReference($number),
            buyerReference: $this->buyerReference($order, $number),
            buyerFlaggedBusiness: $company !== null,
            zeroRateCategory: $config->zeroRateCategory,
        );
    }

    private function toSourceLine(LineData $line): SourceLine
    {
        $quantity = $line->getQuantity();
        $net = $line->getNetTotal();
        $rate = $line->getTaxRatePercent() ?? $this->deriveRate($net, $line->getTaxTotal());

        return new SourceLine(
            description: $line->getName(),
            quantity: $quantity,
            unitNetPrice: $quantity > 0.0 ? $net / $quantity : $net,
            lineNetTotal: $net,
            vatRate: $rate,
            itemId: $this->emptyToNull($line->getSku()),
        );
    }

    /**
     * The VAT rate recovered from a line's tax and net amounts, used when the
     * wrapper could not read the applied rate from the tax table. A zero net
     * (a fully discounted line) yields a zero rate; the two-decimal rounding
     * absorbs float noise so a 19% line reads as 19.0, not 18.999999.
     */
    private function deriveRate(float $net, float $taxTotal): float
    {
        if ($net === 0.0) {
            return 0.0;
        }

        return round($taxTotal / $net * 100.0, 2);
    }

    private function buildBuyer(OrderData $order, ?string $company, ?string $vatId): Party
    {
        $personName = trim(($order->getBillingFirstName() ?? '') . ' ' . ($order->getBillingLastName() ?? ''));
        $name = $company ?? ($personName !== '' ? $personName : 'Customer');

        $address = new Address(
            city: $order->getBillingCity(),
            postalCode: $order->getBillingPostcode(),
            countryCode: $order->getBillingCountry(),
            street: $this->emptyToNull($order->getBillingStreet()),
            additionalStreet: $this->emptyToNull($order->getBillingStreet2()),
            state: $this->emptyToNull($order->getBillingState()),
        );

        return new Party(
            name: $name,
            address: $address,
            vatId: $vatId,
            email: $this->emptyToNull($order->getBillingEmail()),
            contactName: $company !== null && $personName !== '' ? $personName : null,
        );
    }

    /**
     * The buyer reference (BT-10). A merchant routing to a public administration
     * carries the buyer's Leitweg-ID in an order meta field; a plain commercial
     * order falls back to the buyer's customer reference, then to the order
     * number, so the field is always present (BR-DE-1).
     */
    private function buyerReference(OrderData $order, string $orderNumber): string
    {
        return $this->firstNonEmpty([
            $order->getBuyerReferenceRaw(),
            $order->getCustomerReference(),
            $orderNumber,
        ]) ?? $orderNumber;
    }

    /**
     * @param array<int, string|null> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
