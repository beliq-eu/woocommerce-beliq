<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

use Beliq\WooCommerce\Config\PluginConfig;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Tax;

/**
 * The OrderData seam implemented over a live WC_Order. It reads billing fields,
 * line collections, and the two configured meta keys (buyer VAT id, buyer
 * reference) through the order CRUD API, so it works the same under HPOS and the
 * legacy posts store. All EN 16931 mapping decisions stay in WooOrderAdapter;
 * this class only exposes what the order holds.
 *
 * Line net comes from WC_Order_Item::get_total(), which WooCommerce stores net of
 * tax regardless of the store's "prices include tax" setting. The applied VAT
 * rate is read from WC_Tax for the rate id carrying the most tax on the line;
 * when no rate applies (a zero-tax line), it is left null and the adapter derives
 * 0 from the amounts.
 */
final class WcOrderData implements OrderData
{
    public function __construct(
        private readonly WC_Order $order,
        private readonly PluginConfig $config,
    ) {
    }

    public function getOrderNumber(): string
    {
        return (string) $this->order->get_order_number();
    }

    public function getIssueDateYmd(): string
    {
        $created = $this->order->get_date_created();

        return $created !== null ? $created->date('Y-m-d') : gmdate('Y-m-d');
    }

    public function getCurrency(): string
    {
        return (string) $this->order->get_currency();
    }

    public function getBillingCompany(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_company());
    }

    public function getBillingFirstName(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_first_name());
    }

    public function getBillingLastName(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_last_name());
    }

    public function getBillingEmail(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_email());
    }

    public function getBillingCity(): string
    {
        return (string) $this->order->get_billing_city();
    }

    public function getBillingPostcode(): string
    {
        return (string) $this->order->get_billing_postcode();
    }

    public function getBillingCountry(): string
    {
        return (string) $this->order->get_billing_country();
    }

    public function getBillingStreet(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_address_1());
    }

    public function getBillingStreet2(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_address_2());
    }

    public function getBillingState(): ?string
    {
        return $this->emptyToNull($this->order->get_billing_state());
    }

    public function getBuyerVatId(): ?string
    {
        return $this->metaOrNull($this->config->buyerVatMetaKey);
    }

    public function getBuyerReferenceRaw(): ?string
    {
        return $this->metaOrNull($this->config->buyerReferenceMetaKey);
    }

    public function getCustomerReference(): ?string
    {
        $customerId = $this->order->get_customer_id();

        return $customerId > 0 ? 'customer-' . $customerId : null;
    }

    /** @return iterable<WcLineData> */
    public function getProductLines(): iterable
    {
        foreach ($this->order->get_items('line_item') as $item) {
            $sku = null;
            if ($item instanceof WC_Order_Item_Product) {
                $product = $item->get_product();
                $sku = $product !== false ? $this->emptyToNull($product->get_sku()) : null;
            }

            yield $this->line($item, (float) $item->get_quantity(), $sku);
        }
    }

    /** @return iterable<WcLineData> */
    public function getShippingLines(): iterable
    {
        foreach ($this->order->get_items('shipping') as $item) {
            yield $this->line($item, 1.0, null);
        }
    }

    /** @return iterable<WcLineData> */
    public function getFeeLines(): iterable
    {
        foreach ($this->order->get_items('fee') as $item) {
            yield $this->line($item, 1.0, null);
        }
    }

    private function line(WC_Order_Item $item, float $quantity, ?string $sku): WcLineData
    {
        return new WcLineData(
            name: (string) $item->get_name(),
            quantity: $quantity,
            netTotal: (float) $item->get_total(),
            taxTotal: (float) $item->get_total_tax(),
            taxRatePercent: $this->ratePercent($item),
            sku: $sku,
        );
    }

    /**
     * The VAT rate WooCommerce applied to the line, read from WC_Tax. A line can
     * carry more than one rate; the one contributing the most tax is the dominant
     * component, and the line is labelled with it (the net still balances because
     * it is the true line total). A line with no applicable tax yields null, and
     * the adapter derives 0 from the amounts.
     */
    private function ratePercent(WC_Order_Item $item): ?float
    {
        $taxes = $item->get_taxes();
        $totals = is_array($taxes['total'] ?? null) ? $taxes['total'] : [];

        $byAmount = [];
        foreach ($totals as $rateId => $amount) {
            $value = (float) $amount;
            if ($value !== 0.0) {
                $byAmount[(int) $rateId] = abs($value);
            }
        }
        if ($byAmount === [] || !class_exists(WC_Tax::class)) {
            return null;
        }

        arsort($byAmount);
        $dominantRateId = (int) array_key_first($byAmount);
        $percent = WC_Tax::get_rate_percent_value($dominantRateId);

        return is_numeric($percent) ? (float) $percent : null;
    }

    private function metaOrNull(string $metaKey): ?string
    {
        if ($metaKey === '') {
            return null;
        }

        $value = $this->order->get_meta($metaKey);

        return is_string($value) ? $this->emptyToNull($value) : null;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
