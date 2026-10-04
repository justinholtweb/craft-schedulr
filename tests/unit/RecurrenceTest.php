<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\schedulr\helpers\Recurrence;
use PHPUnit\Framework\TestCase;

/**
 * Date arithmetic is where scheduling plugins actually go wrong, and every failure here is silent: a
 * monthly rule that fires on the 3rd of March, a "every other Tuesday" that fires weekly, a 09:00 send
 * that becomes 08:00 in late March. None of them raise anything. All of them are one test each.
 */
final class RecurrenceTest extends TestCase
{
    private function zone(): DateTimeZone
    {
        // A zone with DST, deliberately. Half these tests are meaningless in UTC.
        return new DateTimeZone('Europe/London');
    }

    public function testDailyRespectsInterval(): void
    {
        $dates = Recurrence::dates(
            Recurrence::DAILY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-03-10', $this->zone()),
            $this->zone(),
            interval: 3,
        );

        self::assertSame(['2026-03-01', '2026-03-04', '2026-03-07', '2026-03-10'], $dates);
    }

    public function testWeeklyPicksTheChosenDays(): void
    {
        // Monday (1) and Friday (5), from a Sunday.
        $dates = Recurrence::dates(
            Recurrence::WEEKLY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-03-21', $this->zone()),
            $this->zone(),
            byWeekday: [1, 5],
        );

        self::assertSame([
            '2026-03-02', '2026-03-06',
            '2026-03-09', '2026-03-13',
            '2026-03-16', '2026-03-20',
        ], $dates);
    }

    public function testWeeklyIntervalSkipsWholeWeeks(): void
    {
        // The bug this pins: stepping day by day and testing membership makes `interval` mean nothing,
        // so "every other Tuesday" fires every Tuesday and nobody notices for a fortnight.
        $dates = Recurrence::dates(
            Recurrence::WEEKLY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-04-05', $this->zone()),
            $this->zone(),
            interval: 2,
            byWeekday: [2],
        );

        self::assertSame(['2026-03-03', '2026-03-17', '2026-03-31'], $dates);
    }

    public function testWeeklyWithNoDaysProducesNothing(): void
    {
        // Better than falling back to "every day", which is how a half-configured rule becomes a
        // notification storm.
        self::assertSame([], Recurrence::dates(
            Recurrence::WEEKLY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-04-01', $this->zone()),
            $this->zone(),
        ));
    }

