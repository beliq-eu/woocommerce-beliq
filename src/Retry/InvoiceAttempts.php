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
        // Another process is attempting the order. Its pending action was the one
        // that brought this run, so one is queued again, in case that process
        // dies before it settles; if it settles first, the queued action finds
        // nothing due and does nothing.
        if (!$this->lock->acquire($orderId)) {
            $this->scheduler->scheduleAt($orderId, ($this->now)() + RetrySchedule::ATTEMPT_LEASE_SECONDS);

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

            // No attempt is left: the last one was claimed but its process died
            // mid-call, or a retry of a request that may have run is due inside
            // the first 15 minutes and its action ran too late to start it there.
            if (RetrySchedule::attemptsExhausted($seen) || RetrySchedule::expired($seen, $now)) {
                $this->giveUp($orderId, $now);

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

    private function giveUp(int $orderId, int $now): void
    {
        $lastError = $this->store->lastError($orderId) ?? 'no answer';
        $this->store->delete($orderId);
        $this->scheduler->unschedule($orderId);
        $this->recorder->recordFailure($orderId, $lastError, $now, null);
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
        // Only a later attempt follows a failure the order shows; a first
        // attempt that succeeds leaves the order as it is.
        if (!$laterAttempt) {
            return;
        }
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
