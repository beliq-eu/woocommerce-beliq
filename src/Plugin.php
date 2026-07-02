<?php declare(strict_types=1);

namespace Beliq\WooCommerce;

use Beliq\WooCommerce\Admin\OrderActions;
use Beliq\WooCommerce\Admin\OrderMetabox;
use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Integration\InvoiceIntegration;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use Beliq\WooCommerce\Order\OrderStatusTrigger;

/**
 * Wires the plugin's runtime once WooCommerce is loaded: registers the settings
 * integration, the order-status trigger that generates on the configured status,
 * and the admin surfaces (metabox, download, and manual regenerate). Everything
 * here is plain hook registration; the work lives in the collaborators.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $configProvider = new WooPluginConfigProvider();
        $store = new DocumentStore();
        $generator = new InvoiceGenerator($store);

        add_filter('woocommerce_integrations', [$this, 'registerIntegration']);
        (new OrderStatusTrigger($configProvider, $generator))->register();
        (new OrderActions($configProvider, $generator, $store))->register();
        (new OrderMetabox($store))->register();
    }

    /**
     * @param array<int, class-string> $integrations
     * @return array<int, class-string>
     */
    public function registerIntegration(array $integrations): array
    {
        $integrations[] = InvoiceIntegration::class;

        return $integrations;
    }
}
