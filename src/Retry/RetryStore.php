<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

use Beliq\Core\Retry\RetryState;

/** Where an order's later attempts stand, kept with the order. */
interface RetryStore
{
    public function find(int $orderId): ?RetryState;

    public function lastError(int $orderId): ?string;

    public function save(int $orderId, RetryState $state, ?string $lastError): void;

    public function delete(int $orderId): void;
}
