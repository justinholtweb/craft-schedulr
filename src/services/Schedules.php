<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\helpers\Recurrence;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\Plugin;
use Throwable;

/**
 * Turning a rule into rows.
 *
 * This is the piece the plugin is named for, and the whole design is one decision: **a recurrence
 * rule is never evaluated at send time.** It is expanded ahead of time into concrete occurrence
 * rows, so that:
 *
 * - the runner's query is one indexed `status = 'pending' AND dueAt <= now`, whatever the rules are;
 * - the CP can *show* the next twelve sends instead of asserting that they will happen;
 * - "09:00 in each subscriber's own time zone" is expressible at all, because it is simply thirty
 *   rows with thirty different UTC instants rather than one row and a promise.
 *
 * Everything below is UTC. Wall-clock arithmetic happens in the target zone and is converted once,
 * at the end, because doing it the other way round is how a daily 09:00 send drifts by an hour twice
 * a year and nobody can say when it started.
 */
class Schedules extends Component
{
    // ------------------------------------------------------------------------------ reading

    public function getForNotification(int $notificationId): ?Schedule
    {
        $row = (new Query())->from(Table::SCHEDULES)->where(['notificationId' => $notificationId])->one();

        return $row ? new Schedule($row) : null;
    }

    public function getById(?int $id): ?Schedule
    {
        if ($id === null) {
            return null;
        }

        $row = (new Query())->from(Table::SCHEDULES)->where(['id' => $id])->one();

        return $row ? new Schedule($row) : null;
    }

    public function getOccurrenceById(?int $id): ?Occurrence
    {
        if ($id === null) {
            return null;
        }

        $row = (new Query())->from(Table::OCCURRENCES)->where(['id' => $id])->one();

        return $row ? new Occurrence($row) : null;
    }

    public function getNextOccurrence(int $notificationId): ?Occurrence
    {
        $row = (new Query())
            ->from(Table::OCCURRENCES)
            ->where(['notificationId' => $notificationId, 'status' => Occurrence::STATUS_PENDING])
            ->orderBy(['dueAt' => SORT_ASC])
            ->one();

        return $row ? new Occurrence($row) : null;
    }

    /**
     * The next sends across every notification — the Schedule screen.
     *
     * @return Occurrence[]
     */
    public function getUpcoming(int $limit = 50, ?int $notificationId = null): array
    {
        $query = (new Query())
            ->from(Table::OCCURRENCES)
            ->where(['status' => [Occurrence::STATUS_PENDING, Occurrence::STATUS_CLAIMED, Occurrence::STATUS_SENDING]])
            ->orderBy(['dueAt' => SORT_ASC])
            ->limit($limit);

        if ($notificationId !== null) {
            $query->andWhere(['notificationId' => $notificationId]);
        }

        return array_map(static fn(array $row) => new Occurrence($row), $query->all());
    }

    /**
     * @return Occurrence[]
     */
    public function getRecent(int $limit = 50, ?int $notificationId = null): array
    {
        $query = (new Query())
            ->from(Table::OCCURRENCES)
            ->where(['not', ['status' => [Occurrence::STATUS_PENDING, Occurrence::STATUS_CLAIMED]]])
            ->orderBy(['dueAt' => SORT_DESC])
            ->limit($limit);

        if ($notificationId !== null) {
            $query->andWhere(['notificationId' => $notificationId]);
        }

        return array_map(static fn(array $row) => new Occurrence($row), $query->all());
    }

    // ------------------------------------------------------------------------------ writing

