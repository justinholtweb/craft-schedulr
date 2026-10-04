<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Delivery;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\Plugin;

/**
 * What happened after the send.
 *
 * **Click tracking rides on the URL, not on a worker-reported event**, and that is the decision the
 * rest of this class follows from. It matters because on a site running PWA, the worker that
 * receives Schedulr's notifications is *PWA's* worker — a worker that has never heard of Schedulr
 * and will never report anything to it. A tracked URL keeps working through any worker, any email
 * client, and any on-site link.
 *
 * The tracked URL deliberately **does not carry the destination**. Two reasons, and the second is
 * the one that would have hurt:
 *
 * 1. A push payload has a hard ceiling around 4KB after encryption, and a URL carrying another
 *    URL plus tracking parameters is the thing that pushes the one important message over it.
 * 2. A redirect endpoint that takes its target from a query parameter is an open redirect. Signed
 *    or not, it becomes a phishing gadget hosted on the customer's own domain.
 *
 * So the URL carries only IDs, the destination is looked up server-side, and the IDs are signed —
 * otherwise anyone who received one notification could inflate the click count on every campaign.
 */
class Analytics extends Component
{
    public const EVENT_DISPLAYED = 'displayed';
    public const EVENT_CLICKED = 'clicked';
    public const EVENT_DISMISSED = 'dismissed';
    public const EVENT_CONVERTED = 'converted';
    public const EVENT_UNSUBSCRIBED = 'unsubscribed';

    /**
     * A URL that records the click and then goes where it was going.
     *
     * Returns the destination untouched when tracking is off or there is nothing to track, so a Lite
     * install links straight through rather than bouncing through an endpoint that does nothing.
     */
    public function trackedUrl(
        string $url,
        ?int $notificationId,
        ?int $variantId = null,
        ?int $subscriberId = null,
        string $channel = 'push',
        ?int $buttonIndex = null,
    ): string {
        $url = trim($url);

        if ($notificationId === null) {
            return $url;
        }

        if (!Edition::allowsClickTracking(Plugin::getInstance()->isPro())) {
            return $url;
        }

        $params = [
            'sr_n' => $notificationId,
            'sr_c' => $channel,
        ];

        if ($variantId !== null) {
            $params['sr_v'] = $variantId;
        }

        if ($subscriberId !== null) {
            $params['sr_s'] = $subscriberId;
        }

        if ($buttonIndex !== null) {
            $params['sr_b'] = $buttonIndex;
        }

        $params['sr_k'] = $this->sign($params);

        // Namespaced with `sr_`, not `n`/`s`/`t`. `token` and `p` are both reserved by Craft — a
        // request carrying `?token=` is rejected in `Application::init()` before any controller runs,
        // and `?p=` is how Craft is told which path was requested — so a tracking link using either
        // 404s while the same route with no query string works fine.
        return UrlHelper::siteUrl('schedulr/go', $params);
    }

    /**
     * The signature over a tracked URL's parameters.
     *
     * Truncated to twelve hex characters. The full digest would add fifty bytes to every push payload
     * for no security this needs: forging one is worth a single fake click, and twelve characters is
     * 48 bits of work per attempt for that.
     *
     * @param array<string, mixed> $params
     */
    public function sign(array $params): string
    {
        unset($params['sr_k']);
        ksort($params);

        $payload = http_build_query($params);

        return substr(hash_hmac('sha256', $payload, $this->key('click')), 0, 12);
    }

    /**
     * A key for one purpose, derived from the site's security key.
     *
     * Never the raw security key. Craft signs cookies, `hashData()` payloads and its own tokens with
     * it, and a MAC computed with the same key over attacker-chosen input is an oracle for all of
     * them. A derived key per purpose also means a click signature can never be replayed as an event
     * signature, or the other way round.
     */
    private function key(string $purpose): string
    {
        $securityKey = (string)Craft::$app->getConfig()->getGeneral()->securityKey;

        return hash_hmac('sha256', 'schedulr:' . $purpose . ':v1', $securityKey);
    }

