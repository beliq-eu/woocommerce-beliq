<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

/**
 * The narrow, read-only view of an order the adapter needs. A WC_Order wrapper
 * implements it over the live WooCommerce order (resolving the configured meta
 * keys for the buyer VAT id and reference); the adapter maps against this
 * interface, so the mapping logic runs and is tested without booting WordPress.
 *
 * Products, shipping, and fees are exposed as separate line collections because
 * WooCommerce keeps them in separate order-item groups; coupons are not exposed
 * (WooCommerce already folds discounts into each line's net total, so emitting
 * a coupon line would double-subtract).
 */
interface OrderData
{
    public function getOrderNumber(): string;

    /** The order's issue date as an ISO date, YYYY-MM-DD. */
    public function getIssueDateYmd(): string;

    /** ISO 4217 currency code, e.g. EUR. */
    public function getCurrency(): string;

    public function getBillingCompany(): ?string;

    public function getBillingFirstName(): ?string;

    public function getBillingLastName(): ?string;

    public function getBillingEmail(): ?string;

    public function getBillingCity(): string;

    public function getBillingPostcode(): string;

    /** ISO 3166-1 alpha-2 country code. */
    public function getBillingCountry(): string;

    public function getBillingStreet(): ?string;

    public function getBillingStreet2(): ?string;

    public function getBillingState(): ?string;

    /** Buyer VAT id, resolved from the configured order meta key (nullable). */
    public function getBuyerVatId(): ?string;

    /** Buyer reference (BT-10) as stored, e.g. a Leitweg-ID (nullable). */
    public function getBuyerReferenceRaw(): ?string;

    /** A stable customer identifier used as the buyer-reference fallback. */
    public function getCustomerReference(): ?string;

    /** @return iterable<LineData> */
    public function getProductLines(): iterable;

    /** @return iterable<LineData> */
    public function getShippingLines(): iterable;

    /** @return iterable<LineData> */
    public function getFeeLines(): iterable;
}