    public function save(Schedule $schedule, bool $runValidation = true): bool
    {
        if ($runValidation && !$schedule->validate()) {
            return false;
        }

        if (!Edition::allowsPerSubscriberTimezone(Plugin::getInstance()->isPro())) {
            // A downgrade, not a refusal: the schedule saves, it just resolves to one moment. The
            // alternative — refusing the save — would make a lapsed licence look like a bug in the
            // schedule editor.
            $schedule->timezoneMode = Schedule::TZ_SITE;
        }

        $data = [
            'notificationId' => $schedule->notificationId,
            'mode' => $schedule->mode,
            'sendAt' => Db::prepareDateForDb($schedule->sendAt),
            'timezoneMode' => $schedule->timezoneMode,
            'frequency' => $schedule->frequency,
            'interval' => max(1, $schedule->interval),
            // Arrays, not pre-encoded strings — see helpers\Data for why.
            'byWeekday' => $schedule->getByWeekday(),
            'byMonthDay' => $schedule->getByMonthDay(),
            'timeOfDay' => $schedule->timeOfDay,
            'startDate' => Db::prepareDateForDb($schedule->startDate),
            'endDate' => Db::prepareDateForDb($schedule->endDate),
            'maxOccurrences' => $schedule->maxOccurrences,
            'exclusions' => $schedule->getExclusions(),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ];

        $db = Craft::$app->getDb();

        if ($schedule->id !== null) {
            $db->createCommand()->update(Table::SCHEDULES, $data, ['id' => $schedule->id])->execute();

            return true;
        }

        $existing = $schedule->notificationId !== null
            ? $this->getForNotification($schedule->notificationId)
            : null;

        if ($existing !== null) {
            // The unique index on notificationId enforces one schedule per notification: two would
            // expand into two overlapping sets of occurrences and double-send everything.
            $schedule->id = $existing->id;
            $db->createCommand()->update(Table::SCHEDULES, $data, ['id' => $existing->id])->execute();

            return true;
        }

        $db->createCommand()->insert(Table::SCHEDULES, $data + [
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $schedule->id = (int)$db->getLastInsertID();

        return true;
    }

    /**
     * Throws away a notification's unsent future occurrences and expands the rule again.
     *
     * **Unsent** and **future** are both load-bearing. Sent occurrences are the record of what
     * actually went out and are worth more than the rule that produced them; a due-but-unclaimed one
     * from a minute ago is discarded because the edit supersedes it.
     */
    public function reexpand(Notification $notification): int
    {
        if ($notification->id === null) {
            return 0;
        }

        $schedule = $this->getForNotification($notification->id);

        Craft::$app->getDb()->createCommand()->delete(Table::OCCURRENCES, [
            'and',
            ['notificationId' => $notification->id],
            ['status' => Occurrence::STATUS_PENDING],
        ])->execute();

        if ($schedule === null || $notification->state === Notification::STATE_DRAFT) {
            // A draft has no occurrences. Expanding one would send it the moment its time arrived,
            // which is the single worst possible interpretation of "draft".
            return 0;
        }

        return $this->expand($schedule, $notification);
    }

    /**
     * Materialises this schedule's occurrences up to the horizon.
     *
     * @return int How many rows were created.
     */
    public function expand(Schedule $schedule, ?Notification $notification = null): int
    {
        if ($schedule->notificationId === null) {
            return 0;
        }

        $notification ??= Plugin::getInstance()->notifications->getById($schedule->notificationId);

        if ($notification === null) {
            return 0;
        }

        $instants = match ($schedule->mode) {
            Schedule::MODE_AT => $this->instantsForOnce($schedule),
            Schedule::MODE_RECURRING => $this->instantsForRecurrence($schedule),
            // `now` is sent by the send button, and `trigger` by whatever happens. Neither has a
            // clock, so neither has occurrences until the moment arrives.
            default => [],
        };

        if ($instants === []) {
            return 0;
        }

        $existing = $this->existingKeys($schedule->notificationId);
        $rows = [];
        $now = Db::prepareDateForDb(new DateTime());

        foreach ($instants as [$dueAt, $timezone]) {
            $key = $dueAt->format('Y-m-d H:i:s') . '|' . ($timezone ?? '');

            // Idempotent, so a re-expansion after a horizon top-up does not duplicate anything that
            // survived — including the sent rows, which is why this checks *all* statuses.
            if (isset($existing[$key])) {
                continue;
            }

            $existing[$key] = true;

            $rows[] = [
                $schedule->notificationId,
                $schedule->id,
                $dueAt->format('Y-m-d H:i:s'),
                $timezone,
                Occurrence::STATUS_PENDING,
                0,
                $now,
                $now,
                StringHelper::UUID(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        Craft::$app->getDb()->createCommand()->batchInsert(Table::OCCURRENCES, [
            'notificationId', 'scheduleId', 'dueAt', 'timezone', 'status', 'attempts',
            'dateCreated', 'dateUpdated', 'uid',
        ], $rows)->execute();

        Db::update(Table::SCHEDULES, ['dateLastExpanded' => $now], ['id' => $schedule->id]);

        return count($rows);
    }

    /**
     * Tops up every recurring schedule whose horizon has run down. Called by the runner.
     */
    public function expandAll(): int
    {
        $created = 0;

        $rows = (new Query())
            ->from(Table::SCHEDULES)
            ->where(['mode' => Schedule::MODE_RECURRING])
            ->all();

        foreach ($rows as $row) {
            $schedule = new Schedule($row);

            try {
                $created += $this->expand($schedule);
            } catch (Throwable $e) {
                Plugin::error('Could not expand schedule #' . $schedule->id . ': ' . $e->getMessage());
            }
        }

        return $created;
    }

    /**
     * An occurrence for right now, for the send button.
     *
     * "Send now" goes through an occurrence like everything else rather than taking a shortcut, so a
     * manual send and a scheduled one produce identical ledgers and are testable by one path.
     */
    public function createImmediateOccurrence(Notification $notification): ?Occurrence
    {
        if ($notification->id === null) {
            return null;
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $db = Craft::$app->getDb();

        $db->createCommand()->insert(Table::OCCURRENCES, [
            'notificationId' => $notification->id,
            'scheduleId' => $this->getForNotification($notification->id)?->id,
            'dueAt' => $now->format('Y-m-d H:i:s'),
            'timezone' => null,
            'status' => Occurrence::STATUS_CLAIMED,
            'claimedAt' => $now->format('Y-m-d H:i:s'),
            'claimToken' => StringHelper::UUID(),
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return $this->getOccurrenceById((int)$db->getLastInsertID());
    }

    // -------------------------------------------------------------------------- the runner's end

    /**
     * Claims the due occurrences for this runner.
     *
     * Claiming is a two-step **update-then-read** rather than a read-then-update, because two
     * runners — a cron tick and a web request — can be inside this method at the same time. The
     * update writes a token only onto rows that are still `pending`, so the database decides the
     * winner; reading first and updating second would let both runners send the same notification.
     *
     * @return Occurrence[]
     */
    public function claimDue(int $limit = 25): array
    {
        $token = StringHelper::UUID();
        $now = new DateTime('now', new DateTimeZone('UTC'));

        $ids = (new Query())
            ->select(['id'])
            ->from(Table::OCCURRENCES)
            ->where(['status' => Occurrence::STATUS_PENDING])
            ->andWhere(['<=', 'dueAt', $now->format('Y-m-d H:i:s')])
            ->orderBy(['dueAt' => SORT_ASC])
            ->limit($limit)
            ->column();

        if ($ids === []) {
            return [];
        }

        $claimed = Craft::$app->getDb()->createCommand()->update(Table::OCCURRENCES, [
            'status' => Occurrence::STATUS_CLAIMED,
            'claimedAt' => $now->format('Y-m-d H:i:s'),
            'claimToken' => $token,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], [
            'and',
            ['id' => $ids],
            // The guard. Anything another runner took between the select and here is no longer
            // pending, so this update passes over it and that runner keeps it.
            ['status' => Occurrence::STATUS_PENDING],
        ])->execute();

        if ($claimed === 0) {
            return [];
        }

        return array_map(
            static fn(array $row) => new Occurrence($row),
            (new Query())->from(Table::OCCURRENCES)->where(['claimToken' => $token])->all(),
        );
    }

    /**
     * Returns occurrences stuck mid-send back to pending.
     *
     * A worker that is killed between claiming and queueing leaves a row nothing will ever pick up
     * again, and the only symptom is a notification that never arrived — no error, no failed job,
     * nothing in the log. Reclaiming after a generous interval is what makes the schedule
     * self-healing rather than quietly lossy.
     */
    public function reclaimStalled(int $minutes = 30): int
    {
        $cutoff = (new DateTime('now', new DateTimeZone('UTC')))->modify("-{$minutes} minutes");

        return Craft::$app->getDb()->createCommand()->update(Table::OCCURRENCES, [
            'status' => Occurrence::STATUS_PENDING,
            'claimToken' => null,
            'claimedAt' => null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], [
            'and',
            ['status' => [Occurrence::STATUS_CLAIMED, Occurrence::STATUS_SENDING]],
            ['<', 'claimedAt', $cutoff->format('Y-m-d H:i:s')],
        ])->execute();
    }

    public function markOccurrence(?int $id, string $status, ?string $error = null): void
    {
        if ($id === null) {
            return;
        }

        $data = [
            'status' => $status,
            'lastError' => $error !== null ? substr($error, 0, 2000) : null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ];

        if (in_array($status, [Occurrence::STATUS_SENT, Occurrence::STATUS_FAILED, Occurrence::STATUS_SKIPPED], true)) {
            $data['dateSent'] = Db::prepareDateForDb(new DateTime());
        }

        if ($status === Occurrence::STATUS_SENDING) {
            $data['attempts'] = new \yii\db\Expression('[[attempts]] + 1');
        }

        Craft::$app->getDb()->createCommand()->update(Table::OCCURRENCES, $data, ['id' => $id])->execute();
    }

    public function setOccurrenceCounts(?int $id, int $targeted = 0, int $delivered = 0, int $failed = 0): void
    {
        if ($id === null) {
            return;
        }

        $update = [];

        foreach (['targeted' => $targeted, 'delivered' => $delivered, 'failed' => $failed] as $column => $delta) {
            if ($delta !== 0) {
                $update[$column] = new \yii\db\Expression("[[$column]] + :d_$column", [":d_$column" => $delta]);
            }
        }

        if ($update !== []) {
            Craft::$app->getDb()->createCommand()->update(Table::OCCURRENCES, $update, ['id' => $id])->execute();
        }
    }

    /**
     * Records that a batch has finished, and reports whether the send is now complete.
     *
     * The increment and the read are one statement apart, deliberately: two workers finishing at the
     * same moment must not both see "complete" and both mark the notification sent. The increment is
     * atomic, so exactly one of them reads a value that reaches `targeted`.
     */
    public function completeBatch(?int $occurrenceId, int $processed, int $delivered, int $failed): bool
    {
        if ($occurrenceId === null) {
            return false;
        }

        $db = Craft::$app->getDb();

        $db->createCommand()->update(Table::OCCURRENCES, [
            'processed' => new \yii\db\Expression('[[processed]] + :p', [':p' => $processed]),
            'delivered' => new \yii\db\Expression('[[delivered]] + :d', [':d' => $delivered]),
            'failed' => new \yii\db\Expression('[[failed]] + :f', [':f' => $failed]),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $occurrenceId])->execute();

        $row = (new Query())
            ->select(['targeted', 'processed'])
            ->from(Table::OCCURRENCES)
            ->where(['id' => $occurrenceId])
            ->one();

        if ($row === false || $row === null) {
            return false;
        }

        $targeted = (int)$row['targeted'];

        // Zero targeted means the send never resolved an audience, which `Sender::dispatch()` has
        // already marked `skipped`. Treating it as complete here would overwrite that.
        return $targeted > 0 && (int)$row['processed'] >= $targeted;
    }

    public function cancelPending(int $notificationId): int
    {
        return Craft::$app->getDb()->createCommand()->update(Table::OCCURRENCES, [
            'status' => Occurrence::STATUS_CANCELLED,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['notificationId' => $notificationId, 'status' => Occurrence::STATUS_PENDING])->execute();
    }

    // --------------------------------------------------------------------------- expansion

    /**
     * @return array<int, array{0: DateTimeImmutable, 1: string|null}>
     */
    private function instantsForOnce(Schedule $schedule): array
    {
        if ($schedule->sendAt === null) {
            return [];
        }

        if (!$schedule->isPerSubscriberTimezone()) {
            return [[
                $this->toUtc(DateTimeImmutable::createFromMutable($schedule->sendAt)),
                null,
            ]];
        }

        // The wall-clock time the author typed, reproduced in every zone on the list.
        $wall = $schedule->sendAt->format('Y-m-d H:i:00');

        return $this->fanOut(fn(DateTimeZone $zone) => new DateTimeImmutable($wall, $zone));
    }

    /**
     * @return array<int, array{0: DateTimeImmutable, 1: string|null}>
     */
    private function instantsForRecurrence(Schedule $schedule): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteZone = new DateTimeZone(Craft::$app->getTimeZone());

        $horizon = (new DateTimeImmutable('now', $siteZone))
            ->modify('+' . max(1, $settings->expandHorizonDays) . ' days');

        if ($schedule->endDate !== null) {
            $end = DateTimeImmutable::createFromMutable($schedule->endDate);
            $horizon = $end < $horizon ? $end : $horizon;
        }

        $from = $schedule->startDate !== null
            ? DateTimeImmutable::createFromMutable($schedule->startDate)
            : new DateTimeImmutable('now', $siteZone);

        // Never before today. A rule whose start date is in the past must resume from now, not
        // backfill — expanding a year of missed Tuesdays and then sending all of them is the worst
        // possible reading of "every Tuesday".
        $today = new DateTimeImmutable('now', $siteZone);

        if ($from < $today) {
            $from = $today;
        }

        $dates = Recurrence::dates(
            (string)$schedule->frequency,
            $from,
            $horizon,
            $siteZone,
            $schedule->interval,
            $schedule->getByWeekday(),
            $schedule->getByMonthDay(),
            $schedule->maxOccurrences,
        );

        if ($dates === []) {
            return [];
        }

        [$hour, $minute] = $schedule->getTimeParts();
        $exclusions = array_flip($schedule->getExclusions());

        $out = [];

        foreach ($dates as $date) {
            if (isset($exclusions[$date])) {
                continue;
            }

            if (!$schedule->isPerSubscriberTimezone()) {
                $utc = Recurrence::instant($date, $hour, $minute, $siteZone);

                if ($this->isFuture($utc)) {
                    $out[] = [$this->deferPastQuietHours($utc, null), null];
                }

                continue;
            }

            foreach ($this->zones() as $name => $zone) {
                $utc = Recurrence::instant($date, $hour, $minute, $zone);

                if ($this->isFuture($utc)) {
                    $out[] = [$this->deferPastQuietHours($utc, $name), $name];
                }
            }
        }

        return array_slice($out, 0, Recurrence::CEILING);
    }

    /**
     * The time zones on the list, as usable objects.
     *
     * A zone PHP's database has never heard of is dropped rather than throwing. Its subscribers are not
     * lost: `Sender::timezoneCondition()` folds every unrecognised and missing zone into the site's, so
     * skipping the row here loses nobody.
     *
     * @return array<string, DateTimeZone>
     */
    private function zones(): array
    {
        $out = [];

        foreach (array_keys(Plugin::getInstance()->subscribers->timezones()) as $name) {
            try {
                $out[(string)$name] = new DateTimeZone((string)$name);
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    /**
     * One instant per time zone on the list.
     *
     * @param callable(DateTimeZone): DateTimeImmutable $build
     * @return array<int, array{0: DateTimeImmutable, 1: string|null}>
     */
    private function fanOut(callable $build): array
    {
        $out = [];

        foreach ($this->zones() as $name => $zone) {
            $out[] = [$this->toUtc($build($zone)), $name];
        }

        return $out;
    }

    /**
     * Moves an instant that lands inside quiet hours to the end of them.
     *
     * The arithmetic is in `helpers\Recurrence`; what happens here is reading the setting and resolving
     * the zone, both of which need Craft.
     */
    private function deferPastQuietHours(DateTimeImmutable $utc, ?string $timezone): DateTimeImmutable
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasQuietHours()) {
            return $utc;
        }

        $start = Recurrence::minutes($settings->quietHoursStart);
        $end = Recurrence::minutes($settings->quietHoursEnd);

        if ($start === null || $end === null) {
            return $utc;
        }

        try {
            $zone = new DateTimeZone($timezone ?? Craft::$app->getTimeZone());
        } catch (Throwable) {
            return $utc;
        }

        return Recurrence::deferPastQuiet($utc, $zone, $start, $end);
    }

    private function toUtc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether an instant is worth materialising.
     *
     * A minute of grace, because expanding a rule whose next slot is forty seconds away should
     * produce it rather than skip a send for being marginally late.
     */
    private function isFuture(DateTimeImmutable $utc): bool
    {
        return $utc >= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 minute');
    }

    /**
     * @return array<string, true>
     */
    private function existingKeys(int $notificationId): array
    {
        $rows = (new Query())
            ->select(['dueAt', 'timezone'])
            ->from(Table::OCCURRENCES)
            ->where(['notificationId' => $notificationId])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(new DateTime((string)$row['dueAt']))->format('Y-m-d H:i:s') . '|' . ((string)($row['timezone'] ?? ''))] = true;
        }

        return $out;
    }
}
