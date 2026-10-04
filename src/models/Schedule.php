<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;
use DateTime;
use justinholtweb\schedulr\helpers\Data;

/**
 * When a notification goes out.
 *
 * Deliberately **not** a general RRULE implementation. Four frequencies, an interval, weekday and
 * month-day selectors, a window and an exclusion list cover what a marketing schedule actually is,
 * and every additional RRULE part is a rule the CP has to be able to *show* somebody — which is
 * the real constraint. A schedule nobody can read is a schedule nobody trusts.
 *
 * The rule is never evaluated at send time. `services\Schedules` expands it into concrete
 * occurrence rows, so the runner does one indexed comparison and the CP can list the next twelve
 * sends rather than assert that they will happen.
 */
class Schedule extends Model
{
    /** Send as soon as it is saved. */
    public const MODE_NOW = 'now';

    /** Once, at a stated moment. */
    public const MODE_AT = 'at';

    /** Repeatedly, on a rule. */
    public const MODE_RECURRING = 'recurring';

    /** When something happens — an entry publishes, a user registers. No clock at all. */
    public const MODE_TRIGGER = 'trigger';

    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_YEARLY = 'yearly';

    /** "09:00" means 09:00 in the site's zone — one moment for everybody. */
    public const TZ_SITE = 'site';

    /** "09:00" means 09:00 wherever each subscriber is — as many moments as there are zones. */
    public const TZ_SUBSCRIBER = 'subscriber';

    public ?int $id = null;
    public ?int $notificationId = null;

    public string $mode = self::MODE_NOW;

    public ?DateTime $sendAt = null;

    public string $timezoneMode = self::TZ_SITE;

    public ?string $frequency = null;
    public int $interval = 1;

    /** @var int[]|string|null 0-6, Sunday first. */
    public mixed $byWeekday = null;

    /** @var int[]|string|null 1-31, and -1 for the last day of the month. */
    public mixed $byMonthDay = null;

    /** @var string|null "HH:MM". */
    public ?string $timeOfDay = null;

    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;
    public ?int $maxOccurrences = null;

    /** @var string[]|string|null "YYYY-MM-DD" dates the rule must skip. */
    public mixed $exclusions = null;

    public ?DateTime $dateLastExpanded = null;

