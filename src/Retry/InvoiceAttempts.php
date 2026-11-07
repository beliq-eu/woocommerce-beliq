<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

use Beliq\Core\Retry\RetrySchedule;
use Beliq\Core\Retry\RetryState;

/**
 * Runs the attempts at an order's automatic invoice: the first inside the
 * status change, the later ones from an Action Scheduler action. Each attempt
 * holds the order's lock, so two processes never call beliq for one order at
 * once, and schedules the next attempt before it calls beliq, so a process
 * killed mid-call leaves a due attempt behind instead of nothing. Every failure
 * is recorded on the order, retried or not.
 */
final class InvoiceAttempts
{
    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now
     * @param (\Closure(int, string): void)|null $log
     */
    public function __construct(
        private readonly RetryStore $store,
        private readonly RetryScheduler $scheduler,
        private readonly OrderLock $lock,
        private readonly InvoiceGeneration $generation,
        private readonly FailureRecorder $recorder,
        ?\Closure $now = null,
        private readonly ?\Closure $log = null,
    ) {
        $this->now = $now ?? time(...);
    }

    public function first(int $orderId): void
    {
        if (!$this->lock->acquire($orderId)) {
            return;
        }
        try {
            if ($this->store->find($orderId) !== null) {
                return;
            }
            $state = RetrySchedule::start(($this->now)());
            $this->store->save($orderId, $state, null);
            $this->scheduler->scheduleAt($orderId, $state->nextAttemptAt);

            $this->attempt($orderId, false, $state);
        } finally {
            $this->lock->release($orderId);
        }
    }

    public function later(int $orderId): void
    {
        if (!$this->lock->acquire($orderId)) {
            return;
        }
        try {
            $seen = $this->store->find($orderId);
            if ($seen === null) {
                return;
            }
            $now = ($this->now)();
            // An action that runs early (scheduled twice, or by hand from the
            // Action Scheduler screen) puts the due one back and does nothing.
            if ($seen->nextAttemptAt > $now) {
                $this->scheduler->scheduleAt($orderId, $seen->nextAttemptAt);

                return;
            }
            // The last attempt was claimed but never settled: its process died mid-call.
            if (RetrySchedule::attemptsExhausted($seen)) {
                $this->store->delete($orderId);
                $this->scheduler->unschedule($orderId);
                $this->recorder->recordFailure($orderId, $this->store->lastError($orderId) ?? 'no answer', $now, null);

                return;
            }

            $claimed = RetrySchedule::claim($seen, $now);
            $this->store->save($orderId, $claimed, $this->store->lastError($orderId));
            $this->scheduler->scheduleAt($orderId, $claimed->nextAttemptAt);

            $this->attempt($orderId, true, $claimed);
        } finally {
            $this->lock->release($orderId);
        }
    }

    private function attempt(int $orderId, bool $laterAttempt, RetryState $state): void
    {
        try {
            $attempted = $this->generation->generate($orderId, $laterAttempt);
        } catch (\Throwable $failure) {
            $this->settleFailure($orderId, $state, $failure);

            return;
        }

        $this->store->delete($orderId);
        $this->scheduler->unschedule($orderId);
        if ($attempted) {
            $this->recorder->clear($orderId);
        } else {
            $this->recorder->stopRetrying($orderId);
        }
    }

    private function settleFailure(int $orderId, RetryState $state, \Throwable $failure): void
    {
        $now = ($this->now)();
        $description = RetrySchedule::describe($failure);
        $next = RetrySchedule::afterFailure($state, $failure, $now);

        if ($this->log !== null) {
            ($this->log)($orderId, $failure->getMessage());
        }

        if ($next === null) {
            $this->store->delete($orderId);
            $this->scheduler->unschedule($orderId);
        } else {
            $this->store->save($orderId, $next, $description);
            $this->scheduler->scheduleAt($orderId, $next->nextAttemptAt);
        }
        $this->recorder->recordFailure($orderId, $description, $now, $next?->nextAttemptAt);
    }
}