    public function testMonthlyClampsRatherThanRollingOver(): void
    {
        // PHP's own setDate(2026, 2, 31) is the 3rd of March, without complaint. That would send a
        // monthly notification on the wrong day in four months of every year.
        $dates = Recurrence::dates(
            Recurrence::MONTHLY,
            new DateTimeImmutable('2026-01-01', $this->zone()),
            new DateTimeImmutable('2026-04-30', $this->zone()),
            $this->zone(),
            byMonthDay: [31],
        );

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $dates);
    }

    public function testMonthlyLastDay(): void
    {
        $dates = Recurrence::dates(
            Recurrence::MONTHLY,
            new DateTimeImmutable('2028-01-01', $this->zone()),
            new DateTimeImmutable('2028-03-31', $this->zone()),
            $this->zone(),
            byMonthDay: [Recurrence::LAST_DAY],
        );

        // 2028 is a leap year, which is the whole reason -1 exists rather than "28" or "31".
        self::assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $dates);
    }

    public function testYearly(): void
    {
        $dates = Recurrence::dates(
            Recurrence::YEARLY,
            new DateTimeImmutable('2026-06-15', $this->zone()),
            new DateTimeImmutable('2029-01-01', $this->zone()),
            $this->zone(),
        );

        self::assertSame(['2026-06-15', '2027-06-15', '2028-06-15'], $dates);
    }

    public function testWeeklyFromAMidweekAnchorDoesNotSkipAWeek(): void
    {
        // Anchored on a Wednesday, every Thursday. Stepping the cursor a week and then jumping to "sunday
        // this week" — PHP's weeks start on Monday — used to lose the 12th entirely.
        $dates = Recurrence::dates(
            Recurrence::WEEKLY,
            new DateTimeImmutable('2026-03-04', $this->zone()),
            new DateTimeImmutable('2026-03-20', $this->zone()),
            $this->zone(),
            byWeekday: [4],
        );

        self::assertSame(['2026-03-05', '2026-03-12', '2026-03-19'], $dates);
    }

    /**
     * The runner expands every minute. A rule re-anchored at "today" on every pass makes each pass's
     * first week *this* week, so "every other Tuesday" fired every Tuesday.
     */
    public function testWeeklyIntervalIsAnchoredAtTheStartDateNotToday(): void
    {
        $anchor = new DateTimeImmutable('2026-09-01', $this->zone()); // a Tuesday
        $horizon = new DateTimeImmutable('2026-11-15', $this->zone());

        $expected = ['2026-10-13', '2026-10-27', '2026-11-10'];

        // Expanded on four consecutive days — including both weeks of the cycle — and every pass
        // agrees on which Tuesdays are in it.
        foreach (['2026-10-03', '2026-10-04', '2026-10-06', '2026-10-10'] as $today) {
            $dates = Recurrence::dates(
                Recurrence::WEEKLY,
                $anchor,
                $horizon,
                $this->zone(),
                interval: 2,
                byWeekday: [2],
                notBefore: new DateTimeImmutable($today, $this->zone()),
            );

            self::assertSame($expected, $dates, "expanded on $today");
        }
    }

    public function testYearlyYieldsOneDatePerYearWhicheverDayItIsExpanded(): void
    {
        $anchor = new DateTimeImmutable('2024-06-15', $this->zone());

        // Expanded daily with a ninety-day horizon, a yearly rule must produce nothing at all until its
        // day is inside the window — not today's date, once per pass.
        foreach (['2026-10-03', '2026-10-04', '2026-10-05'] as $today) {
            $now = new DateTimeImmutable($today, $this->zone());

            self::assertSame([], Recurrence::dates(
                Recurrence::YEARLY,
                $anchor,
                $now->modify('+90 days'),
                $this->zone(),
                notBefore: $now,
            ), "expanded on $today");
        }

        self::assertSame(['2027-06-15', '2028-06-15', '2029-06-15'], Recurrence::dates(
            Recurrence::YEARLY,
            $anchor,
            new DateTimeImmutable('2029-12-31', $this->zone()),
            $this->zone(),
            notBefore: new DateTimeImmutable('2026-10-03', $this->zone()),
        ));
    }

    public function testYearlyClampsTheTwentyNinthOfFebruary(): void
    {
        self::assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], Recurrence::dates(
            Recurrence::YEARLY,
            new DateTimeImmutable('2028-02-29', $this->zone()),
            new DateTimeImmutable('2032-12-31', $this->zone()),
            $this->zone(),
        ));
    }

    public function testMonthlyIntervalIsAnchoredAtTheStartDate(): void
    {
        // Every third month on the 10th, from January: Jan, Apr, Jul, Oct. Expanded in August, the next
        // one is October — not August, which is where re-anchoring at today would put it.
        $dates = Recurrence::dates(
            Recurrence::MONTHLY,
            new DateTimeImmutable('2026-01-10', $this->zone()),
            new DateTimeImmutable('2027-01-31', $this->zone()),
            $this->zone(),
            interval: 3,
            byMonthDay: [10],
            notBefore: new DateTimeImmutable('2026-08-01', $this->zone()),
        );

        self::assertSame(['2026-10-10', '2027-01-10'], $dates);
    }

    public function testMaxOccurrencesIsHonouredAcrossRepeatedExpansions(): void
    {
        $anchor = new DateTimeImmutable('2026-10-01', $this->zone());
        $horizon = new DateTimeImmutable('2027-01-01', $this->zone());

        $expand = fn(string $today) => Recurrence::dates(
            Recurrence::DAILY,
            $anchor,
            $horizon,
            $this->zone(),
            limit: 3,
            notBefore: new DateTimeImmutable($today, $this->zone()),
        );

        // The first pass sees all three. Each later pass sees only what is left of them, and once the
        // three have gone the rule is finished — a count restarting at zero each pass would hand out
        // three more every day, forever.
        self::assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $expand('2026-10-01'));
        self::assertSame(['2026-10-02', '2026-10-03'], $expand('2026-10-02'));
        self::assertSame(['2026-10-03'], $expand('2026-10-03'));
        self::assertSame([], $expand('2026-10-04'));
        self::assertSame([], $expand('2026-12-25'));
    }

    public function testMaxOccurrencesCountsEveryWeekdayOfAWeeklyRule(): void
    {
        // Mondays and Fridays, stop after five: two weeks and a Monday.
        $dates = Recurrence::dates(
            Recurrence::WEEKLY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-06-01', $this->zone()),
            $this->zone(),
            byWeekday: [5, 1],
            limit: 5,
            notBefore: new DateTimeImmutable('2026-03-10', $this->zone()),
        );

        self::assertSame(['2026-03-13', '2026-03-16'], $dates);
    }

    public function testAnAncientAnchorStillExpandsCheaplyAndInPhase(): void
    {
        // Ten years of every-other-day, and today's slice must still be on the anchor's parity.
        $dates = Recurrence::dates(
            Recurrence::DAILY,
            new DateTimeImmutable('2016-01-01', $this->zone()),
            new DateTimeImmutable('2026-01-10', $this->zone()),
            $this->zone(),
            interval: 2,
            notBefore: new DateTimeImmutable('2026-01-01', $this->zone()),
        );

        // 2016-01-01 to 2026-01-01 is 3,653 days — odd — so the 1st itself is off-cycle.
        self::assertSame(['2026-01-02', '2026-01-04', '2026-01-06', '2026-01-08', '2026-01-10'], $dates);
    }

    public function testLimitIsHonoured(): void
    {
        $dates = Recurrence::dates(
            Recurrence::DAILY,
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2027-03-01', $this->zone()),
            $this->zone(),
            limit: 5,
        );

        self::assertCount(5, $dates);
    }

    public function testAnUnknownFrequencyProducesNothing(): void
    {
        self::assertSame([], Recurrence::dates(
            'fortnightly',
            new DateTimeImmutable('2026-03-01', $this->zone()),
            new DateTimeImmutable('2026-04-01', $this->zone()),
            $this->zone(),
        ));
    }

    /**
     * The DST test, and the reason `dates()` returns dates rather than instants.
     */
    public function testWallClockTimeSurvivesADstBoundary(): void
    {
        $zone = $this->zone();

        // British Summer Time began on 2026-03-29. Both of these are 09:00 locally.
        $before = Recurrence::instant('2026-03-28', 9, 0, $zone);
        $after = Recurrence::instant('2026-03-30', 9, 0, $zone);

        self::assertSame('09:00', $before->setTimezone($zone)->format('H:i'));
        self::assertSame('09:00', $after->setTimezone($zone)->format('H:i'));

        // And they are genuinely different instants in UTC — 09:00 GMT against 08:00 UTC — which is
        // exactly what stepping an instant forward by "one day" would get wrong.
        self::assertSame('09:00', $before->format('H:i'));
        self::assertSame('08:00', $after->format('H:i'));
    }

    public function testPerZoneInstantsDifferByTheOffset(): void
    {
        $london = Recurrence::instant('2026-06-15', 9, 0, new DateTimeZone('Europe/London'));
        $newYork = Recurrence::instant('2026-06-15', 9, 0, new DateTimeZone('America/New_York'));
        $tokyo = Recurrence::instant('2026-06-15', 9, 0, new DateTimeZone('Asia/Tokyo'));

        // The spread a "09:00 local" send actually covers. Tokyo goes first, New York last, and the two
        // are thirteen hours apart — which is why one such send is many occurrences and why the CP
        // says so.
        self::assertTrue($tokyo < $london);
        self::assertTrue($london < $newYork);
        self::assertSame(13 * 3600, $newYork->getTimestamp() - $tokyo->getTimestamp());
    }

    public function testQuietWindowWrappingMidnight(): void
    {
        $start = Recurrence::minutes('22:00');
        $end = Recurrence::minutes('07:00');

        self::assertNotNull($start);
        self::assertNotNull($end);

        self::assertTrue(Recurrence::isQuiet(Recurrence::minutes('23:30'), $start, $end));
        self::assertTrue(Recurrence::isQuiet(Recurrence::minutes('03:00'), $start, $end));
        self::assertTrue(Recurrence::isQuiet(Recurrence::minutes('22:00'), $start, $end));
        self::assertFalse(Recurrence::isQuiet(Recurrence::minutes('07:00'), $start, $end));
        self::assertFalse(Recurrence::isQuiet(Recurrence::minutes('12:00'), $start, $end));
    }

    public function testQuietWindowWithinOneDay(): void
    {
        $start = Recurrence::minutes('09:00');
        $end = Recurrence::minutes('17:00');

        self::assertTrue(Recurrence::isQuiet(Recurrence::minutes('12:00'), $start, $end));
        self::assertFalse(Recurrence::isQuiet(Recurrence::minutes('08:59'), $start, $end));
        self::assertFalse(Recurrence::isQuiet(Recurrence::minutes('17:00'), $start, $end));
    }

    public function testQuietHoursDeferRatherThanDrop(): void
    {
        $zone = new DateTimeZone('Europe/London');

        // 03:00 local, inside a 22:00–07:00 window. It must come out as 07:00 the same morning, not be
        // discarded — a daily 07:00 notification silently never sending is the failure this prevents.
        $utc = Recurrence::instant('2026-06-15', 3, 0, $zone);
        $deferred = Recurrence::deferPastQuiet($utc, $zone, 22 * 60, 7 * 60);

        self::assertSame('2026-06-15 07:00', $deferred->setTimezone($zone)->format('Y-m-d H:i'));
    }

    public function testQuietHoursRollIntoTheNextMorning(): void
    {
        $zone = new DateTimeZone('Europe/London');

        // 23:00 local. The end of the window is 07:00, which is already behind us today, so it is
        // tomorrow's 07:00 — not a time in the past, which would fire the instant it was written.
        $utc = Recurrence::instant('2026-06-15', 23, 0, $zone);
        $deferred = Recurrence::deferPastQuiet($utc, $zone, 22 * 60, 7 * 60);

        self::assertSame('2026-06-16 07:00', $deferred->setTimezone($zone)->format('Y-m-d H:i'));
    }

    public function testAnInstantOutsideQuietHoursIsUntouched(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $utc = Recurrence::instant('2026-06-15', 12, 0, $zone);

        self::assertSame($utc->getTimestamp(), Recurrence::deferPastQuiet($utc, $zone, 22 * 60, 7 * 60)->getTimestamp());
    }

    public function testMinutesRejectsNonsense(): void
    {
        self::assertSame(0, Recurrence::minutes('00:00'));
        self::assertSame(1439, Recurrence::minutes('23:59'));
        self::assertNull(Recurrence::minutes('24:00'));
        self::assertNull(Recurrence::minutes('12:60'));
        self::assertNull(Recurrence::minutes('noon'));
        self::assertNull(Recurrence::minutes(''));
    }
}
