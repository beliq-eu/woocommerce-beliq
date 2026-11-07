<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Retry\InvoiceAttempts;
use Throwable;
use WC_Order;

/**
 * Generates a beliq invoice when an order reaches the configured status
 * (processing or completed). The merchant picks which status fires; the handler
 * skips the rest. The first attempt runs here; when beliq cannot be reached or
 * is unavailable, InvoiceAttempts schedules a later one, and every failure shows
 * on the order. A generation failure must never break the status transition
 * that triggered it, so the handler swallows and logs every error through the
 * WooCommerce logger rather than letting it propagate.
 */
final class OrderStatusTrigger
{
    public function __construct(
        private readonly WooPluginConfigProvider $configProvider,
        private readonly DocumentStore $store,
        private readonly InvoiceAttempts $attempts,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 10, 4);
    }

    public function onStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
    {
        try {
            $config = $this->configProvider->get();
            if (!$config->enabled || $config->apiKey === '') {
                return;
            }
            if ($to !== $config->triggerEvent) {
                return;
            }

            // The automatic trigger never overwrites an existing document, so a
            // re-fired status is a no-op.
            if ($this->store->has($order)) {
                return;
            }

            $this->attempts->first($orderId);
        } catch (Throwable $exception) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    'beliq invoice generation failed: ' . $exception->getMessage(),
                    ['source' => 'beliq', 'order_id' => $orderId],
                );
            }
        }
    }
}
