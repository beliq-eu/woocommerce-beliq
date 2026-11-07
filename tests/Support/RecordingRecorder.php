<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\WooCommerce\Retry\FailureRecorder;

final class RecordingRecorder implements FailureRecorder
{
    /** @var list<array{orderId: int, description: string, at: int, nextAttemptAt: ?int}> */
    public array $failures = [];

    /** @var list<int> */
    public array $cleared = [];

    /** @var list<int> */
    public array $stopped = [];

    /** @var list<string> */
    public array $events = [];

    public function recordFailure(int $orderId, string $description, int $at, ?int $nextAttemptAt): void
    {
        $this->failures[] = ['orderId' => $orderId, 'description' => $description, 'at' => $at, 'nextAttemptAt' => $nextAttemptAt];
        $this->events[] = 'failure';
    }

    public function clear(int $orderId): void
    {
        $this->cleared[] = $orderId;
        $this->events[] = 'clear';
    }

    public function stopRetrying(int $orderId): void
    {
        $this->stopped[] = $orderId;
        $this->events[] = 'stop';
    }
}
