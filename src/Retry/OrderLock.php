<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/** Keeps two PHP processes from attempting one order's invoice at once. */
interface OrderLock
{
    /** False when another process holds the order; it does not wait. */
    public function acquire(int $orderId): bool;

    public function release(int $orderId): void;
}