    /**
     * These two exist because the service hydrates this model from a whole database row.
     *
     * A model constructed from a row needs somewhere for *every* selected column to land, or Yii throws
     * `UnknownPropertyException` from `Component::__set()` — nowhere near the cause, and only on the read
     * path, so anything that re-reads what it just wrote in memory passes happily.
     */
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function datetimeAttributes(): array
    {
        return ['sendAt', 'startDate', 'endDate', 'dateLastExpanded', 'dateCreated', 'dateUpdated'];
    }

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_NOW => Craft::t('schedulr', 'Send immediately'),
            self::MODE_AT => Craft::t('schedulr', 'Send once, at a time'),
            self::MODE_RECURRING => Craft::t('schedulr', 'Send repeatedly'),
            self::MODE_TRIGGER => Craft::t('schedulr', 'Send when something happens'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        return [
            self::FREQ_DAILY => Craft::t('schedulr', 'Daily'),
            self::FREQ_WEEKLY => Craft::t('schedulr', 'Weekly'),
            self::FREQ_MONTHLY => Craft::t('schedulr', 'Monthly'),
            self::FREQ_YEARLY => Craft::t('schedulr', 'Yearly'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function timezoneModeOptions(): array
    {
        return [
            self::TZ_SITE => Craft::t('schedulr', 'One moment, in the site’s time zone'),
            self::TZ_SUBSCRIBER => Craft::t('schedulr', 'That local time, wherever each subscriber is'),
        ];
    }

    /**
     * @return int[]
     */
    public function getByWeekday(): array
    {
        return $this->intList($this->byWeekday, 0, 6);
    }

    /**
     * @return int[]
     */
    public function getByMonthDay(): array
    {
        // -1 is "the last day of the month", which is the only negative value worth supporting: "the
        // 31st" silently skips February, and every author who writes it means the last day. Zero is
        // dropped rather than clamped — there is no zeroth of the month, and `setDate(…, 0)` in PHP is
        // the last day of the *previous* one.
        return array_values(array_filter(
            $this->intList($this->byMonthDay, -1, 31),
            static fn(int $day) => $day !== 0,
        ));
    }

    /**
     * @return string[]
     */
    public function getExclusions(): array
    {
        return array_values(array_filter(
            Data::toStringList($this->exclusions),
            static fn(string $date) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date),
        ));
    }

    public function isRecurring(): bool
    {
        return $this->mode === self::MODE_RECURRING;
    }

    public function isPerSubscriberTimezone(): bool
    {
        return $this->timezoneMode === self::TZ_SUBSCRIBER
            && in_array($this->mode, [self::MODE_AT, self::MODE_RECURRING], true);
    }

    /** The hour and minute the rule fires at, defaulting to the top of the day. */
    public function getTimeParts(): array
    {
        if ($this->timeOfDay !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $this->timeOfDay, $m)) {
            return [min(23, (int)$m[1]), min(59, (int)$m[2])];
        }

        if ($this->sendAt !== null) {
            return [(int)$this->sendAt->format('G'), (int)$this->sendAt->format('i')];
        }

        return [9, 0];
    }

    /** A one-line summary for the CP, because a schedule nobody can read is a schedule nobody trusts. */
    public function describe(): string
    {
        $formatter = Craft::$app->getFormatter();

        return match ($this->mode) {
            self::MODE_NOW => Craft::t('schedulr', 'Immediately'),
            self::MODE_AT => $this->sendAt !== null
                ? Craft::t('schedulr', 'Once, on {date}', ['date' => $formatter->asDatetime($this->sendAt, 'short')])
                : Craft::t('schedulr', 'Once — no date set'),
            self::MODE_TRIGGER => Craft::t('schedulr', 'When triggered'),
            self::MODE_RECURRING => $this->describeRecurrence(),
            default => $this->mode,
        };
    }

    private function describeRecurrence(): string
    {
        $time = $this->timeOfDay !== null && $this->timeOfDay !== '' ? $this->timeOfDay : '09:00';
        $every = $this->interval > 1 ? (string)$this->interval . ' ' : '';

        $base = match ($this->frequency) {
            self::FREQ_DAILY => Craft::t('schedulr', 'Every {n}day at {time}', ['n' => $every, 'time' => $time]),
            self::FREQ_WEEKLY => Craft::t('schedulr', 'Every {n}week on {days} at {time}', [
                'n' => $every,
                'days' => $this->weekdayNames(),
                'time' => $time,
            ]),
            self::FREQ_MONTHLY => Craft::t('schedulr', 'Every {n}month on day {days} at {time}', [
                'n' => $every,
                'days' => $this->monthDayNames(),
                'time' => $time,
            ]),
            self::FREQ_YEARLY => Craft::t('schedulr', 'Every {n}year at {time}', ['n' => $every, 'time' => $time]),
            default => Craft::t('schedulr', 'Repeatedly'),
        };

        if ($this->timezoneMode === self::TZ_SUBSCRIBER) {
            $base .= ' ' . Craft::t('schedulr', 'in each subscriber’s own time zone');
        }

        return $base;
    }

    private function weekdayNames(): string
    {
        $days = $this->getByWeekday();

        if ($days === []) {
            return Craft::t('schedulr', 'no day chosen');
        }

        $names = Craft::$app->getLocale()->getWeekDayNames('abbreviated');

        return implode(', ', array_map(static fn(int $d) => (string)($names[$d] ?? $d), $days));
    }

    private function monthDayNames(): string
    {
        $days = $this->getByMonthDay();

        if ($days === []) {
            return Craft::t('schedulr', 'no day chosen');
        }

        return implode(', ', array_map(
            static fn(int $d) => $d === -1 ? Craft::t('schedulr', 'last') : (string)$d,
            $days,
        ));
    }

    protected function defineRules(): array
    {
        return [
            [['mode'], 'in', 'range' => array_keys(self::modeOptions())],
            [['timezoneMode'], 'in', 'range' => array_keys(self::timezoneModeOptions())],
            [['frequency'], 'in', 'range' => array_keys(self::frequencyOptions()), 'skipOnEmpty' => true],
            [['interval'], 'integer', 'min' => 1, 'max' => 365],
            [['maxOccurrences'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
            [['timeOfDay'], 'match', 'pattern' => '/^\d{1,2}:\d{2}$/', 'skipOnEmpty' => true],
            [['sendAt'], 'required', 'when' => fn(self $model) => $model->mode === self::MODE_AT],
            [['frequency'], 'required', 'when' => fn(self $model) => $model->mode === self::MODE_RECURRING],
            [['byWeekday'], 'validateWeeklyDays', 'skipOnEmpty' => false],
            // Yii's CompareValidator stringifies its operands, so `['endDate', 'compare', ...]` on
            // two DateTimes throws "Object of class DateTime could not be converted to string" at
            // save time rather than at validation-definition time. Compared by hand instead.
            [['endDate'], 'validateWindow', 'skipOnEmpty' => false],
        ];
    }

    public function validateWeeklyDays(string $attribute): void
    {
        if ($this->mode !== self::MODE_RECURRING || $this->frequency !== self::FREQ_WEEKLY) {
            return;
        }

        if ($this->getByWeekday() === []) {
            $this->addError($attribute, Craft::t('schedulr', 'Choose at least one day of the week.'));
        }
    }

    public function validateWindow(string $attribute): void
    {
        if ($this->endDate === null || $this->startDate === null) {
            return;
        }

        if ($this->endDate <= $this->startDate) {
            $this->addError($attribute, Craft::t('schedulr', 'The end date must be after the start date.'));
        }
    }

    /**
     * @return int[]
     */
    private function intList(mixed $value, int $min, int $max): array
    {
        return Data::toIntList($value, $min, $max);
    }
}
