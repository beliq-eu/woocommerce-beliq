<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use PHPUnit\Framework\TestCase;

/**
 * The provider's job beyond PluginConfig::fromValues is coercing WooCommerce's
 * 'yes' / 'no' checkbox strings into real booleans. These assert that coercion
 * (including the trap that 'no' is a truthy non-empty string) and that the two
 * WooCommerce-only meta keys map through.
 */
final class WooPluginConfigProviderTest extends TestCase
{
    public function testYesNoCheckboxStringsCoerceToBooleans(): void
    {
        $config = WooPluginConfigProvider::fromRawSettings([
            'enabled' => 'yes',
            'businessOnly' => 'no',
        ]);

        self::assertTrue($config->enabled);
        // The regression this guards: fromValues alone reads 'no' as boolean true
        // because it is a non-empty string; the provider must hand it a real false.
        self::assertFalse($config->businessOnly);
    }

    public function testNoCheckboxKeysFallBackToPluginDefaults(): void
    {
        $config = WooPluginConfigProvider::fromRawSettings([]);

        self::assertFalse($config->enabled);
        self::assertTrue($config->businessOnly);
    }

    public function testAlternativeTruthyCheckboxValues(): void
    {
        foreach (['yes', 'YES', '1', 1, true] as $truthy) {
            $config = WooPluginConfigProvider::fromRawSettings(['enabled' => $truthy]);
            self::assertTrue($config->enabled, sprintf('value %s should enable', var_export($truthy, true)));
        }
    }

    public function testNonYesCheckboxValuesAreFalse(): void
    {
        foreach (['no', 'NO', '', '0', 0, false, 'true'] as $falsy) {
            $config = WooPluginConfigProvider::fromRawSettings(['businessOnly' => $falsy]);
            self::assertFalse($config->businessOnly, sprintf('value %s should not enable', var_export($falsy, true)));
        }
    }

    public function testBuyerMetaKeysMapThrough(): void
    {
        $config = WooPluginConfigProvider::fromRawSettings([
            'buyerVatMetaKey' => '_billing_vat',
            'buyerReferenceMetaKey' => '  _leitweg_id  ',
        ]);

        self::assertSame('_billing_vat', $config->buyerVatMetaKey);
        self::assertSame('_leitweg_id', $config->buyerReferenceMetaKey);
    }

    public function testMetaKeysDefaultToEmpty(): void
    {
        $config = WooPluginConfigProvider::fromRawSettings([]);

        self::assertSame('', $config->buyerVatMetaKey);
        self::assertSame('', $config->buyerReferenceMetaKey);
    }

    public function testRemainingValuesStillMapThroughFromValues(): void
    {
        $config = WooPluginConfigProvider::fromRawSettings([
            'enabled' => 'yes',
            'apiKey' => 'key-123',
            'standard' => 'xrechnung',
            'triggerEvent' => 'completed',
        ]);

        self::assertSame('key-123', $config->apiKey);
        self::assertSame('xrechnung', $config->standard);
        self::assertSame('completed', $config->triggerEvent);
    }
}
