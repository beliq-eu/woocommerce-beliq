<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Order;

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use Throwable;
use WC_Order;

/**
 * Generates a beliq invoice when an order reaches the configured status
 * (processing or completed). The merchant picks which status fires; the handler
 * skips the rest. A generation failure must never break the status transition
 * that triggered it, so the handler swallows and logs every error through the
 * WooCommerce logger rather than letting it propagate.
 */
final class OrderStatusTrigger
{
    public function __construct(
        private readonly WooPluginConfigProvider $configProvider,
        private readonly InvoiceGenerator $generator,
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

            $this->generator->generate($order, $config, force: false);
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
