<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\WooCommerce\Retry\OrderLock;

/** One holder per order at a time, as MySQL's GET_LOCK gives across processes. */
final class MemoryLock implements OrderLock
{
    /** @var array<int, true> */
    private array $held = [];

    public function acquire(int $orderId): bool
    {
        if (isset($this->held[$orderId])) {
            return false;
        }
        $this->held[$orderId] = true;

        return true;
    }

    public function release(int $orderId): void
    {
        unset($this->held[$orderId]);
    }
}
