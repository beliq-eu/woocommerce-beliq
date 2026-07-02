<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\WooCommerce\Order\LineData;
use Beliq\WooCommerce\Order\OrderData;

/**
 * A plain OrderData for adapter tests, standing in for the WC_Order wrapper so
 * the mapping is exercised without WooCommerce. Lines are positional; the rest
 * are named so a test sets only the fields it asserts on.
 */
final readonly class StubOrderData implements OrderData
{
    /**
     * @param list<LineData> $productLines
     * @param list<LineData> $shippingLines
     * @param list<LineData> $feeLines
     */
    public function __construct(
        private array $productLines = [],
        private array $shippingLines = [],
        private array $feeLines = [],
        private string $orderNumber = 'WC-1001',
        private string $issueDateYmd = '2026-07-01',
        private string $currency = 'EUR',
        private ?string $billingCompany = null,
        private ?string $billingFirstName = null,
        private ?string $billingLastName = null,
        private ?string $billingEmail = null,
        private string $billingCity = 'Berlin',
        private string $billingPostcode = '10115',
        private string $billingCountry = 'DE',
        private ?string $billingStreet = null,
        private ?string $billingStreet2 = null,
        private ?string $billingState = null,
        private ?string $buyerVatId = null,
        private ?string $buyerReferenceRaw = null,
        private ?string $customerReference = null,
    ) {
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getIssueDateYmd(): string
    {
        return $this->issueDateYmd;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBillingCompany(): ?string
    {
        return $this->billingCompany;
    }

    public function getBillingFirstName(): ?string
    {
        return $this->billingFirstName;
    }

    public function getBillingLastName(): ?string
    {
        return $this->billingLastName;
    }

    public function getBillingEmail(): ?string
    {
        return $this->billingEmail;
    }

    public function getBillingCity(): string
    {
        return $this->billingCity;
    }

    public function getBillingPostcode(): string
    {
        return $this->billingPostcode;
    }

    public function getBillingCountry(): string
    {
        return $this->billingCountry;
    }

    public function getBillingStreet(): ?string
    {
        return $this->billingStreet;
    }

    public function getBillingStreet2(): ?string
    {
        return $this->billingStreet2;
    }

    public function getBillingState(): ?string
    {
        return $this->billingState;
    }

    public function getBuyerVatId(): ?string
    {
        return $this->buyerVatId;
    }

    public function getBuyerReferenceRaw(): ?string
    {
        return $this->buyerReferenceRaw;
    }

    public function getCustomerReference(): ?string
    {
        return $this->customerReference;
    }

    public function getProductLines(): iterable
    {
        return $this->productLines;
    }

    public function getShippingLines(): iterable
    {
        return $this->shippingLines;
    }

    public function getFeeLines(): iterable
    {
        return $this->feeLines;
    }
}
