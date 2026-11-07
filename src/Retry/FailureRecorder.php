<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/** Keeps the last failure of an order's automatic invoice visible on the order. */
interface FailureRecorder
{
    /** @param int|null $nextAttemptAt when the next attempt is due, or null when none follows */
    public function recordFailure(int $orderId, string $description, int $at, ?int $nextAttemptAt): void;

    /** The invoice was made: nothing is left to show. */
    public function clear(int $orderId): void;

    /** The failure stays, but no attempt follows it any more. */
    public function stopRetrying(int $orderId): void;
}
