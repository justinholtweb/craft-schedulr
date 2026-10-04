<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
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

        if (
            $schedule->timezoneMode === Schedule::TZ_SUBSCRIBER
            && !Edition::allowsPerSubscriberTimezone(Plugin::getInstance()->isPro())
            && !$this->wasPerSubscriberTimezone($schedule)
        ) {
            // Lite cannot *switch a schedule to* per-subscriber time zones. It saves rather than
            // refusing — refusing would make a lapsed licence look like a bug in the schedule editor —
            // and resolves to one moment instead.
            //
            // A schedule that was already per-subscriber keeps it. A downgrade, not a wall: re-saving
            // a notification after the licence lapsed must not quietly move thirty zones' 09:00 to the
            // site's 09:00, which for most of the list is the middle of the night.
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
     * Whether the stored copy of this schedule already resolves per subscriber.
     */
    private function wasPerSubscriberTimezone(Schedule $schedule): bool
    {
        $condition = $schedule->id !== null
            ? ['id' => $schedule->id]
            : ($schedule->notificationId !== null ? ['notificationId' => $schedule->notificationId] : null);

        if ($condition === null) {
            return false;
        }

        return (new Query())
            ->from(Table::SCHEDULES)
            ->where($condition)
            ->andWhere(['timezoneMode' => Schedule::TZ_SUBSCRIBER])
            ->exists();
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
     * Recovers occurrences stuck mid-send — without ever sending one twice.
     *
     * Two different stalls, and they are deliberately treated differently:
     *
     * - **`claimed` for longer than `$minutes`** goes back to `pending`. A runner claimed it and died
     *   before dispatching it, and the only symptom would otherwise be a notification that never
     *   arrived — no error, no failed job, nothing in the log. This is safe because `dispatch()` (and
     *   `Automations`) move a row to `sending` *before* queueing its first batch: a row still
     *   `claimed` has no batches anywhere, so returning it to pending cannot double anything.
     *
     * - **`sending` is never returned to pending.** Its batches are in the queue, and the queue may
     *   simply be behind — a worker that is down for an hour, a long send on a slow host. Reclaiming
     *   it would dispatch the whole audience again *while the original batches are still waiting to
     *   run*, so everybody would get it twice; doing so after thirty minutes is what this method used
     *   to do. Instead, a `sending` row that has made no progress for `$abandonHours` is **closed**
     *   from the totals its batches did report — `sent` if anything was delivered, `failed` if not —
     *   with an error naming how many recipients never reported back. The notification leaves the
     *   `sending` state with it, so an automation's in-flight guard cannot stay shut forever.
     *
     * Losing the stragglers of an abandoned send is the honest failure mode here. Re-sending to
     * everybody to make sure of them is the one that loses a site its push permission.
     *
     * @return int Rows returned to pending plus rows closed.
     */
    public function reclaimStalled(int $minutes = 30, int $abandonHours = 24): int
    {
        $utc = new DateTimeZone('UTC');
        $cutoff = (new DateTime('now', $utc))->modify("-{$minutes} minutes");
        $db = Craft::$app->getDb();

        $reclaimed = $db->createCommand()->update(Table::OCCURRENCES, [
            'status' => Occurrence::STATUS_PENDING,
            'claimToken' => null,
            'claimedAt' => null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], [
            'and',
            ['status' => Occurrence::STATUS_CLAIMED],
            ['<', 'claimedAt', $cutoff->format('Y-m-d H:i:s')],
        ])->execute();

        // `dateUpdated` rather than `claimedAt`: every finished batch touches it, so a long send that
        // is still making progress is never mistaken for an abandoned one.
        $abandonedBefore = Db::prepareDateForDb((new DateTime('now', $utc))->modify('-' . max(1, $abandonHours) . ' hours'));

        $stalled = (new Query())
            ->select(['id', 'notificationId', 'targeted', 'processed', 'delivered'])
            ->from(Table::OCCURRENCES)
            ->where(['status' => Occurrence::STATUS_SENDING])
            ->andWhere(['<', 'dateUpdated', $abandonedBefore])
            ->all();

        $closed = 0;

        foreach ($stalled as $row) {
            $delivered = (int)$row['delivered'];
            $missing = max(0, (int)$row['targeted'] - (int)$row['processed']);
            $status = $delivered > 0 ? Occurrence::STATUS_SENT : Occurrence::STATUS_FAILED;

            $updated = $db->createCommand()->update(Table::OCCURRENCES, [
                'status' => $status,
                'lastError' => sprintf('Abandoned after %d hour(s) without progress: %d recipient(s) never reported back.', max(1, $abandonHours), $missing),
                'dateSent' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], [
                'and',
                ['id' => $row['id']],
                // Guarded, so a batch that finishes the send between the select and here wins.
                ['status' => Occurrence::STATUS_SENDING],
                ['<', 'dateUpdated', $abandonedBefore],
            ])->execute();

            if ($updated === 0) {
                continue;
            }

            $closed++;

            if ($row['notificationId'] !== null) {
                Plugin::getInstance()->notifications->setStateById(
                    (int)$row['notificationId'],
                    $delivered > 0 ? Notification::STATE_SENT : Notification::STATE_FAILED,
                );
            }

            Plugin::warning(sprintf('Closed occurrence #%d, abandoned mid-send with %d recipient(s) unaccounted for.', $row['id'], $missing));
        }

        return $reclaimed + $closed;
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
     * Records that a batch has finished, and — for exactly one batch — closes the send.
     *
     * Returns `null` while the send is still going, and the occurrence's **accumulated** totals to the
     * one caller whose batch completed it. Those totals are what the outcome is decided from. Deciding
     * from the calling batch's own counts instead marks a send that delivered 9,999 of 10,000 as
     * `failed` whenever the last batch to finish happened to be the one with the dead device in it.
     *
     * Closing is a single conditional `UPDATE … WHERE processed >= targeted AND status IN (…)`, so the
     * database picks the winner. Incrementing and then *reading* `processed` is not enough: two batches
     * finishing together can both increment before either reads, and then both see "complete" and
     * both close the send.
     *
     * @return array{targeted: int, processed: int, delivered: int, failed: int}|null
     */
    public function completeBatch(?int $occurrenceId, int $processed, int $delivered, int $failed): ?array
    {
        if ($occurrenceId === null) {
            return null;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $db->createCommand()->update(Table::OCCURRENCES, [
            'processed' => new \yii\db\Expression('[[processed]] + :p', [':p' => $processed]),
            'delivered' => new \yii\db\Expression('[[delivered]] + :d', [':d' => $delivered]),
            'failed' => new \yii\db\Expression('[[failed]] + :f', [':f' => $failed]),
            'dateUpdated' => $now,
        ], ['id' => $occurrenceId])->execute();

        $closed = $db->createCommand()->update(Table::OCCURRENCES, [
            'status' => new \yii\db\Expression('CASE WHEN [[delivered]] > 0 THEN :sr_sent ELSE :sr_failed END', [
                ':sr_sent' => Occurrence::STATUS_SENT,
                ':sr_failed' => Occurrence::STATUS_FAILED,
            ]),
            'lastError' => new \yii\db\Expression('CASE WHEN [[delivered]] > 0 THEN NULL ELSE :sr_nothing END', [
                ':sr_nothing' => 'Nothing was delivered.',
            ]),
            'dateSent' => $now,
            'dateUpdated' => $now,
        ], [
            'and',
            ['id' => $occurrenceId],
            // `claimed` as well as `sending`, for a send raised by an automation before it learned to
            // say `sending`; never anything already closed, so a late batch cannot reopen the outcome.
            ['status' => [Occurrence::STATUS_CLAIMED, Occurrence::STATUS_SENDING]],
            // Zero targeted means the send never resolved an audience, which `Sender::dispatch()` has
            // already marked `skipped`. Treating it as complete here would overwrite that.
            ['>', 'targeted', 0],
            '[[processed]] >= [[targeted]]',
        ])->execute();

        if ($closed === 0) {
            return null;
        }

        $row = (new Query())
            ->select(['targeted', 'processed', 'delivered', 'failed'])
            ->from(Table::OCCURRENCES)
            ->where(['id' => $occurrenceId])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return [
            'targeted' => (int)$row['targeted'],
            'processed' => (int)$row['processed'],
            'delivered' => (int)$row['delivered'],
            'failed' => (int)$row['failed'],
        ];
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

        // Quiet hours are deliberately **not** applied here. They defer recurring sends only — the
        // documented contract (docs/usage.md, "Quiet hours") is that a notification sent immediately
        // or once at a stated time "goes when you told it to". A one-off at 23:00 is a decision a
        // person made about one message; a recurring 23:00 is a rule that will keep landing in the
        // night long after anyone remembers setting it.
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

        // The rule's anchor: its start date, or — for a rule with none — the day it was created. Never
        // "now". The runner expands every minute, and a rule re-anchored at now on every pass loses
        // its phase ("every other week" fires weekly), lands a yearly rule on today's date every day,
        // and restarts its `maxOccurrences` count each time so the rule never stops.
        $anchor = match (true) {
            $schedule->startDate !== null => DateTimeImmutable::createFromMutable($schedule->startDate),
            $schedule->dateCreated !== null => DateTimeImmutable::createFromMutable($schedule->dateCreated),
            default => new DateTimeImmutable('now', $siteZone),
        };

        // Output starts at today — a rule whose start date is in the past resumes, it never backfills;
        // expanding a year of missed Tuesdays and then sending all of them is the worst possible reading
        // of "every Tuesday". A day earlier than today, because a subscriber zone behind the site's may
        // still be on yesterday's date with its 09:00 ahead of it. `isFuture()` drops whatever has
        // actually passed, and `existingKeys()` makes the overlap free.
        $notBefore = (new DateTimeImmutable('now', $siteZone))->modify('-1 day');

        $dates = Recurrence::dates(
            (string)$schedule->frequency,
            $anchor,
            $horizon,
            $siteZone,
            $schedule->interval,
            $schedule->getByWeekday(),
            $schedule->getByMonthDay(),
            // Counted from the anchor by `dates()` itself, so the occurrences already materialised and
            // sent are part of the count rather than a fresh allowance on every pass.
            $schedule->maxOccurrences,
            $notBefore,
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
        $utc = new DateTimeZone('UTC');

        foreach ($rows as $row) {
            // Parsed as UTC and normalised to UTC, because that is what the insert side keys on. Read
            // in PHP's default zone instead, a value the driver returns with an offset attached keys
            // as a different wall-clock time, nothing ever matches, and the "idempotent" expansion
            // inserts a duplicate of every row on every pass.
            $out[(new DateTime((string)$row['dueAt'], $utc))->setTimezone($utc)->format('Y-m-d H:i:s') . '|' . ((string)($row['timezone'] ?? ''))] = true;
        }

        return $out;
    }
}
