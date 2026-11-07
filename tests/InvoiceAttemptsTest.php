<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests;

use Beliq\Core\Exception\BeliqApiException;
use Beliq\Core\Exception\BeliqTransportException;
use Beliq\Core\Retry\RetrySchedule;
use Beliq\WooCommerce\Retry\InvoiceAttempts;
use Beliq\WooCommerce\Tests\Support\MemoryLock;
use Beliq\WooCommerce\Tests\Support\MemoryRetries;
use Beliq\WooCommerce\Tests\Support\RecordingRecorder;
use Beliq\WooCommerce\Tests\Support\ScriptedGeneration;
use PHPUnit\Framework\TestCase;

/**
 * The later invoice below WordPress: the real schedule and attempt runner over
 * an in-memory order meta and action queue, with a generation that answers from
 * a script. The same paths run against a real store in smoke/.
 */
final class InvoiceAttemptsTest extends TestCase
{
    private const T0 = 1_800_000_000;
    private const ORDER = 42;

    private int $now = self::T0;

    private MemoryRetries $retries;

    private MemoryLock $lock;

    private ScriptedGeneration $generation;

    private RecordingRecorder $recorder;

    protected function setUp(): void
    {
        $this->retries = new MemoryRetries();
        $this->lock = new MemoryLock();
        $this->generation = new ScriptedGeneration(fn (): int => $this->now);
        $this->recorder = new RecordingRecorder();
    }

    public function testAnOrderPaidDuringAnOutageGetsItsInvoiceAfterIt(): void
    {
        $down = new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503, null, 30);
        $this->generation->script = [$down, $down, $down, $down, true];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(70);

