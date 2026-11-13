<?php
declare(strict_types=1);

final class Billing
{
    public static function subscription(int $companyId): ?array
    {
        return Db::one('SELECT * FROM subscriptions WHERE company_id = ?', [$companyId]);
    }

    /** Active, or still inside the free trial. When false the company is read-only (except clocking and billing). */
    public static function inGoodStanding(?array $sub): bool
    {
        if (!$sub) {
            return true; // companies created before billing existed
        }
        if ($sub['status'] === 'active') {
            return true;
        }
        if ($sub['status'] === 'trialing') {
            return !$sub['trial_ends_at'] || strtotime($sub['trial_ends_at'] . ' UTC') > time();
        }
        if ($sub['status'] === 'past_due') {
            // a week's grace after the paid-through date
            return $sub['current_period_end'] && strtotime($sub['current_period_end'] . ' UTC') + 7 * 86400 > time();
        }
        // canceled: usable until the end of what was paid for
        return $sub['current_period_end'] && strtotime($sub['current_period_end'] . ' UTC') > time();
    }
}
