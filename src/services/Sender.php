<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\channels\ChannelInterface;
use justinholtweb\schedulr\channels\EmailChannel;
use justinholtweb\schedulr\channels\OnSiteChannel;
use justinholtweb\schedulr\channels\PushChannel;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Delivery;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\models\Variant;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\queue\jobs\SendBatch;
use Throwable;

/**
 * Getting one notification to everybody it is for.
 *
 * The pipeline is: **resolve the audience per channel → assign variants → batch → queue → send →
 * record**. Each arrow is there for a reason that shows up at scale:
 *
 * - *Per channel*, because the three channels do not share a recipient list. Resolving once and
 *   sending everywhere means a ledger full of failures that were never attempts.
 * - *Batched and queued*, because a fifty-thousand-device send cannot happen inside the request
 *   that scheduled it, and because a worker that dies must lose one batch rather than its place in
 *   the list.
 * - *Recorded per recipient per channel*, because "did that go out, and to how many" is asked
 *   every single time, and a count kept only on the notification cannot answer "which ones failed".
 *
 * The dedupe policy is applied **inside** a batch, per subscriber, rather than while resolving the
 * audience — it has to be, because "email the people push did not reach" is not knowable until the
 * push has been attempted.
 */
class Sender extends Component
{
    /** @var array<string, ChannelInterface>|null */
    private ?array $channels = null;

    /**
     * @return array<string, ChannelInterface>
     */
    public function getChannels(): array
    {
        return $this->channels ??= [
            Notification::CHANNEL_PUSH => new PushChannel(),
            Notification::CHANNEL_EMAIL => new EmailChannel(),
            Notification::CHANNEL_ONSITE => new OnSiteChannel(),
        ];
    }

    public function getChannel(string $handle): ?ChannelInterface
    {
        return $this->getChannels()[$handle] ?? null;
    }

    // ------------------------------------------------------------------------- dispatching

    /**
     * Sends a notification straight away.
     *
     * Goes through an occurrence like everything else rather than taking a shortcut, so that a
     * manual send and a scheduled one produce identical ledgers — and so "send now" is testable by
     * the same path the runner uses.
     */
    public function sendNow(Notification $notification): ?Occurrence
    {
        $plugin = Plugin::getInstance();
        $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

        if ($occurrence === null) {
            return null;
        }

        $this->dispatch($occurrence);

        // Re-read, because `dispatch()` writes the counts to the row rather than to this model — and
        // every caller reports `$occurrence->targeted` to a human. Returning the pre-dispatch model
        // makes a send that reached fifty thousand people announce itself as "queued to 0 recipients",
        // which reads as a broken plugin rather than a stale object.
        return $plugin->schedules->getOccurrenceById($occurrence->id) ?? $occurrence;
    }

    /**
     * Resolves an occurrence's audience and queues the work.
     *
     * Returns the number of recipients queued across all channels. Zero is a legitimate outcome and
     * marks the occurrence `skipped` rather than `sent` — "we sent to nobody" and "there was nobody
     * to send to" are different facts, and only one of them means something is misconfigured.
     */
    public function dispatch(Occurrence $occurrence): int
    {
        $plugin = Plugin::getInstance();
        $notification = $plugin->notifications->getById($occurrence->notificationId);

        if ($notification === null) {
            $plugin->schedules->markOccurrence($occurrence->id, Occurrence::STATUS_CANCELLED, 'The notification no longer exists.');

            return 0;
        }

        $subscriberIds = $this->resolveAudience($notification, $occurrence);

        if ($subscriberIds === []) {
            $plugin->schedules->markOccurrence($occurrence->id, Occurrence::STATUS_SKIPPED, 'Nobody matched the audience.');
            $plugin->notifications->setState($notification, Notification::STATE_SENT);

            return 0;
        }

        $plugin->schedules->markOccurrence($occurrence->id, Occurrence::STATUS_SENDING);
        $plugin->notifications->setState($notification, Notification::STATE_SENDING);
        $plugin->schedules->setOccurrenceCounts($occurrence->id, targeted: count($subscriberIds));
        $plugin->notifications->addTargeted($notification->id, count($subscriberIds));

        $batchSize = max(1, $plugin->getSettings()->batchSize);
        $batches = array_chunk($subscriberIds, $batchSize);
        $total = count($batches);

        foreach ($batches as $index => $batch) {
            Queue::push(new SendBatch([
                'occurrenceId' => $occurrence->id,
                'notificationId' => $notification->id,
                'subscriberIds' => $batch,
                'batchNumber' => $index + 1,
                'batchCount' => $total,
            ]));
        }

        Plugin::info(sprintf(
            'Queued notification #%d to %d subscriber(s) in %d batch(es).',
            $notification->id,
            count($subscriberIds),
            $total,
        ));

        return count($subscriberIds);
    }

