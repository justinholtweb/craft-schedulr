<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;

/**
 * Everything about who is on the list.
 *
 * Subscribing is cheap, public, unauthenticated and happens thousands of times a day; sending is
 * expensive, authenticated and happens once per device per message. The asymmetry is why this
 * service and `Sender` are separate: the failure handling has nothing in common. A subscribe that
 * fails is retried by the browser on the next page load. A send that fails has to decide, right
 * there, whether the device is temporarily unreachable or permanently gone — because a list that
 * never removes dead endpoints spends the rest of its life pushing to browsers that were
 * uninstalled.
 */
class Subscribers extends Component
{
    /**
     * Records that a browser was here.
     *
     * Called on every page load, so it must be one write and no reads beyond the lookup. Returns
     * the subscriber so the caller can answer the runtime's questions in the same request.
     */
    public function touch(
        string $visitorId,
        ?int $siteId = null,
        ?string $timezone = null,
        ?string $language = null,
    ): ?Subscriber {
        $visitorId = $this->normaliseVisitorId($visitorId);

        if ($visitorId === null) {
            return null;
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $now = new DateTime();
        $existing = $this->getByVisitorId($visitorId, $siteId);

        if ($existing === null) {
            return $this->create($visitorId, $siteId, $timezone, $language);
        }

        $update = [
            'dateLastSeen' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
        ];

        // Counted per *session*, not per page view, so "visited five times" means five visits
        // rather than five clicks through one visit — which is what a segment built on it assumes.
        if ($existing->dateLastSeen === null || $now->getTimestamp() - $existing->dateLastSeen->getTimestamp() > 1800) {
            $update['visits'] = $existing->visits + 1;
            $existing->visits++;
        }

        // Refreshed on every heartbeat rather than captured once: people travel, and a stale zone
        // is what sends "good morning" at 3am.
        if ($timezone !== null && $timezone !== '' && $timezone !== $existing->timezone) {
            $update['timezone'] = $timezone;
            $existing->timezone = $timezone;
        }

        if ($language !== null && $language !== '' && $language !== $existing->language) {
            $update['language'] = substr($language, 0, 12);
            $existing->language = $update['language'];
        }

        $userId = Craft::$app->getUser()->getId();

        // Claiming an anonymous browser for a user who has just signed in is what collapses one
        // person's several devices for frequency capping.
        if ($userId !== null && $existing->userId !== $userId) {
            $update['userId'] = $userId;
            $existing->userId = $userId;
        }

        Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, $update, ['id' => $existing->id])->execute();

        $existing->dateLastSeen = $now;

        return $existing;
    }

