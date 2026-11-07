<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\Core\Retry\RetryState;
use Beliq\WooCommerce\Retry\RetryScheduler;
use Beliq\WooCommerce\Retry\RetryStore;

/**
 * The order meta and the Action Scheduler queue, in memory: what a shop keeps
 * between two PHP processes. A copy of it taken mid-call is what a process
 * killed at that moment leaves behind.
 */
final class MemoryRetries implements RetryStore, RetryScheduler
{
    /** @var array<int, array{state: RetryState, lastError: ?string}> */
    public array $rows = [];

    /** @var array<int, int> order id => when its one pending action is due */
    public array $pending = [];

    public function find(int $orderId): ?RetryState
    {
        return $this->rows[$orderId]['state'] ?? null;
    }

    public function lastError(int $orderId): ?string
    {
        return $this->rows[$orderId]['lastError'] ?? null;
    }

    public function save(int $orderId, RetryState $state, ?string $lastError): void
    {
        $this->rows[$orderId] = ['state' => $state, 'lastError' => $lastError];
    }

    public function delete(int $orderId): void
    {
        unset($this->rows[$orderId]);
    }

    public function scheduleAt(int $orderId, int $at): void
    {
        $this->pending[$orderId] = $at;
    }

    public function unschedule(int $orderId): void
    {
        unset($this->pending[$orderId]);
    }

    /** Takes the due actions off the queue, as Action Scheduler claims them, and returns their orders. */
    public function takeDue(int $now): array
    {
        $due = array_keys(array_filter($this->pending, static fn (int $at): bool => $at <= $now));
        foreach ($due as $orderId) {
            unset($this->pending[$orderId]);
        }

        return $due;
    }
}