    // ---------------------------------------------------------------------------- audience

    /**
     * Who this occurrence is for.
     *
     * The union of everybody reachable on *any* enabled channel — narrowing per channel happens at
     * send time, because a subscriber reachable on two channels is one recipient here and two
     * ledger rows there.
     *
     * @return int[]
     */
    public function resolveAudience(Notification $notification, ?Occurrence $occurrence = null): array
    {
        $plugin = Plugin::getInstance();

        $query = (new Query())
            ->select(['id'])
            ->from(Table::SUBSCRIBERS)
            ->where(['unsubscribed' => false]);

        if ($notification->siteId !== null) {
            $query->andWhere(['siteId' => $notification->siteId]);
        }

        // The occurrence's time zone doubles as its audience slice: this is what makes "09:00
        // local" mean 09:00 for each of thirty zones rather than 09:00 in one of them.
        if ($occurrence !== null && $occurrence->timezone !== null) {
            $query->andWhere($this->timezoneCondition($occurrence->timezone));
        }

        // Reachability, expressed in SQL rather than by loading everybody and filtering. On a
        // hundred-thousand-row list the difference is the send happening at all.
        $reach = ['or'];

        foreach ($notification->getChannels() as $channel) {
            $reach[] = match ($channel) {
                // The same three-part test `Subscriber::isPushable()` applies, expressed once.
                Notification::CHANNEL_PUSH => Subscribers::pushableCondition(),
                Notification::CHANNEL_EMAIL => ['or', ['not', ['email' => null]], ['not', ['userId' => null]]],
                // Everybody who has ever loaded a page.
                Notification::CHANNEL_ONSITE => ['not', ['id' => null]],
                default => ['id' => null],
            };
        }

        if (count($reach) === 1) {
            return [];
        }

        $query->andWhere($reach);

        // Applied whatever the edition. Editions gate *choosing* a segment, in the editor; they never
        // gate honouring one that is already saved. Skipping the condition on Lite turned "lapsed
        // readers in Germany" into "everybody" on the day a licence lapsed, which is the single worst
        // thing this plugin could do with a downgrade.
        if ($notification->audienceId !== null) {
            $audience = $plugin->audiences->getById($notification->audienceId);

            if ($audience !== null) {
                $plugin->audiences->applyCondition($query, $audience);
            }
        }

        $topics = $notification->getTopics();

        if ($topics !== []) {
            // Topics are subscriber tags, so this is a join rather than a JSON containment test —
            // which is spelled differently on MySQL and Postgres and is the reason the tags live in
            // their own table at all.
            $query->andWhere(['id' => (new Query())
                ->select(['subscriberId'])
                ->from(Table::SUBSCRIBER_TAGS)
                ->where(['tag' => $topics]),
            ]);
        }

        // Configured caps apply whatever the edition, for the same reason the segment does: a lapsed
        // licence must not turn "no more than three a week" into "as many as we send".
        $this->applyFrequencyCaps($query);

        /** @var int[] $ids */
        $ids = array_map('intval', $query->column());

        return $ids;
    }

    /**
     * Folds the unknown-zone case into the site's zone.
     *
     * Without this, every subscriber whose browser never reported a zone is silently excluded from
     * every per-zone send — a bug that looks like "the schedule works but reaches fewer people
     * every month" and is invisible in any test whose fixtures all have zones.
     */
    private function timezoneCondition(string $timezone): array
    {
        if ($timezone !== Craft::$app->getTimeZone()) {
            return ['timezone' => $timezone];
        }

        return ['or', ['timezone' => $timezone], ['timezone' => null], ['timezone' => '']];
    }

    /**
     * Quiet hours and "no more than N per period", applied while resolving rather than at send.
     *
     * Excluded here, not skipped later, so a capped subscriber never becomes a `skipped` ledger row
     * — thousands of those per send would bury the skips that mean something.
     */
    private function applyFrequencyCaps(Query $query): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $capPerDays = $settings->frequencyCapDays;
        $capCount = $settings->frequencyCapCount;

        if ($capPerDays <= 0 || $capCount <= 0) {
            return;
        }