        self::assertSame([0, 120, 300, 600, 840], $this->generation->callOffsets(self::T0));
        self::assertSame(1, $this->generation->successes);
        self::assertNull($this->retries->find(self::ORDER));
        self::assertSame([], $this->retries->pending);
        self::assertSame('clear', end($this->recorder->events));
        self::assertSame('DATABASE_UNAVAILABLE (HTTP 503)', $this->recorder->failures[0]['description']);
        self::assertSame(self::T0 + 120, $this->recorder->failures[0]['nextAttemptAt']);
    }

    public function testTheOrderDataBeingWrongIsNotRetriedAndShowsOnTheOrder(): void
    {
        $this->generation->script = [new BeliqApiException('invalid', 'INVALID_INVOICE', 422)];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(70);

        self::assertCount(1, $this->generation->calls);
        self::assertNull($this->retries->find(self::ORDER));
        self::assertSame([], $this->retries->pending);
        self::assertSame([[
            'orderId' => self::ORDER,
            'description' => 'INVALID_INVOICE (HTTP 422)',
            'at' => self::T0,
            'nextAttemptAt' => null,
        ]], $this->recorder->failures);
    }

    public function testAnOutageLongerThanTheWindowEndsOnTheOrderWithNoRetryPending(): void
    {
        $this->generation->script = [new BeliqTransportException(BeliqTransportException::CONNECT_FAILED, 'refused')];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(90);

        self::assertSame([0, 120, 300, 600, 840, 1800, 3600], $this->generation->callOffsets(self::T0));
        self::assertSame([], $this->retries->pending);
        $last = $this->recorder->failures[array_key_last($this->recorder->failures)];
        self::assertSame('connection: connect_failed', $last['description']);
        self::assertNull($last['nextAttemptAt']);
    }

    public function testTheOrderRequestsOwnTimeoutIsRetriedOnceAndALaterTimeoutIsNot(): void
    {
        $timeout = new BeliqTransportException(BeliqTransportException::TIMEOUT, 'timed out');
        $this->generation->script = [$timeout, $timeout];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(70);

        self::assertSame([false, true], array_column($this->generation->calls, 'laterAttempt'));
        self::assertSame([], $this->retries->pending);
    }

    public function testARetryOfARequestThatMayHaveRunIsDroppedWhenTheQueueRunsTooLate(): void
    {
        $this->generation->script = [new BeliqApiException('engine', 'ENGINE_UNAVAILABLE', 503), true];

        $this->attempts()->first(self::ORDER);
        // WP-Cron did not run for 20 minutes on a quiet shop.
        $this->now = self::T0 + 1200;
        foreach ($this->retries->takeDue($this->now) as $orderId) {
            $this->attempts()->later($orderId);
        }

        self::assertCount(1, $this->generation->calls);
        self::assertNull($this->retries->find(self::ORDER));
        self::assertSame([], $this->retries->pending);
        $last = $this->recorder->failures[array_key_last($this->recorder->failures)];
        self::assertSame('ENGINE_UNAVAILABLE (HTTP 503)', $last['description']);
        self::assertNull($last['nextAttemptAt']);
    }

    public function testAFirstAttemptThatSucceedsLeavesTheOrderAsItIs(): void
    {
        $this->attempts()->first(self::ORDER);

        self::assertSame(1, $this->generation->successes);
        self::assertSame([], $this->recorder->events);
        self::assertNull($this->retries->find(self::ORDER));
        self::assertSame([], $this->retries->pending);
    }

    public function testEngineUnavailableIsRetriedThreeTimesAtMost(): void
    {
        $this->generation->script = [new BeliqApiException('engine', 'ENGINE_UNAVAILABLE', 503)];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(70);

        self::assertSame([0, 120, 300, 600], $this->generation->callOffsets(self::T0));
    }

    public function testTheNextAttemptIsScheduledBeforeTheCallSoAKilledProcessLeavesItInPlace(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];
        $killed = null;
        $this->generation->during = function (int $call) use (&$killed): void {
            if ($call === 1) {
                $killed = clone $this->retries;
            }
        };

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(3);
        self::assertSame(1, $this->generation->successes);

        // Restart from what the killed process left: a leased attempt, due again later.
        self::assertInstanceOf(MemoryRetries::class, $killed);
        $this->retries = $killed;
        $leased = $this->retries->find(self::ORDER);
        self::assertNotNull($leased);
        self::assertSame(1, $leased->laterAttempts);
        self::assertSame($leased->nextAttemptAt, $this->retries->pending[self::ORDER]);
        self::assertGreaterThanOrEqual(self::T0 + 120 + RetrySchedule::ATTEMPT_LEASE_SECONDS, $leased->nextAttemptAt);

        $this->lock = new MemoryLock();
        $this->generation->script = [true];
        $this->generation->during = null;
        $this->now = $leased->nextAttemptAt;
        foreach ($this->retries->takeDue($this->now) as $orderId) {
            $this->attempts()->later($orderId);
        }

        self::assertSame(2, $this->generation->successes);
        self::assertNull($this->retries->find(self::ORDER));
    }

    public function testTwoRunsAtOnceForOneOrderMakeOneAttempt(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];
        $this->attempts()->first(self::ORDER);

        // A second action for the same order runs while the first one's call is
        // in flight (two queue runners, or an action scheduled twice).
        $this->generation->during = function (int $call): void {
            if ($call === 1) {
                $this->attempts()->later(self::ORDER);
            }
        };
        $this->now = self::T0 + 120;
        $this->attempts()->later(self::ORDER);

        self::assertCount(2, $this->generation->calls);
        self::assertSame(1, $this->generation->successes);
    }

    public function testTwoStatusChangesAtOnceMakeOneFirstAttempt(): void
    {
        // A payment gateway's webhook and the customer's return can move the
        // order to processing in two requests at once.
        $this->generation->during = function (int $call): void {
            if ($call === 0) {
                $this->attempts()->first(self::ORDER);
            }
        };

        $this->attempts()->first(self::ORDER);

        self::assertCount(1, $this->generation->calls);
    }

    public function testAReFiredTriggerWhileARetryIsPendingStartsNoSecondChain(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];

        $this->attempts()->first(self::ORDER);
        $this->now = self::T0 + 10;
        $this->attempts()->first(self::ORDER);

        self::assertCount(1, $this->generation->calls);
        self::assertSame([self::ORDER => self::T0 + 120], $this->retries->pending);
    }

    public function testAnActionThatComesEarlyLeavesTheDueOneInPlace(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];
        $this->attempts()->first(self::ORDER);

        $this->now = self::T0 + 30;
        $this->attempts()->later(self::ORDER);

        self::assertCount(1, $this->generation->calls);
        self::assertSame([self::ORDER => self::T0 + 120], $this->retries->pending);
    }

    public function testAPluginSwitchedOffDuringTheWindowEndsTheRetry(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), false];

        $this->attempts()->first(self::ORDER);
        $this->runTheQueueEveryMinuteFor(70);

        self::assertCount(2, $this->generation->calls);
        self::assertSame([], $this->retries->pending);
        self::assertSame([self::ORDER], $this->recorder->stopped);
    }

    private function runTheQueueEveryMinuteFor(int $minutes): void
    {
        for ($minute = 1; $minute <= $minutes; $minute++) {
            $this->now = self::T0 + 60 * $minute;
            foreach ($this->retries->takeDue($this->now) as $orderId) {
                $this->attempts()->later($orderId);
            }
        }
    }

    private function attempts(): InvoiceAttempts
    {
        return new InvoiceAttempts(
            $this->retries,
            $this->retries,
            $this->lock,
            $this->generation,
            $this->recorder,
            fn (): int => $this->now,
        );
    }
}
