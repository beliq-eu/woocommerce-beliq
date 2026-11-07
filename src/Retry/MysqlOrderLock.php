<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/**
 * A named lock in the shop's database (MySQL and MariaDB's GET_LOCK), which
 * every PHP process of the shop shares. The database releases it when the
 * connection ends, so a process killed mid-call holds nothing afterwards.
 */
final class MysqlOrderLock implements OrderLock
{
    public function acquire(int $orderId): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock is not data, so there is nothing to cache.
        return $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::name($orderId))) === '1';
    }

    public function release(int $orderId): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see acquire().
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($orderId)));
    }

    private static function name(int $orderId): string
    {
        global $wpdb;

        return $wpdb->prefix . 'beliq_invoice_' . $orderId;
    }
}
