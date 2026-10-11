<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\WooCommerce\Config\PluginConfig;
use Beliq\WooCommerce\Order\WcLineData;
use Beliq\WooCommerce\Order\WcOrderData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * WcOrderData's product lines, read from stand-ins for WooCommerce's order
 * classes. A line's SKU comes from its product, and wc_get_product() is
 * documented to return WC_Product|null|false.
 */
final class WcOrderDataTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/wc-order-stubs.php';
    }

    public function testAProductLineCarriesItsProductsSku(): void
    {
        $line = $this->onlyProductLine(new WC_Product('SKU-1'));

        self::assertSame('SKU-1', $line->getSku());
    }

    #[DataProvider('productsThatDidNotLoad')]
    public function testALineWhoseProductDidNotLoadKeepsItsAmountsWithoutASku(WC_Product|false|null $product): void
    {
        $line = $this->onlyProductLine($product);

        self::assertNull($line->getSku());
        self::assertSame('Widget', $line->getName());
        self::assertSame(2.0, $line->getQuantity());
        self::assertSame(100.0, $line->getNetTotal());
        self::assertSame(19.0, $line->getTaxTotal());
    }

    /** @return array<string, array{false|null}> */
    public static function productsThatDidNotLoad(): array
    {
        return ['false' => [false], 'null' => [null]];
    }

    private function onlyProductLine(WC_Product|false|null $product): WcLineData
    {
        $order = new WC_Order([
            'line_item' => [new WC_Order_Item_Product('Widget', 2, '100.00', '19.00', $product)],
        ]);

        $lines = iterator_to_array((new WcOrderData($order, PluginConfig::fromValues([])))->getProductLines(), false);
        self::assertCount(1, $lines);

        return $lines[0];
    }
}
