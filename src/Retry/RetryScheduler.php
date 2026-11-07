<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/** The one pending action per order that runs its next attempt. */
interface RetryScheduler
{
    /** Replaces any pending action of the order with one due at $at. */
    public function scheduleAt(int $orderId, int $at): void;

    public function unschedule(int $orderId): void;
}
