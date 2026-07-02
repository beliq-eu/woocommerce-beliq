<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\Core\Invoice\Address;
use Beliq\Core\Invoice\Party;
use Beliq\Core\Invoice\PaymentMeans;
use Beliq\WooCommerce\Config\PluginConfig;
use Beliq\WooCommerce\Order\WooOrderAdapter;
use Beliq\WooCommerce\Tests\Support\StubLineData;
use Beliq\WooCommerce\Tests\Support\StubOrderData;
use PHPUnit\Framework\TestCase;

final class WooOrderAdapterTest extends TestCase
{
    private WooOrderAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new WooOrderAdapter();
    }

    public function testScalarsAndNetLinePassThroughWithRateDerivedFromAmounts(): void
    {
        // 2 units, net 200, 38 tax => the WooCommerce line total is already net,
        // so it passes through and the 19% rate is recovered from the amounts.
        $order = new StubOrderData(
            productLines: [new StubLineData('Widget', 2.0, 200.0, 38.0, sku: 'WID-1')],
            orderNumber: 'WC-2001',
            issueDateYmd: '2026-07-02',
            currency: 'EUR',
            billingCompany: 'ACME GmbH',
            billingFirstName: 'Ada',
            billingLastName: 'Byte',
            billingEmail: 'buyer@acme.test',
            billingCity: 'Berlin',
            billingPostcode: '10115',
            billingCountry: 'DE',
            billingStreet: 'Hauptstrasse 1',
            buyerVatId: 'DE123456789',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('WC-2001', $source->number);
        self::assertSame('2026-07-02', $source->issueDate);
        self::assertSame('EUR', $source->currencyCode);
        self::assertSame('Z', $source->zeroRateCategory);

        self::assertSame('ACME GmbH', $source->buyer->name);
        self::assertSame('DE123456789', $source->buyer->vatId);
        self::assertSame('buyer@acme.test', $source->buyer->email);
        self::assertSame('Ada Byte', $source->buyer->contactName);
        self::assertSame('Berlin', $source->buyer->address->city);
        self::assertSame('10115', $source->buyer->address->postalCode);
        self::assertSame('DE', $source->buyer->address->countryCode);
        self::assertSame('Hauptstrasse 1', $source->buyer->address->street);
        self::assertTrue($source->buyerIsBusiness());

        self::assertCount(1, $source->lines);
        self::assertEqualsWithDelta(200.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[0]->vatRate, 0.001);
        self::assertSame('WID-1', $source->lines[0]->itemId);
        self::assertSame('Widget', $source->lines[0]->description);
    }

    public function testSuppliedRateIsUsedInsteadOfDerivingFromAmounts(): void
    {
        // The wrapper read the applied rate from the tax table; it wins over the
        // amount ratio (which here would be 0 because no tax amount is present).
        $order = new StubOrderData(
            productLines: [new StubLineData('Service', 1.0, 500.0, 0.0, taxRatePercent: 19.0, sku: 'SV-1')],
            billingCompany: 'X SARL',
            buyerVatId: 'FR12345678901',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(500.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(500.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testZeroTaxLineYieldsZeroRate(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Export good', 3.0, 300.0, 0.0, sku: 'EX-1')],
            billingCompany: 'Overseas Inc',
            buyerVatId: 'CHE123456789',
            billingCountry: 'CH',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(300.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(0.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testShippingAndFeeLinesAreIncluded(): void
    {
        // Products, shipping, and fees each become a line; a negative fee (a
        // discount booked as a fee) keeps its sign.
        $order = new StubOrderData(
            productLines: [new StubLineData('Book', 1.0, 20.0, 1.4, taxRatePercent: 7.0, sku: 'BK-1')],
            shippingLines: [new StubLineData('Standard shipping', 1.0, 5.0, 0.95, taxRatePercent: 19.0)],
            feeLines: [new StubLineData('Loyalty discount', 1.0, -2.0, -0.38, taxRatePercent: 19.0)],
            billingCompany: 'Kim Trading',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertCount(3, $source->lines);
        self::assertSame('Book', $source->lines[0]->description);
        self::assertSame('Standard shipping', $source->lines[1]->description);
        self::assertEqualsWithDelta(5.0, $source->lines[1]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[1]->vatRate, 0.001);
        self::assertSame('Loyalty discount', $source->lines[2]->description);
        self::assertEqualsWithDelta(-2.0, $source->lines[2]->lineNetTotal, 0.001);
    }

    public function testFullyDiscountedLineDoesNotDivideByZero(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Freebie', 1.0, 0.0, 0.0, sku: 'FR-1')],
            billingCompany: 'Co',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(0.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(0.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(0.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testCompanyWithoutVatStillCountsAsBusiness(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            billingFirstName: 'Kim',
            billingLastName: 'Lee',
            billingCompany: 'Kim Trading',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Kim Trading', $source->buyer->name);
        self::assertNull($source->buyer->vatId);
        self::assertSame('Kim Lee', $source->buyer->contactName);
        self::assertTrue($source->buyerIsBusiness());
    }

    public function testVatWithoutCompanyStillCountsAsBusiness(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            billingFirstName: 'Max',
            billingLastName: 'Muster',
            buyerVatId: 'FR12345678901',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Max Muster', $source->buyer->name);
        self::assertSame('FR12345678901', $source->buyer->vatId);
        // No company, so no separate contact person is set.
        self::assertNull($source->buyer->contactName);
        self::assertTrue($source->buyerIsBusiness());
    }

    public function testPrivateConsumerIsNotBusiness(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Book', 1.0, 20.0, 1.4, taxRatePercent: 7.0, sku: 'BK-1')],
            billingFirstName: 'Jane',
            billingLastName: 'Roe',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Jane Roe', $source->buyer->name);
        self::assertNull($source->buyer->vatId);
        self::assertNull($source->buyer->contactName);
        self::assertFalse($source->buyerIsBusiness());
    }

    public function testBuyerFallsBackToCustomerLabelWhenNameIsBlank(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Customer', $source->buyer->name);
    }

    public function testBuyerReferenceComesFromRawMetaFirst(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            billingCompany: 'Behoerde',
            buyerReferenceRaw: '04011000-12345-67',
            customerReference: 'C-9001',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        // The Leitweg-ID wins over the customer reference and order number.
        self::assertSame('04011000-12345-67', $source->buyerReference);
    }

    public function testBuyerReferenceFallsBackToCustomerReference(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            billingCompany: 'BX',
            customerReference: 'C-9001',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('C-9001', $source->buyerReference);
    }

    public function testBuyerReferenceFallsBackToOrderNumber(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            orderNumber: 'WC-2001',
            billingCompany: 'BX',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('WC-2001', $source->buyerReference);
    }

    public function testPaymentMeansCarriesTheOrderNumberAsReference(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            orderNumber: 'WC-2001',
            billingCompany: 'BX',
        );

        $config = $this->config(new PaymentMeans('58', 'DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank'));
        $source = $this->adapter->toSourceOrder($order, $config);

        self::assertNotNull($source->paymentMeans);
        self::assertSame('58', $source->paymentMeans->typeCode);
        self::assertSame('DE89370400440532013000', $source->paymentMeans->iban);
        // The configured template carries no reference; the adapter injects the order number (BT-83).
        self::assertSame('WC-2001', $source->paymentMeans->paymentReference);
    }

    public function testNoPaymentMeansWhenConfigHasNone(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Item', 1.0, 100.0, 19.0, sku: 'I-1')],
            billingCompany: 'BX',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertNull($source->paymentMeans);
    }

    public function testDeletedProductWithNoSkuDoesNotCrash(): void
    {
        $order = new StubOrderData(
            productLines: [new StubLineData('Deleted product', 1.0, 100.0, 19.0, taxRatePercent: 19.0)],
            billingCompany: 'BX',
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertNull($source->lines[0]->itemId);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->lineNetTotal, 0.001);
    }

    private function config(?PaymentMeans $paymentMeans = null): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            apiKey: 'test-key',
            baseUrl: 'https://api.beliq.eu',
            standard: 'zugferd',
            profile: 'en16931',
            output: 'pdf',
            businessOnly: true,
            triggerEvent: 'processing',
            zeroRateCategory: 'Z',
            seller: new Party('Seller GmbH', new Address('Berlin', '10115', 'DE'), vatId: 'DE999999999'),
            paymentMeans: $paymentMeans,
        );
    }
}
