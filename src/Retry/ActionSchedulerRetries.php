<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

/**
 * The later attempts as Action Scheduler actions, which WooCommerce ships. They
 * run from WP-Cron, which runs on page loads, so on a quiet shop an attempt runs
 * late unless a real cron calls wp-cron.php or `wp action-scheduler run`.
 */
final class ActionSchedulerRetries implements RetryScheduler
{
    public const HOOK = 'beliq_retry_invoice';
    public const GROUP = 'beliq';

    public function scheduleAt(int $orderId, int $at): void
    {
        as_unschedule_all_actions(self::HOOK, self::args($orderId), self::GROUP);
        as_schedule_single_action($at, self::HOOK, self::args($orderId), self::GROUP);
    }

    public function unschedule(int $orderId): void
    {
        as_unschedule_all_actions(self::HOOK, self::args($orderId), self::GROUP);
    }

    /** @return array{order_id: int} */
    private static function args(int $orderId): array
    {
        return ['order_id' => $orderId];
    }
}
