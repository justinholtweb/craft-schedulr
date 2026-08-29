<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\helpers;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The recurrence arithmetic, with no Craft in it.
 *
 * Pulled out of `services\Schedules` on purpose. Date arithmetic is where scheduling plugins actually
 * go wrong — a monthly rule that lands on the 3rd of March, a weekly rule whose interval is silently
 * ignored, a daily 09:00 send that becomes 08:00 for half the year — and none of those need a database
 * to reproduce. Taking plain arguments rather than a `Schedule` makes every one of them a test.
 *
 * Two rules the whole class follows:
 *
 * 1. **Dates first, times last.** `dates()` returns `Y-m-d` strings and the time of day is applied
 *    afterwards, in the target zone. Stepping an *instant* forward by "one day" drifts by an hour
 *    across a DST boundary, and the symptom — a 09:00 notification arriving at 08:00 from late March —
 *    is one nobody connects back to the recurrence code.
 * 2. **Clamp, never roll over.** "The 31st" in February is the 28th or nothing. PHP's own
 *    `setDate(2026, 2, 31)` gives the 3rd of March without complaint, which sends a monthly
 *    notification on the wrong day in four months of the year.
 */
final class Recurrence
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';
    public const YEARLY = 'yearly';

    /** The last day of the month, whatever it is. */
    public const LAST_DAY = -1;

    /** Hard ceiling on one expansion, whatever the horizon and the rule ask for. */
    public const CEILING = 2000;

    /**
     * The dates a rule lands on, as `Y-m-d` strings in `$zone`.
     *
     * @param int[] $byWeekday 0-6, Sunday first. Only read for a weekly rule.
     * @param int[] $byMonthDay 1-31, plus -1 for the last day. Only read for a monthly rule.
     * @return string[]
     */
    public static function dates(
        string $frequency,
        DateTimeImmutable $from,
        DateTimeImmutable $horizon,
        DateTimeZone $zone,
        int $interval = 1,
        array $byWeekday = [],
        array $byMonthDay = [],
        ?int $limit = null,
    ): array {
        $interval = max(1, $interval);
        $limit ??= self::CEILING;
        $limit = min($limit, self::CEILING);

        // Midnight in the target zone, so a rule starting "today" is not skipped because the clock has
        // already passed the time of day the caller will apply later.
        $cursor = new DateTimeImmutable($from->setTimezone($zone)->format('Y-m-d'), $zone);
        $end = new DateTimeImmutable($horizon->setTimezone($zone)->format('Y-m-d'), $zone);

        $dates = [];

        // A guard rather than a `while (true)`: a malformed rule — a zero interval that slipped past
        // validation, a weekday list that never advances — must terminate, and terminating short is
        // vastly better than a request that never returns.
        $guard = 0;

        while ($cursor <= $end && count($dates) < $limit && $guard++ < 5000) {
            switch ($frequency) {
                case self::DAILY:
                    $dates[] = $cursor->format('Y-m-d');
                    $cursor = $cursor->modify('+' . $interval . ' days');
                    break;

                case self::WEEKLY:
                    if ($byWeekday === []) {
                        return [];
                    }

                    // Every chosen weekday within this week, then jump a whole interval of weeks.
                    // Stepping day by day and testing membership instead would make `interval` mean
                    // nothing at all — "every other Tuesday" would fire every Tuesday.
                    foreach ($byWeekday as $weekday) {
                        $offset = ($weekday - (int)$cursor->format('w') + 7) % 7;
                        $day = $cursor->modify('+' . $offset . ' days');

                        if ($day <= $end && count($dates) < $limit) {
                            $dates[] = $day->format('Y-m-d');
                        }
                    }

                    $cursor = $cursor->modify('+' . $interval . ' weeks')->modify('sunday this week');
                    break;

                case self::MONTHLY:
                    if ($byMonthDay === []) {
                        return [];
                    }

                    foreach ($byMonthDay as $monthDay) {
                        $day = $monthDay === self::LAST_DAY
                            ? $cursor->modify('last day of this month')
                            : $cursor->setDate(
                                (int)$cursor->format('Y'),
                                (int)$cursor->format('n'),
                                // Clamped, not rolled over.
                                min($monthDay, (int)$cursor->format('t')),
                            );

                        if ($day >= $cursor && $day <= $end && count($dates) < $limit) {
                            $dates[] = $day->format('Y-m-d');
                        }
                    }

                    $cursor = $cursor->modify('first day of this month')->modify('+' . $interval . ' months');
                    break;

                case self::YEARLY:
                    $dates[] = $cursor->format('Y-m-d');
                    $cursor = $cursor->modify('+' . $interval . ' years');
                    break;

                default:
                    return [];
            }
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return array_slice($dates, 0, $limit);
    }

    /**
     * A wall-clock date and time in one zone, as an instant in UTC.
     *
     * The one place the conversion happens, so a per-subscriber-timezone send and a site-time send
     * cannot disagree about what "09:00" meant.
     */
    public static function instant(string $date, int $hour, int $minute, DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable(
            sprintf('%s %02d:%02d:00', $date, $hour, $minute),
            $zone,
        ))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether a local time falls inside a quiet window.
     *
     * A window that wraps midnight — 22:00 to 07:00 — is the usual answer, so it is the branch written
     * first rather than the edge case bolted on afterwards.
     */
    public static function isQuiet(int $minutes, int $startMinutes, int $endMinutes): bool
    {
        if ($startMinutes === $endMinutes) {
            return false;
        }

        return $startMinutes < $endMinutes
            ? ($minutes >= $startMinutes && $minutes < $endMinutes)
            : ($minutes >= $startMinutes || $minutes < $endMinutes);
    }

    /**
     * Moves an instant landing inside quiet hours to the end of them.
     *
     * Deferred, never dropped. Dropping would mean a daily 07:00 notification silently never sending on
     * a site whose quiet hours run to 08:00, with nothing anywhere to say so.
     */
    public static function deferPastQuiet(
        DateTimeImmutable $utc,
        DateTimeZone $zone,
        int $startMinutes,
        int $endMinutes,
    ): DateTimeImmutable {
        $local = $utc->setTimezone($zone);
        $minutes = ((int)$local->format('G') * 60) + (int)$local->format('i');

        if (!self::isQuiet($minutes, $startMinutes, $endMinutes)) {
            return $utc;
        }

        $target = $local->setTime(intdiv($endMinutes, 60), $endMinutes % 60);

        if ($target <= $local) {
            $target = $target->modify('+1 day');
        }

        return $target->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * "HH:MM" as minutes past midnight, or null when it is not a time.
     */
    public static function minutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hour = (int)$m[1];
        $minute = (int)$m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