        $since = Db::prepareDateForDb((new DateTime())->modify("-{$capPerDays} days"));

        $overCap = (new Query())
            ->select(['subscriberId'])
            ->from(Table::DELIVERIES)
            ->where(['status' => [Delivery::STATUS_DELIVERED, Delivery::STATUS_QUEUED]])
            ->andWhere(['>=', 'dateCreated', $since])
            ->andWhere(['not', ['subscriberId' => null]])
            ->groupBy(['subscriberId'])
            ->having(['>=', 'COUNT(*)', $capCount]);

        $query->andWhere(['not', ['id' => $overCap]]);
    }

    // ------------------------------------------------------------------------------ sending

    /**
     * Sends one batch. Called from the queue job, never inline.
     *
     * @param int[] $subscriberIds
     * @return array{delivered: int, failed: int, skipped: int}
     */
    public function sendBatch(Notification $notification, array $subscriberIds, ?int $occurrenceId = null): array
    {
        $plugin = Plugin::getInstance();
        $subscribers = $plugin->subscribers->findAll(['ids' => $subscriberIds]);
        $variants = $this->variantsFor($notification);
        $policy = $this->policyFor($notification);
        $channels = $notification->getChannels();

        $rows = [];
        $counts = ['delivered' => 0, 'failed' => 0, 'skipped' => 0];
        $reached = [];
        $failedIds = [];

        foreach ($subscribers as $subscriber) {
            $variant = $this->pickVariant($variants, $notification->id, $subscriber->id);
            $overrides = $variant?->overrides() ?? [];
            $sentOnAny = false;

            foreach ($channels as $handle) {
                $channel = $this->getChannel($handle);

                if ($channel === null || !$channel->canReach($subscriber)) {
                    continue;
                }

                // The dedupe policy, applied here because "email the people push did not reach" is
                // not knowable until the push has actually been attempted.
                if ($policy === Settings::DEDUPE_FIRST && $sentOnAny) {
                    break;
                }

                if ($policy === Settings::DEDUPE_FALLBACK && $sentOnAny && $handle === Notification::CHANNEL_EMAIL) {
                    continue;
                }

                $result = $channel->send($notification, $subscriber, $overrides, $variant?->id);

                $rows[] = [
                    'notificationId' => $notification->id,
                    'occurrenceId' => $occurrenceId,
                    'variantId' => $variant?->id,
                    'subscriberId' => $subscriber->id,
                    'channel' => $handle,
                    'status' => $result->status,
                    'statusCode' => $result->statusCode,
                    'error' => $result->error,
                ];

                if ($result->isSuccess()) {
                    $sentOnAny = true;
                    $counts['delivered']++;
                    $reached[$subscriber->id] = true;
                } elseif ($result->status === Delivery::STATUS_SKIPPED) {
                    $counts['skipped']++;
                } else {
                    $counts['failed']++;

                    if ($handle === Notification::CHANNEL_PUSH) {
                        // `gone` retires the subscription immediately; `failed` only counts against
                        // it, because a push service having a bad afternoon is not a dead device.
                        if ($result->status === Delivery::STATUS_GONE) {
                            $failedIds[$subscriber->id] = 'gone';
                        } elseif (self::countsAgainstDevice($result->statusCode)) {
                            $failedIds[$subscriber->id] = $subscriber->failures;
                        }
                    }
                }
            }
        }

        $this->applySubscriberOutcomes($reached, $failedIds);
        $this->recordDeliveries($rows);

        return $counts;
    }

    /**
     * Writes the ledger, after re-checking which subscribers still exist.
     *
     * This re-check is not defensive padding. A device dropped as `410 gone` during *this very
     * batch* has already had its row deleted, and the foreign key is checked at insert even with
     * `ON DELETE SET NULL` — so without this the whole `batchInsert` dies and takes the record of
     * every delivery in the batch with it, including the successful ones.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function recordDeliveries(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_column($rows, 'subscriberId'))));

        $surviving = $ids === [] ? [] : array_flip(array_map('intval', (new Query())
            ->select(['id'])
            ->from(Table::SUBSCRIBERS)
            ->where(['id' => $ids])
            ->column()));

        $now = Db::prepareDateForDb(new DateTime());
        $values = [];

        foreach ($rows as $row) {
            $subscriberId = $row['subscriberId'];

            $values[] = [
                $row['notificationId'],
                $row['occurrenceId'],
                $row['variantId'],
                $subscriberId !== null && isset($surviving[$subscriberId]) ? $subscriberId : null,
                $row['channel'],
                $row['status'],
                $row['statusCode'],
                $row['error'] !== null ? substr($row['error'], 0, 2000) : null,
                $now,
                $now,
                StringHelper::UUID(),
            ];
        }

        try {
            Craft::$app->getDb()->createCommand()->batchInsert(Table::DELIVERIES, [
                'notificationId', 'occurrenceId', 'variantId', 'subscriberId', 'channel',
                'status', 'statusCode', 'error', 'dateCreated', 'dateUpdated', 'uid',
            ], $values)->execute();
        } catch (Throwable $e) {
            // The send already happened. Losing the ledger is bad; throwing here would make the
            // queue retry the batch and notify everybody a second time, which is worse.
            Plugin::error('Could not write ' . count($values) . ' delivery row(s): ' . $e->getMessage());
        }
    }

    /**
     * Whether a failed push says something about the *device*, and so should count towards
     * `pushMaxFailures`.
     *
     * 401/403 mean this site's VAPID credentials are wrong, 413 means the message is too big, and
     * 429/5xx mean the push service is busy. Every device fails identically on those, so counting
     * them would let five sends with a bad keypair strip every subscriber's push subscription. A
     * connection failure (no status code) and anything else in the 4xx range do count.
     */
    public static function countsAgainstDevice(?int $statusCode): bool
    {
        if ($statusCode === null) {
            return true;
        }

        return !in_array($statusCode, [401, 403, 413, 429], true) && $statusCode < 500;
    }

    /**
     * @param array<int, true> $reached
     * @param array<int, int|string> $failed
     */
    private function applySubscriberOutcomes(array $reached, array $failed): void
    {
        $subscribers = Plugin::getInstance()->subscribers;

        foreach (array_keys($reached) as $id) {
            $subscribers->markReached((int)$id);
        }

        foreach ($failed as $id => $state) {
            if (isset($reached[$id])) {
                // Reached on another channel. Not a failing device.
                continue;
            }

            if ($state === 'gone') {
                $subscribers->unsubscribePush((string)($subscribers->getById((int)$id)->endpoint ?? ''));

                continue;
            }

            $subscribers->markFailed((int)$id, (int)$state);
        }
    }

    // ------------------------------------------------------------------------------ variants

    /**
     * @return Variant[]
     */
    private function variantsFor(Notification $notification): array
    {
        // Saved variants are honoured whatever the edition: Lite cannot *add* an arm (the editor and
        // `Notifications::saveVariants()` see to that), but a test that was running when the licence
        // lapsed keeps splitting its audience the way it was set up rather than silently collapsing
        // into arm A halfway through.
        $variants = Plugin::getInstance()->notifications->getVariantModels($notification->id);

        // One variant is not a test — it is the notification, whose own fields are already being
        // used. Returning it would apply its (possibly empty) overrides on top of itself.
        return count($variants) > 1 ? $variants : [];
    }

    /**
     * Which arm this subscriber is in.
     *
     * Deterministic, from a hash of the notification and subscriber IDs, so a retried batch puts
     * the same person in the same arm. Randomising here would let a queue retry show one person
     * both variants and quietly corrupt the result the test exists to produce.
     *
     * @param Variant[] $variants
     */
    private function pickVariant(array $variants, ?int $notificationId, ?int $subscriberId): ?Variant
    {
        if ($variants === []) {
            return null;
        }

        $total = array_sum(array_map(static fn(Variant $v) => max(0, $v->share), $variants));

        if ($total <= 0) {
            return $variants[0];
        }

        $bucket = (int)(hexdec(substr(md5($notificationId . ':' . $subscriberId), 0, 8)) % $total);
        $running = 0;

        foreach ($variants as $variant) {
            $running += max(0, $variant->share);

            if ($bucket < $running) {
                return $variant;
            }
        }

        // Reached only when the shares do not total what they claimed. The last arm takes the
        // remainder, which is the same rule the CP uses when normalising them.
        return $variants[count($variants) - 1];
    }

    /**
     * The notification's saved dedupe policy, whatever the edition.
     *
     * Lite cannot *choose* a policy — the editor leaves a Lite notification on its default — but a
     * policy chosen while the site was Pro keeps applying. Forcing `none` on downgrade would make every
     * multi-channel notification notify everybody once per channel, which is exactly how a site loses
     * its push permission.
     */
    private function policyFor(Notification $notification): string
    {
        return $notification->dedupePolicy;
    }
}
