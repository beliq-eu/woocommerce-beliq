<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/** One attempt at an order's automatic beliq invoice. */
interface InvoiceGeneration
{
    /**
     * Throws the failure when the attempt failed. Returns false when the plugin
     * is off, so no attempt was made, and true otherwise: the invoice is stored,
     * the order already had one, or the business-only setting skips the order.
     */
    public function generate(int $orderId, bool $laterAttempt): bool;
}