    /**
     * Records a push subscription, or refreshes the one this browser already had.
     *
     * The endpoint is the push identity and the visitor ID is the site identity, and they can
     * disagree: a browser whose subscription was rotated presents a new endpoint with the same
     * visitor ID, and a browser whose storage was cleared presents the same endpoint with a new
     * visitor ID. Both have to collapse onto one row, or the same person is notified twice.
     *
     * @param array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $subscription
     */
    public function subscribe(
        string $visitorId,
        array $subscription,
        ?int $siteId = null,
        ?string $timezone = null,
        ?string $language = null,
    ): ?Subscriber {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $p256dh = trim((string)($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string)($subscription['keys']['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return null;
        }

        // Not a cosmetic check. An endpoint is a URL this server will POST to, unauthenticated, on
        // a schedule — so it has to be https and it has to be a real host, or the subscribe
        // endpoint is an SSRF gadget anybody on the internet can aim.
        if (!$this->isAcceptableEndpoint($endpoint)) {
            Plugin::warning('Refused a push subscription with an unusable endpoint.');

            return null;
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $hash = hash('sha256', $endpoint);
        $now = new DateTime();

        $subscriber = $this->getByVisitorId($this->normaliseVisitorId($visitorId) ?? '', $siteId)
            ?? $this->getByEndpointHash($hash, $siteId);

        if ($subscriber === null) {
            $subscriber = $this->create($this->normaliseVisitorId($visitorId) ?? StringHelper::UUID(), $siteId, $timezone, $language);

            if ($subscriber === null) {
                return null;
            }
        }

        // A different row already holds this endpoint — the same browser after a storage clear.
        // Its history is worth less than not notifying one person twice, so it goes.
        $duplicate = $this->getByEndpointHash($hash, $siteId);

        if ($duplicate !== null && $duplicate->id !== $subscriber->id) {
            $this->delete($duplicate->id);
        }

        $update = [
            'endpoint' => $endpoint,
            'endpointHash' => $hash,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'contentEncoding' => 'aes128gcm',
            'failures' => 0,
            'unsubscribed' => false,
            // Cleared, because granting permission is the visitor changing their mind and the
            // prompt must stop treating them as someone who said no.
            'dateDeclined' => null,
            'dateSubscribed' => Db::prepareDateForDb($subscriber->dateSubscribed ?? $now),
            'dateLastSeen' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
        ];

        if ($timezone !== null && $timezone !== '') {
            $update['timezone'] = $timezone;
        }

        if ($language !== null && $language !== '') {
            $update['language'] = substr($language, 0, 12);
        }

        // `Craft::$app->getRequest()` is a `craft\console\Request` outside a web request and has no
        // `getUserAgent()` at all — so subscribing from a console command, a queue job, or an adoption
        // pass would fatal with `UnknownMethodException`.
        $userAgent = $this->userAgent();

        if (Plugin::getInstance()->getSettings()->storeUserAgent && $userAgent !== '') {
            $update['userAgent'] = substr($userAgent, 0, 500);
        }

        $update['platform'] = $this->detectPlatform($userAgent);

        Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, $update, ['id' => $subscriber->id])->execute();

        return $this->getById($subscriber->id);
    }

    /**
     * Forgets a push subscription while keeping the visitor.
     *
     * Deleting the row instead would lose the record that this person declined, and the prompt
     * would ask them again on their next page load.
     */
    public function unsubscribePush(string $endpoint, ?int $siteId = null): bool
    {
        $hash = hash('sha256', trim($endpoint));

        $condition = ['endpointHash' => $hash];

        if ($siteId !== null) {
            $condition['siteId'] = $siteId;
        }

        $affected = Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
            'endpoint' => null,
            'endpointHash' => null,
            'p256dh' => null,
            'auth' => null,
            'dateSubscribed' => null,
            'dateDeclined' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], $condition)->execute();

        return $affected > 0;
    }

