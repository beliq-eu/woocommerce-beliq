<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Tests\Support;

use Beliq\WooCommerce\Retry\InvoiceGeneration;

/** Answers each call from a script: true is a stored invoice, false the plugin off, a Throwable a failure. The last entry repeats. */
final class ScriptedGeneration implements InvoiceGeneration
{
    /** @var list<\Throwable|bool> */
    public array $script = [true];

    /** @var (\Closure(int): void)|null */
    public ?\Closure $during = null;

    /** @var list<array{orderId: int, at: int, laterAttempt: bool}> */
    public array $calls = [];

    public int $successes = 0;

    /** @param \Closure(): int $now */
    public function __construct(private readonly \Closure $now)
    {
    }

    public function generate(int $orderId, bool $laterAttempt): bool
    {
        $call = count($this->calls);
        $this->calls[] = ['orderId' => $orderId, 'at' => ($this->now)(), 'laterAttempt' => $laterAttempt];
        if ($this->during !== null) {
            ($this->during)($call);
        }
        $outcome = $this->script[min($call, count($this->script) - 1)];
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        if ($outcome) {
            $this->successes++;
        }

        return $outcome;
    }

    /** @return list<int> */
    public function callOffsets(int $t0): array
    {
        return array_map(static fn (array $call): int => $call['at'] - $t0, $this->calls);
    }
}
