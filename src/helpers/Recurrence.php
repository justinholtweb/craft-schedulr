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
     * `$from` is the rule's **anchor**, not "where to start looking". Everything that gives a rule its
     * shape is measured from it: which weeks "every other week" means, which years a yearly rule lands
     * in, and how many times a "stop after 10" rule has already fired. `$notBefore` is where output
     * starts. Keeping the two apart is the whole fix for a family of bugs that all looked like one
     * line — `from = max(startDate, today)` — because the runner expands every minute, and a rule
     * re-anchored at today on every pass:
     *
     * - fires "every other week" weekly, because each pass's first week is this week;
     * - materialises a yearly rule on today's date, every day;
     * - never stops a `maxOccurrences` rule, because the count starts again at zero each pass.
     *
     * So the walk always begins at the anchor and *counts* every date it lands on toward `$limit`,
     * including the ones before `$notBefore` that it does not return. Whole periods before
     * `$notBefore` are skipped arithmetically when there is no limit to count against, so a daily rule
     * anchored five years ago costs the same to expand as one anchored yesterday.
     *
     * @param int[] $byWeekday 0-6, Sunday first. Only read for a weekly rule.
     * @param int[] $byMonthDay 1-31, plus -1 for the last day. Only read for a monthly rule.
     * @param int|null $limit The rule's total number of occurrences, counted from the anchor.
     * @param DateTimeImmutable|null $notBefore Dates before this one (in `$zone`) are counted, not returned.
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
        ?DateTimeImmutable $notBefore = null,
    ): array {
        $interval = max(1, $interval);

        if (!in_array($frequency, [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY], true)) {
            return [];
        }

        // Better than falling back to "every day", which is how a half-configured rule becomes a
        // notification storm.
        if (($frequency === self::WEEKLY && $byWeekday === []) || ($frequency === self::MONTHLY && $byMonthDay === [])) {
            return [];
        }

        // Every date below is a calendar date, held as midnight **UTC** whatever `$zone` is. The zone is
        // only used to decide which calendar date an instant falls on; after that the arithmetic is on
        // dates, where a day is always a day. Doing it on zone-local midnights instead lets a DST change
        // shave an hour off a "day" and turn `diff()->days` into an off-by-one.
        $anchor = self::day($from, $zone);
        $end = self::day($horizon, $zone);
        $floor = $notBefore !== null ? self::day($notBefore, $zone) : $anchor;

        if ($floor < $anchor) {
            $floor = $anchor;
        }

        $index = $limit === null ? self::firstPeriod($frequency, $anchor, $floor, $interval) : 0;

        $dates = [];
        $counted = 0;

        // A guard rather than a `while (true)`: a malformed rule must terminate, and terminating short
        // is vastly better than a request that never returns. Generous, because a counted rule walks
        // every period from its anchor.
        $guard = 0;

        while ($guard++ < 100000) {
            $candidates = self::period($frequency, $anchor, $index, $interval, $byWeekday, $byMonthDay);

            if ($candidates === null) {
                break;
            }

            [$periodStart, $days] = $candidates;

            if ($periodStart > $end) {
                break;
            }

            foreach ($days as $day) {
                if ($day < $anchor || $day > $end) {
                    continue;
                }

                if ($limit !== null && $counted >= $limit) {
                    break 2;
                }

                $counted++;

                if ($day >= $floor) {
                    $dates[] = $day->format('Y-m-d');

                    if (count($dates) >= self::CEILING) {
                        break 2;
                    }
                }
            }

            $index++;
        }

        return $dates;
    }

    /**
     * A calendar date in `$zone`, as midnight UTC.
     */
    private static function day(DateTimeImmutable $value, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($value->setTimezone($zone)->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * The period the floor falls in, so the walk can start there.
     *
     * Only used when nothing is being counted. Counting what a skipped weekly or monthly period
     * *would* have produced means re-deriving the clamping and the first-period cut-off, and the walk
     * already does both correctly — and a counted rule is bounded by its own limit anyway. Rounds
     * down, so it can only ever land on or before the floor's period, never past it.
     */
    private static function firstPeriod(
        string $frequency,
        DateTimeImmutable $anchor,
        DateTimeImmutable $floor,
        int $interval,
    ): int {
        if ($floor <= $anchor) {
            return 0;
        }

        $elapsed = match ($frequency) {
            self::DAILY => (int)$anchor->diff($floor)->days,
            self::WEEKLY => intdiv((int)self::weekStart($anchor)->diff(self::weekStart($floor))->days, 7),
            self::MONTHLY => (((int)$floor->format('Y') - (int)$anchor->format('Y')) * 12)
                + ((int)$floor->format('n') - (int)$anchor->format('n')),
            default => (int)$floor->format('Y') - (int)$anchor->format('Y'),
        };

        return intdiv(max(0, $elapsed), $interval);
    }

    /**
     * The `$index`th period of a rule: when it starts, and the dates in it, sorted and unique.
     *
     * @param int[] $byWeekday
     * @param int[] $byMonthDay
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable[]}|null
     */
    private static function period(
        string $frequency,
        DateTimeImmutable $anchor,
        int $index,
        int $interval,
        array $byWeekday,
        array $byMonthDay,
    ): ?array {
        $step = $index * $interval;

        switch ($frequency) {
            case self::DAILY:
                $day = $anchor->modify('+' . $step . ' days');

                return [$day, [$day]];

            case self::WEEKLY:
                // Weeks run Sunday to Saturday and the phase comes from the anchor's week, so "every
                // other Tuesday" is every other Tuesday counted from the week the rule started — not
                // from whichever week the runner happened to look in.
                $start = self::weekStart($anchor)->modify('+' . ($step * 7) . ' days');
                $days = [];

                foreach ($byWeekday as $weekday) {
                    $weekday = (int)$weekday;

                    if ($weekday < 0 || $weekday > 6) {
                        continue;
                    }

                    $days[$weekday] = $start->modify('+' . $weekday . ' days');
                }

                ksort($days);

                return [$start, array_values($days)];

            case self::MONTHLY:
                $start = self::ymd((int)$anchor->format('Y'), (int)$anchor->format('n') + $step, 1);
                $last = (int)$start->format('t');
                $days = [];

                foreach ($byMonthDay as $monthDay) {
                    $monthDay = (int)$monthDay;
                    // Clamped, not rolled over: "the 31st" in February is the 28th.
                    $dayOfMonth = $monthDay === self::LAST_DAY ? $last : min(max(1, $monthDay), $last);
                    $days[$dayOfMonth] = $start->setDate((int)$start->format('Y'), (int)$start->format('n'), $dayOfMonth);
                }

                ksort($days);

                return [$start, array_values($days)];

            case self::YEARLY:
                // The anchor's month and day, every `$interval` years. Clamped like a monthly rule, so
                // a rule anchored on the 29th of February lands on the 28th in the three years out of
                // four that have no 29th — `+1 year` would land it on the 1st of March.
                $start = self::ymd((int)$anchor->format('Y') + $step, (int)$anchor->format('n'), 1);
                $day = $start->setDate(
                    (int)$start->format('Y'),
                    (int)$start->format('n'),
                    min((int)$anchor->format('j'), (int)$start->format('t')),
                );

                return [$day, [$day]];
        }

        return null;
    }

    /** The Sunday on or before a date. */
    private static function weekStart(DateTimeImmutable $day): DateTimeImmutable
    {
        return $day->modify('-' . (int)$day->format('w') . ' days');
    }

    /** A date from parts, with the month allowed to overflow into later years. */
    private static function ymd(int $year, int $month, int $day): DateTimeImmutable
    {
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('UTC'));
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
