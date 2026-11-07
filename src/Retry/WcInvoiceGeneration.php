<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use RuntimeException;
use WC_Order;

/**
 * An attempt through InvoiceGenerator, without force, so an order that has an
 * invoice by the time of the attempt (a manual generate, say) is left as it is.
 * The first attempt runs in the status change a shop admin or a payment webhook
 * waits on and keeps the short timeout; a later attempt gets the longer one.
 */
final class WcInvoiceGeneration implements InvoiceGeneration
{
    public function __construct(
        private readonly WooPluginConfigProvider $configProvider,
        private readonly InvoiceGenerator $orderRequest,
        private readonly InvoiceGenerator $later,
        private readonly DocumentStore $store,
    ) {
    }

    public function generate(int $orderId, bool $laterAttempt): bool
    {
        $config = $this->configProvider->get();
        if (!$config->enabled || $config->apiKey === '') {
            return false;
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            throw new RuntimeException('Order not found.');
        }

        ($laterAttempt ? $this->later : $this->orderRequest)->generate($order, $config, force: false);

        // False when the business-only setting skipped the order.
        return $this->store->has($order);
    }
}