    /** Opts a subscriber out of everything, on every channel, permanently. */
    public function unsubscribeAll(int $id): bool
    {
        return Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
            'unsubscribed' => true,
            'endpoint' => null,
            'endpointHash' => null,
            'p256dh' => null,
            'auth' => null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id])->execute() > 0;
    }

    /** Records that the visitor said no, so the prompt honours it. */
    public function decline(string $visitorId, ?int $siteId = null): bool
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $visitorId = $this->normaliseVisitorId($visitorId);

        if ($visitorId === null) {
            return false;
        }

        return Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
            'dateDeclined' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['visitorId' => $visitorId, 'siteId' => $siteId])->execute() > 0;
    }

    // --------------------------------------------------------------------------- reading

    public function getById(?int $id): ?Subscriber
    {
        if ($id === null) {
            return null;
        }

        $row = $this->query()->where(['id' => $id])->one();

        return $row ? $this->toModel($row) : null;
    }

    public function getByVisitorId(string $visitorId, ?int $siteId = null): ?Subscriber
    {
        if ($visitorId === '') {
            return null;
        }

        $query = $this->query()->where(['visitorId' => $visitorId]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $row = $query->one();

        return $row ? $this->toModel($row) : null;
    }

    public function getByEndpointHash(string $hash, ?int $siteId = null): ?Subscriber
    {
        $query = $this->query()->where(['endpointHash' => $hash]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $row = $query->one();

        return $row ? $this->toModel($row) : null;
    }

    /**
     * @return Subscriber[]
     */
    public function findAll(array $criteria = [], int $offset = 0, ?int $limit = null): array
    {
        $query = $this->buildQuery($criteria);

        if ($offset > 0) {
            $query->offset($offset);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map(fn(array $row) => $this->toModel($row), $query->all());
    }

    public function count(array $criteria = []): int
    {
        // `count()` comes back as a string from PDO on some drivers, and a string compared with
        // `>=` against an int limit is a comparison nobody meant to write.
        return (int)$this->buildQuery($criteria)->count();
    }

    /**
     * The counts the CP leads with.
     *
     * @return array<string, int>
     */
    public function stats(?int $siteId = null): array
    {
        $base = fn() => (new Query())->from(Table::SUBSCRIBERS)
            ->where($siteId !== null ? ['siteId' => $siteId] : []);

        return [
            'total' => (int)$base()->count(),
            'pushable' => (int)$base()->andWhere(self::pushableCondition())->count(),
            'emailable' => (int)$base()->andWhere(['unsubscribed' => false])->andWhere(['not', ['email' => null]])->count(),
            'declined' => (int)$base()->andWhere(['not', ['dateDeclined' => null]])->andWhere(['endpointHash' => null])->count(),
            'unsubscribed' => (int)$base()->andWhere(['unsubscribed' => true])->count(),
        ];
    }

    /**
     * The distinct time zones on the list, with a count each.
     *
     * This is what a per-subscriber-timezone send fans out over, so it is a real query rather than
     * a walk of the whole list: on a large site the difference is thirty rows against fifty
     * thousand.
     *
     * @return array<string, int>
     */
    public function timezones(?int $siteId = null): array
    {
        $rows = (new Query())
            ->select(['timezone', 'total' => 'COUNT(*)'])
            ->from(Table::SUBSCRIBERS)
            ->where(['unsubscribed' => false])
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['timezone'])
            ->all();

        $out = [];
        $siteZone = Craft::$app->getTimeZone();

        foreach ($rows as $row) {
            $zone = (string)(($row['timezone'] ?? '') ?: $siteZone);

            // Unknown *and unusable* zones are both folded into the site's. A browser can report a zone
            // this PHP's database has never heard of, and if the census listed it the CP would promise
            // "N zones, N deliveries" while the fan-out silently produced N-1. Folding here keeps the
            // number the CP shows equal to the number of occurrences a send actually creates.
            if ($zone !== $siteZone && !$this->isUsableTimeZone($zone)) {
                $zone = $siteZone;
            }

            $out[$zone] = ($out[$zone] ?? 0) + (int)$row['total'];
        }

        ksort($out);

        return $out;
    }

    // ------------------------------------------------------------------------------ tags

    /**
     * @param array<string, string|null> $tags
     */
    public function setTags(int $subscriberId, array $tags): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        foreach ($tags as $tag => $value) {
            $tag = substr(trim($tag), 0, 120);

            if ($tag === '') {
                continue;
            }

            $clean = $value !== null ? substr($value, 0, 255) : null;

            // The value belongs in **both** halves. `Db::upsert()`'s first argument is what gets
            // inserted when there is no conflicting row, and its third is what gets updated when there
            // is — so a value passed only to the update half is silently null on every fresh row, and
            // the bug only shows up for tags nobody had set before.
            Db::upsert(Table::SUBSCRIBER_TAGS, [
                'subscriberId' => $subscriberId,
                'tag' => $tag,
                'value' => $clean,
            ], [
                'value' => $clean,
                'dateUpdated' => $now,
            ], db: $db);
        }
    }

    public function removeTag(int $subscriberId, string $tag): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::SUBSCRIBER_TAGS, ['subscriberId' => $subscriberId, 'tag' => $tag])
            ->execute();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function tagsFor(array $subscriberIds): array
    {
        if ($subscriberIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['subscriberId', 'tag', 'value'])
            ->from(Table::SUBSCRIBER_TAGS)
            ->where(['subscriberId' => $subscriberIds])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(int)$row['subscriberId']][(string)$row['tag']] = $row['value'];
        }

        return $out;
    }

    /** @return string[] */
    public function allTags(): array
    {
        return (new Query())
            ->select(['tag'])
            ->distinct()
            ->from(Table::SUBSCRIBER_TAGS)
            ->orderBy(['tag' => SORT_ASC])
            ->column();
    }

    // -------------------------------------------------------------------------- delivery

    /** A device that answered. Resets the failure count, because failures must be *consecutive*. */
    public function markReached(int $id): void
    {
        Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
            'failures' => 0,
            'dateLastNotified' => Db::prepareDateForDb(new DateTime()),
            'notifiedCount' => new \yii\db\Expression('[[notifiedCount]] + 1'),
        ], ['id' => $id])->execute();
    }

    /**
     * A device that did not answer.
     *
     * At the ceiling the *subscription* is dropped and the visitor is kept: pushing forever to a
     * browser that was uninstalled costs a request per send per device, and it is the single
     * biggest reason a mature push list gets slow.
     */
    public function markFailed(int $id, int $failures): void
    {
        $max = Plugin::getInstance()->getSettings()->pushMaxFailures;

        if ($failures + 1 >= $max) {
            Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
                'endpoint' => null,
                'endpointHash' => null,
                'p256dh' => null,
                'auth' => null,
                'failures' => 0,
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $id])->execute();

            return;
        }

        Craft::$app->getDb()->createCommand()->update(Table::SUBSCRIBERS, [
            'failures' => $failures + 1,
        ], ['id' => $id])->execute();
    }

    public function delete(int $id): bool
    {
        return Craft::$app->getDb()->createCommand()->delete(Table::SUBSCRIBERS, ['id' => $id])->execute() > 0;
    }

    /**
     * Forgets browsers not seen for this many days.
     *
     * @return int How many were forgotten.
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return Craft::$app->getDb()->createCommand()->delete(Table::SUBSCRIBERS, [
            'and',
            ['<', 'dateLastSeen', Db::prepareDateForDb($cutoff)],
        ])->execute();
    }

    // -------------------------------------------------------------------------- internals

    private function create(string $visitorId, int $siteId, ?string $timezone, ?string $language): ?Subscriber
    {
        $now = Db::prepareDateForDb(new DateTime());
        $settings = Plugin::getInstance()->getSettings();
        $userAgent = $this->userAgent();

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::SUBSCRIBERS, [
                'siteId' => $siteId,
                'userId' => Craft::$app->getUser()->getId(),
                'visitorId' => $visitorId,
                'timezone' => $timezone,
                'language' => $language !== null ? substr($language, 0, 12) : null,
                'platform' => $this->detectPlatform($userAgent),
                'userAgent' => $settings->storeUserAgent ? substr($userAgent, 0, 500) : null,
                'visits' => 1,
                'dateFirstSeen' => $now,
                'dateLastSeen' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // Two page loads from one browser can race the unique index on (visitorId, siteId).
            // Losing that race means the row exists, which is the outcome we wanted.
            $existing = $this->getByVisitorId($visitorId, $siteId);

            if ($existing !== null) {
                return $existing;
            }

            Plugin::error('Could not record a visitor: ' . $e->getMessage());

            return null;
        }

        return $this->getByVisitorId($visitorId, $siteId);
    }

    /**
     * "Push can reach this row", in SQL.
     *
     * All three key parts, not just the endpoint — the same test `Subscriber::isPushable()` applies in
     * PHP. A predicate that checked only the endpoint would target a half-written row and then throw
     * inside the encryptor, halfway through a queue job, for a device that was never reachable.
     */
    public static function pushableCondition(): array
    {
        return [
            'and',
            ['unsubscribed' => false],
            ['not', ['endpointHash' => null]],
            ['not', ['p256dh' => null]],
            ['not', ['auth' => null]],
        ];
    }

    private function query(): Query
    {
        return (new Query())
            ->select([
                'id', 'siteId', 'userId', 'visitorId', 'endpoint', 'endpointHash', 'p256dh', 'auth',
                'contentEncoding', 'email', 'emailVerified', 'language', 'timezone', 'platform',
                'userAgent', 'country', 'visits', 'dateFirstSeen', 'dateLastSeen', 'dateSubscribed',
                'dateLastNotified', 'notifiedCount', 'dateDeclined', 'unsubscribed', 'failures',
                'dateCreated', 'uid',
            ])
            ->from(Table::SUBSCRIBERS);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function buildQuery(array $criteria): Query
    {
        $query = $this->query()->orderBy(['dateLastSeen' => SORT_DESC, 'id' => SORT_DESC]);

        if (isset($criteria['siteId'])) {
            $query->andWhere(['siteId' => $criteria['siteId']]);
        }

        if (!empty($criteria['pushable'])) {
            $query->andWhere(self::pushableCondition());
        }

        if (!empty($criteria['emailable'])) {
            $query->andWhere(['not', ['email' => null]])->andWhere(['unsubscribed' => false]);
        }

        if (isset($criteria['unsubscribed'])) {
            $query->andWhere(['unsubscribed' => (bool)$criteria['unsubscribed']]);
        }

        if (!empty($criteria['timezone'])) {
            $query->andWhere(['timezone' => $criteria['timezone']]);
        }

        if (!empty($criteria['search'])) {
            $search = (string)$criteria['search'];
            $query->andWhere(['or',
                ['like', 'email', $search],
                ['like', 'platform', $search],
                ['like', 'timezone', $search],
                ['like', 'visitorId', $search],
            ]);
        }

        if (!empty($criteria['ids'])) {
            $query->andWhere(['id' => $criteria['ids']]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function toModel(array $row): Subscriber
    {
        // Craft's `Model::__construct()` already typecasts scalars and converts the attributes
        // named in `datetimeAttributes()` from DB strings, so the row goes through raw. Casting by
        // hand here would be redundant, and running `prepareDateForDb` on the way *in* is
        // backwards.
        return new Subscriber($row);
    }

    private function normaliseVisitorId(string $visitorId): ?string
    {
        $visitorId = trim($visitorId);

        // The visitor ID is generated by the browser, which means it is attacker-controlled: it is
        // written to a `char(36)` column and read back into segments, so it is validated as a UUID
        // rather than merely truncated.
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $visitorId)) {
            return null;
        }

        return strtolower($visitorId);
    }

    /**
     * Whether an endpoint is one this server is willing to POST to on a schedule.
     *
     * The subscribe endpoint is public and unauthenticated, so without this check anybody on the
     * internet can register `http://169.254.169.254/…` and have the site's own queue fetch it
     * every time a notification goes out.
     */
    private function isAcceptableEndpoint(string $endpoint): bool
    {
        if (!str_starts_with($endpoint, 'https://')) {
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        // A bare IP is never a push service; every one of them is a named host behind DNS.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return str_contains($host, '.') && !str_ends_with(strtolower($host), '.localhost');
    }

    private function isUsableTimeZone(string $name): bool
    {
        try {
            new \DateTimeZone($name);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The user agent, or an empty string when there is no web request.
     */
    private function userAgent(): string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return '';
        }

        return (string)$request->getUserAgent();
    }

    private function detectPlatform(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        // Coarse on purpose. A segment wants "Android" and "iOS"; a version string would make
        // every browser update look like a different platform and quietly split every segment.
        return match (true) {
            (bool)preg_match('/iPhone|iPad|iPod/i', $userAgent) => 'iOS',
            (bool)preg_match('/Android/i', $userAgent) => 'Android',
            (bool)preg_match('/Macintosh|Mac OS X/i', $userAgent) => 'macOS',
            (bool)preg_match('/Windows/i', $userAgent) => 'Windows',
            (bool)preg_match('/Linux|X11/i', $userAgent) => 'Linux',
            default => null,
        };
    }
}