    /**
     * The signature a push payload carries so the worker can attribute a display or a dismissal.
     *
     * The worker has no visitor ID — it has no page and no localStorage — only the subscriber ID the
     * payload gave it. A bare numeric ID on an unauthenticated endpoint would let anybody write events
     * against every subscriber on the list, so the ID travels with a MAC over exactly that triple.
     */
    public function eventSignature(int $notificationId, ?int $variantId, int $subscriberId): string
    {
        $payload = $notificationId . ':' . ($variantId ?? '') . ':' . $subscriberId;

        return substr(hash_hmac('sha256', $payload, $this->key('event')), 0, 12);
    }

    public function verifyEventSignature(int $notificationId, ?int $variantId, int $subscriberId, string $given): bool
    {
        return $given !== '' && hash_equals($this->eventSignature($notificationId, $variantId, $subscriberId), $given);
    }

    /**
     * Whether a destination is one a link may be pointed at: http(s), or a path with no scheme.
     *
     * Applied on the way *out* as well as at save time, because a destination is also read from rows
     * written before validation existed, and the redirect is the last place a `javascript:` URL can be
     * stopped before it runs on the site's own origin.
     */
    public static function isSafeDestination(string $url): bool
    {
        return $url !== '' && Notification::isSafeUrl($url);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function verify(array $params): bool
    {
        $given = (string)($params['sr_k'] ?? '');

        if ($given === '') {
            return false;
        }

        // Constant-time, because a signature check that leaks its comparison position is a signature
        // check that can be walked one character at a time.
        return hash_equals($this->sign($params), $given);
    }

    /**
     * Where a tracked link should actually go.
     */
    public function destinationFor(int $notificationId, ?int $variantId, ?int $buttonIndex): ?string
    {
        $notification = Plugin::getInstance()->notifications->getById($notificationId);

        if ($notification === null) {
            return null;
        }

        if ($buttonIndex !== null) {
            $buttons = $notification->getButtons();
            $url = trim((string)($buttons[$buttonIndex]['url'] ?? ''));

            if ($url !== '') {
                return $url;
            }
        }

        if ($variantId !== null) {
            foreach (Plugin::getInstance()->notifications->getVariantModels($notificationId) as $variant) {
                if ($variant->id === $variantId && trim((string)$variant->url) !== '') {
                    return (string)$variant->url;
                }
            }
        }

        $url = trim((string)$notification->url);

        return $url !== '' ? $url : null;
    }

    // ------------------------------------------------------------------------------- events

    /**
     * Records an event only if this subscriber has not already recorded it for this notification.
     *
     * For the unauthenticated event endpoint. "Displayed" and "dismissed" are facts that happen once
     * per person per notification, so a repeat is either a retry or somebody replaying the request —
     * and in both cases writing another row only grows the table.
     */
    public function recordOnce(
        string $type,
        int $notificationId,
        ?int $variantId,
        int $subscriberId,
        ?string $channel = null,
    ): bool {
        $exists = (new Query())
            ->from(Table::EVENTS)
            ->where([
                'type' => $type,
                'notificationId' => $notificationId,
                'subscriberId' => $subscriberId,
            ])
            ->exists();

        if ($exists) {
            return false;
        }

        return $this->record($type, $notificationId, $variantId, $subscriberId, $channel);
    }

    /**
     * Records one event, and rolls the notification's counter if it is a click.
     *
     * Clicks are deduped per subscriber per notification. Without that, a notification somebody
     * opens on Monday and again on Friday reads as a 200% click rate — and rates above 100% are how
     * a report loses its audience.
     */
    public function record(
        string $type,
        ?int $notificationId,
        ?int $variantId = null,
        ?int $subscriberId = null,
        ?string $channel = null,
        ?string $url = null,
    ): bool {
        if (!in_array($type, [
            self::EVENT_DISPLAYED,
            self::EVENT_CLICKED,
            self::EVENT_DISMISSED,
            self::EVENT_CONVERTED,
            self::EVENT_UNSUBSCRIBED,
        ], true)) {
            return false;
        }

        // Every event belongs to a notification except an unsubscribe from the email link, which
        // signs the subscriber and nothing else. Refusing those silently meant no unsubscribe was
        // ever recorded.
        if ($notificationId === null && $type !== self::EVENT_UNSUBSCRIBED) {
            return false;
        }

        $isFirst = true;

        if ($subscriberId !== null) {
            $isFirst = !(new Query())
                ->from(Table::EVENTS)
                ->where([
                    'type' => $type,
                    'notificationId' => $notificationId,
                    'subscriberId' => $subscriberId,
                ])
                ->exists();
        }

        $now = Db::prepareDateForDb(new DateTime());

        // The event row is written even when it is a repeat: "how many times was this opened" is a
        // real question, and it is only the *rate* that needs deduping.
        Craft::$app->getDb()->createCommand()->insert(Table::EVENTS, [
            'notificationId' => $notificationId,
            'variantId' => $variantId,
            'subscriberId' => $subscriberId,
            'channel' => $channel !== null ? substr($channel, 0, 16) : null,
            'type' => $type,
            'url' => $url !== null ? substr($url, 0, 1000) : null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        if ($type === self::EVENT_CLICKED && $isFirst) {
            Plugin::getInstance()->notifications->addClick($notificationId);

            if ($variantId !== null) {
                Craft::$app->getDb()->createCommand()->update(Table::VARIANTS, [
                    'clicked' => new \yii\db\Expression('[[clicked]] + 1'),
                ], ['id' => $variantId])->execute();
            }
        }

        return true;
    }

    // ------------------------------------------------------------------------------ reports

    /**
     * The funnel for one notification.
     *
     * Rates are returned as null rather than zero when there is no denominator. "0%" against zero
     * deliveries reads as a campaign that failed rather than one that has not been sent, and it is
     * the number people screenshot.
     *
     * @return array<string, int|float|null>
     */
    public function funnel(int $notificationId): array
    {
        $notification = Plugin::getInstance()->notifications->getById($notificationId);

        if ($notification === null) {
            return [];
        }

        $events = (new Query())
            ->select(['type', 'total' => 'COUNT(*)', 'people' => 'COUNT(DISTINCT [[subscriberId]])'])
            ->from(Table::EVENTS)
            ->where(['notificationId' => $notificationId])
            ->groupBy(['type'])
            ->all();

        $byType = [];

        foreach ($events as $row) {
            $byType[(string)$row['type']] = ['total' => (int)$row['total'], 'people' => (int)$row['people']];
        }

        $delivered = $notification->delivered;
        $displayed = $byType[self::EVENT_DISPLAYED]['people'] ?? 0;
        $clicked = $byType[self::EVENT_CLICKED]['people'] ?? 0;
        $dismissed = $byType[self::EVENT_DISMISSED]['people'] ?? 0;

        return [
            'targeted' => $notification->targeted,
            'delivered' => $delivered,
            'failed' => $notification->failed,
            'displayed' => $displayed,
            'clicked' => $clicked,
            'dismissed' => $dismissed,
            'clicks' => $byType[self::EVENT_CLICKED]['total'] ?? 0,
            'deliveryRate' => $notification->targeted > 0
                ? round(($delivered / $notification->targeted) * 100, 1)
                : null,
            'clickRate' => $delivered > 0 ? round(($clicked / $delivered) * 100, 1) : null,
            // Against *displayed*, not delivered. A dismissal only exists once something was shown,
            // and dividing by delivered would flatter every campaign whose notifications were never
            // seen.
            'dismissRate' => $displayed > 0 ? round(($dismissed / $displayed) * 100, 1) : null,
        ];
    }

    /**
     * Daily totals for the dashboard.
     *
     * @return array<int, array<string, int|string>>
     */
    public function daily(int $days = 30): array
    {
        $since = Db::prepareDateForDb((new DateTime())->modify('-' . max(1, $days) . ' days'));

        $deliveries = (new Query())
            ->select([
                'day' => $this->dayExpression('dateCreated'),
                'status',
                'total' => 'COUNT(*)',
            ])
            ->from(Table::DELIVERIES)
            ->where(['>=', 'dateCreated', $since])
            ->groupBy(['day', 'status'])
            ->all();

        $events = (new Query())
            ->select([
                'day' => $this->dayExpression('dateCreated'),
                'type',
                'total' => 'COUNT(*)',
            ])
            ->from(Table::EVENTS)
            ->where(['>=', 'dateCreated', $since])
            ->groupBy(['day', 'type'])
            ->all();

        $series = [];

        // Every day in the window, including the empty ones. A chart drawn from only the days that
        // have data compresses a quiet fortnight into a single tick and makes a decline look flat.
        $cursor = new DateTime('-' . max(1, $days) . ' days');
        $end = new DateTime('tomorrow');

        while ($cursor < $end) {
            $series[$cursor->format('Y-m-d')] = [
                'day' => $cursor->format('Y-m-d'),
                'delivered' => 0,
                'failed' => 0,
                'clicked' => 0,
                'displayed' => 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($deliveries as $row) {
            $day = (string)$row['day'];

            if (!isset($series[$day])) {
                continue;
            }

            if ($row['status'] === Delivery::STATUS_DELIVERED || $row['status'] === Delivery::STATUS_QUEUED) {
                $series[$day]['delivered'] += (int)$row['total'];
            } elseif ($row['status'] === Delivery::STATUS_FAILED || $row['status'] === Delivery::STATUS_GONE) {
                $series[$day]['failed'] += (int)$row['total'];
            }
        }

        foreach ($events as $row) {
            $day = (string)$row['day'];

            if (isset($series[$day], $series[$day][(string)$row['type']])) {
                $series[$day][(string)$row['type']] += (int)$row['total'];
            }
        }

        return array_values($series);
    }

    /**
     * Truncating a datetime to a date, in a way both drivers accept.
     *
     * `DATE()` exists on MySQL and not on Postgres, where it is `CAST(x AS date)`. Getting this wrong
     * produces a report that works locally and throws on the customer's cluster.
     */
    private function dayExpression(string $column): \yii\db\Expression
    {
        $isMysql = Craft::$app->getDb()->getIsMysql();

        return new \yii\db\Expression($isMysql ? "DATE([[$column]])" : "CAST([[$column]] AS date)");
    }

    /**
     * The best and worst performers, for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(int $limit = 10, int $minimumDelivered = 20): array
    {
        $rows = (new Query())
            ->select(['id', 'delivered', 'clicked', 'failed', 'targeted', 'dateLastSent'])
            ->from(Table::NOTIFICATIONS)
            ->where(['>=', 'delivered', $minimumDelivered])
            ->orderBy(['dateLastSent' => SORT_DESC])
            ->limit(100)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $delivered = (int)$row['delivered'];

            $out[] = [
                'id' => (int)$row['id'],
                'delivered' => $delivered,
                'clicked' => (int)$row['clicked'],
                'clickRate' => $delivered > 0 ? round(((int)$row['clicked'] / $delivered) * 100, 1) : null,
                'dateLastSent' => $row['dateLastSent'],
            ];
        }

        usort($out, static fn(array $a, array $b) => ($b['clickRate'] ?? 0) <=> ($a['clickRate'] ?? 0));

        return array_slice($out, 0, $limit);
    }

    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        return Deliveries::deleteInChunks(Table::EVENTS, ['<', 'dateCreated', $cutoff]);
    }
}
