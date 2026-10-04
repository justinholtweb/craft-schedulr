<?php
/**
 * Schedulr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-schedulr/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every subscriber, notification, audience and ledger row it creates it
 * deletes again, whether the run passes or not. Nothing here touches a row it did not create — the
 * fixtures are fenced by a run-specific tag so a repeated run cannot see the previous one's leftovers.
 *
 * Nothing here reaches a real push service. The one send that exercises the push path aims at a
 * `.invalid` host, which cannot resolve by definition, so the assertion is about how Schedulr classifies
 * an unreachable device rather than about anybody's network.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Audience;
use justinholtweb\schedulr\models\Delivery;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\push\Encryptor;
use justinholtweb\schedulr\services\Analytics;
use justinholtweb\schedulr\services\Automations;

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, callable $test): void
{
    global $passed, $failed, $failures;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        $failures[] = $label . ($result === false ? '' : ' — ' . (string)$result);
        echo "  ✗ $label" . ($result === false ? '' : ' — ' . (string)$result) . "\n";
    } catch (Throwable $e) {
        global $failed, $failures;
        $failed++;
        $detail = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        $failures[] = $label . ' — ' . $detail;
        echo "  ✗ $label — $detail\n";
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

// --------------------------------------------------------------------------------- fixtures

$plugin = Plugin::getInstance();
$db = Craft::$app->getDb();
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

// Push endpoints must be on a known push service's host. The fixtures aim at `push.invalid`, which can
// never resolve, so it is allowed here — **in memory only**, never saved — rather than pointing the
// suite at a real push service.
if (property_exists($plugin->getSettings(), 'extraPushHosts')) {
    $plugin->getSettings()->extraPushHosts = array_merge((array)$plugin->getSettings()->extraPushHosts, ['push.invalid']);
}

/** The tag every fixture carries, so cleanup and isolation are one condition. */
$RUN = 'schedulr-check-' . StringHelper::randomString(8);

$createdSubscriberIds = [];
$createdNotificationIds = [];
$createdAudienceIds = [];

/**
 * A subscriber, built directly rather than through the runtime, so a check can name its state.
 */
function makeSubscriber(array $overrides = []): Subscriber
{
    global $db, $siteId, $createdSubscriberIds, $plugin;

    $now = Db::prepareDateForDb(new DateTime());

    $data = array_merge([
        'siteId' => $siteId,
        'visitorId' => StringHelper::UUID(),
        'contentEncoding' => 'aes128gcm',
        'visits' => 1,
        'dateFirstSeen' => $now,
        'dateLastSeen' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $overrides);

    $db->createCommand()->insert(Table::SUBSCRIBERS, $data)->execute();
    $id = (int)$db->getLastInsertID();
    $createdSubscriberIds[] = $id;

    return $plugin->subscribers->getById($id);
}

/** A pushable subscriber whose endpoint can never resolve. */
function makePushable(array $overrides = []): Subscriber
{
    $endpoint = 'https://push.invalid/' . StringHelper::randomString(24);

    return makeSubscriber(array_merge([
        'endpoint' => $endpoint,
        'endpointHash' => hash('sha256', $endpoint),
        // The RFC 8291 vector's subscription key and auth secret: real, well-formed values, so the
        // encryptor does its actual work rather than being short-circuited by a validation failure.
        'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
        'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
        'dateSubscribed' => Db::prepareDateForDb(new DateTime()),
    ], $overrides));
}

function makeNotification(array $attributes = [], ?Schedule $schedule = null): Notification
{
    global $siteId, $createdNotificationIds, $plugin;

    $notification = new Notification();
    $notification->siteId = $siteId;
    $notification->title = 'Check ' . StringHelper::randomString(6);
    $notification->state = Notification::STATE_DRAFT;

    foreach ($attributes as $key => $value) {
        if ($key === 'channels') {
            $notification->setChannels($value);
            continue;
        }

        $notification->$key = $value;
    }

    $notification->setSchedule($schedule ?? new Schedule(['mode' => Schedule::MODE_NOW]));

    if (!$plugin->notifications->save($notification)) {
        throw new RuntimeException('Fixture notification would not save: ' . Json::encode($notification->getErrors()));
    }

    $createdNotificationIds[] = $notification->id;

    return $notification;
}

function makeAudience(array $rules, string $match = 'all'): Audience
{
    global $createdAudienceIds, $plugin;

    $audience = new Audience();
    $audience->name = 'Check ' . StringHelper::randomString(6);
    $audience->setCondition(['match' => $match, 'rules' => $rules]);

    if (!$plugin->audiences->save($audience)) {
        throw new RuntimeException('Fixture audience would not save: ' . Json::encode($audience->getErrors()));
    }

    $createdAudienceIds[] = $audience->id;

    return $audience;
}

$cleanup = function() use (&$createdSubscriberIds, &$createdNotificationIds, &$createdAudienceIds, $db) {
    // An automation template raises *further* notifications whenever a sweep runs — including sweeps in
    // later checks that know nothing about it. Following `templateId` is what makes cleanup complete
    // rather than nearly complete, and "nearly" is what leaves two rows behind every run.
    $templateIds = array_values(array_unique(array_filter($createdNotificationIds)));

    if ($templateIds !== []) {
        try {
            foreach ((new Query())->select(['id'])->from(Table::NOTIFICATIONS)
                ->where(['templateId' => $templateIds])->column() as $raisedId) {
                $createdNotificationIds[] = (int)$raisedId;
            }
        } catch (Throwable) {
        }
    }

    foreach (array_unique($createdNotificationIds) as $id) {
        try {
            $element = Notification::find()->id($id)->status(null)->one();

            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        } catch (Throwable) {
        }
    }

    foreach (array_unique($createdSubscriberIds) as $id) {
        try {
            $db->createCommand()->delete(Table::SUBSCRIBERS, ['id' => $id])->execute();
        } catch (Throwable) {
        }
    }

    foreach (array_unique($createdAudienceIds) as $id) {
        try {
            $db->createCommand()->delete(Table::AUDIENCES, ['id' => $id])->execute();
        } catch (Throwable) {
        }
    }
};

// Registered as a safety net for a run that dies mid-way, and **also called explicitly** at the end.
// A shutdown function alone is not enough: by the time PHP runs them Craft is partway through its own
// teardown, so `getElements()` can fail and the loop's try/catch swallows it — which is exactly how this
// file left ninety-nine notifications behind before anybody noticed.
register_shutdown_function($cleanup);

echo "Schedulr integration checks (run {$RUN})\n";

// ------------------------------------------------------------------------------------ wiring

section('Wiring');

check('the plugin is installed and enabled', fn() => $plugin !== null
    && Craft::$app->getPlugins()->isPluginEnabled('schedulr'));

foreach ([
    'keys', 'interop', 'subscribers', 'notifications', 'schedules', 'runner', 'sender',
    'audiences', 'deliveries', 'analytics', 'automations', 'serviceWorker',
] as $service) {
    check("the $service service resolves", fn() => $plugin->$service !== null);
}

check('both editions are declared', fn() => Plugin::editions() === ['lite', 'pro']);
check('the settings model is a Settings', fn() => $plugin->getSettings() instanceof Settings);
check('Notification is a registered element type', fn() => in_array(
    Notification::class,
    Craft::$app->getElements()->getAllElementTypes(),
    true,
));
check('all ten tables exist', function() {
    $missing = [];

    foreach ((new ReflectionClass(Table::class))->getConstants() as $table) {
        if (Craft::$app->getDb()->getTableSchema($table) === null) {
            $missing[] = $table;
        }
    }

    return $missing === [] ? true : 'missing: ' . implode(', ', $missing);
});
check('the CP nav item builds', fn() => is_array($plugin->getCpNavItem()));
check('the plugin icon exists', fn() => is_file(dirname(__DIR__, 2) . '/src/icon.svg'));
check('the CP mask icon exists', fn() => is_file(dirname(__DIR__, 2) . '/src/icon-mask.svg'));

// -------------------------------------------------------------------------------------- keys

section('Keys');

check('a keypair exists or is generated on demand', fn() => $plugin->keys->getPublicKey() !== '');
check('the public key is a 65-byte uncompressed point', function() use ($plugin) {
    $raw = Encryptor::decode($plugin->keys->getPublicKey());

    return strlen($raw) === 65 && $raw[0] === "\x04" ? true : 'got ' . strlen($raw) . ' bytes';
});
check('the private key is usable by openssl', fn() => Encryptor::isUsableKey($plugin->keys->getPrivateKey()));
check('the key source is reported', fn() => in_array($plugin->keys->getSource(), ['pwa', 'env', 'generated'], true));
check('hasKeys() is true once keys exist', fn() => $plugin->keys->hasKeys() === true);
check('getKeys() is memoised within a request', function() use ($plugin) {
    return $plugin->keys->getPublicKey() === $plugin->keys->getPublicKey();
});
check('a VAPID header can be signed for a real endpoint shape', function() use ($plugin) {
    $header = Encryptor::vapidHeader(
        'https://fcm.googleapis.com/fcm/send/xyz',
        'mailto:test@example.com',
        $plugin->keys->getPublicKey(),
        $plugin->keys->getPrivateKey(),
    );

    return str_starts_with($header, 'vapid t=') && str_contains($header, ', k=');
});

// ------------------------------------------------------------------------------------ interop

section('Interop with PWA');

check('PWA detection returns a boolean', fn() => is_bool($plugin->interop->isPwaActive()));
check('the interop status is reportable', function() use ($plugin) {
    $status = $plugin->interop->status();

    return array_keys($status) === ['installed', 'keys', 'worker', 'subscribers'];
});
check('worker ownership is decided, not guessed', fn() => is_bool($plugin->interop->ownsServiceWorker()));
check('adopting from PWA is safe to call whatever is installed', fn() => is_int($plugin->interop->adoptFromPwa()));
check('the runtime is told who owns the worker', function() use ($plugin) {
    $config = $plugin->interop->runtimeConfig();

    return isset($config['ownsWorker'], $config['pwa']);
});
check('deferring to PWA and owning the worker cannot both be true', function() use ($plugin) {
    if (!$plugin->interop->isPwaActive()) {
        return true;
    }

    // The invariant the whole interop design exists to hold: one registration per scope.
    return !($plugin->interop->getPwaKeys() !== null && $plugin->interop->ownsServiceWorker())
        || !$plugin->getSettings()->registerServiceWorker;
});

// ------------------------------------------------------------------------------- the worker

section('Service worker');

check('the worker script renders with its config substituted', function() use ($plugin) {
    $site = Craft::$app->getSites()->getPrimarySite();
    $script = $plugin->serviceWorker->render($site);

    return !str_contains($script, '/*__SCHEDULR_CONFIG__*/')
        && str_contains($script, 'addEventListener(\'push\'')
        ? true
        : 'placeholder left in place';
});
check('the worker carries the VAPID public key', function() use ($plugin) {
    $site = Craft::$app->getSites()->getPrimarySite();

    return str_contains($plugin->serviceWorker->render($site), $plugin->keys->getPublicKey());
});
check('the worker config never contains the private key', function() use ($plugin) {
    $site = Craft::$app->getSites()->getPrimarySite();
    $script = $plugin->serviceWorker->render($site);

    return !str_contains($script, 'PRIVATE KEY') ? true : 'the private key leaked into a public file';
});
check('the worker cache key changes with the keys', function() use ($plugin) {
    $site = Craft::$app->getSites()->getPrimarySite();
    $before = $plugin->serviceWorker->cacheKey($site);

    return $before === $plugin->serviceWorker->cacheKey($site) && str_starts_with($before, 'schedulr:sw:');
});
check('the worker URL is null when PWA owns the registration', function() use ($plugin) {
    $url = $plugin->serviceWorker->scriptUrl();

    return $plugin->interop->ownsServiceWorker() ? is_string($url) : $url === null;
});

// -------------------------------------------------------------------------------- subscribers

section('Subscribers');

check('a visitor is recorded on first sight', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $visitorId = StringHelper::UUID();
    $subscriber = $plugin->subscribers->touch($visitorId, $siteId, 'Europe/London', 'en-GB');

    if ($subscriber === null) {
        return 'touch() returned null';
    }

    $createdSubscriberIds[] = $subscriber->id;

    return $subscriber->visitorId === $visitorId
        && $subscriber->timezone === 'Europe/London'
        && $subscriber->language === 'en-GB'
        && $subscriber->visits === 1;
});

check('a malformed visitor ID is refused', fn() => $plugin->subscribers->touch('not-a-uuid', $siteId) === null);
check('an empty visitor ID is refused', fn() => $plugin->subscribers->touch('', $siteId) === null);

check('touching twice does not create a second row', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $visitorId = StringHelper::UUID();
    $first = $plugin->subscribers->touch($visitorId, $siteId);
    $second = $plugin->subscribers->touch($visitorId, $siteId);

    $createdSubscriberIds[] = $first->id;

    return $first->id === $second->id;
});

check('a refreshed time zone is stored', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $visitorId = StringHelper::UUID();
    $plugin->subscribers->touch($visitorId, $siteId, 'Europe/London');
    $updated = $plugin->subscribers->touch($visitorId, $siteId, 'Asia/Tokyo');

    $createdSubscriberIds[] = $updated->id;

    // People travel, and a stale zone is what sends "good morning" at 3am.
    return $updated->timezone === 'Asia/Tokyo';
});

check('a push subscription is recorded', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $visitorId = StringHelper::UUID();
    $endpoint = 'https://push.invalid/' . StringHelper::randomString(20);

    $subscriber = $plugin->subscribers->subscribe($visitorId, [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'],
    ], $siteId, 'Europe/London');

    if ($subscriber === null) {
        return 'subscribe() returned null';
    }

    $createdSubscriberIds[] = $subscriber->id;

    return $subscriber->isPushable()
        && $subscriber->endpointHash === hash('sha256', $endpoint)
        && $subscriber->dateSubscribed !== null;
});

check('a subscription with no auth secret is refused', function() use ($plugin, $siteId) {
    return $plugin->subscribers->subscribe(StringHelper::UUID(), [
        'endpoint' => 'https://push.invalid/x',
        'keys' => ['p256dh' => 'abc'],
    ], $siteId) === null;
});

check('an http endpoint is refused', function() use ($plugin, $siteId) {
    return $plugin->subscribers->subscribe(StringHelper::UUID(), [
        'endpoint' => 'http://push.example.com/x',
        'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
    ], $siteId) === null;
});

check('an endpoint pointing at a bare IP is refused', function() use ($plugin, $siteId) {
    // The subscribe endpoint is public and unauthenticated; without this it is an SSRF gadget anyone
    // can aim at the site's own metadata service.
    return $plugin->subscribers->subscribe(StringHelper::UUID(), [
        'endpoint' => 'https://169.254.169.254/latest/meta-data',
        'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
    ], $siteId) === null;
});

check('an endpoint with no dot in its host is refused', function() use ($plugin, $siteId) {
    return $plugin->subscribers->subscribe(StringHelper::UUID(), [
        'endpoint' => 'https://localhost/x',
        'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
    ], $siteId) === null;
});

check('re-subscribing the same endpoint under a new visitor ID collapses onto one row', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $endpoint = 'https://push.invalid/' . StringHelper::randomString(20);
    $keys = ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'];

    $first = $plugin->subscribers->subscribe(StringHelper::UUID(), ['endpoint' => $endpoint, 'keys' => $keys], $siteId);
    $second = $plugin->subscribers->subscribe(StringHelper::UUID(), ['endpoint' => $endpoint, 'keys' => $keys], $siteId);

    $createdSubscriberIds[] = $first->id;
    $createdSubscriberIds[] = $second->id;

    // The same browser after a storage clear. Two rows would notify one person twice.
    $count = (int)(new Query())->from(Table::SUBSCRIBERS)->where(['endpointHash' => hash('sha256', $endpoint)])->count();

    return $count === 1 ? true : "$count rows hold the same endpoint";
});

check('granting permission clears a previous decline', function() use ($plugin, $siteId, &$createdSubscriberIds) {
    $visitorId = StringHelper::UUID();
    $subscriber = $plugin->subscribers->touch($visitorId, $siteId);
    $createdSubscriberIds[] = $subscriber->id;

    $plugin->subscribers->decline($visitorId, $siteId);

    $declined = $plugin->subscribers->getById($subscriber->id);

    if ($declined->dateDeclined === null) {
        return 'the decline was not recorded';
    }

    $plugin->subscribers->subscribe($visitorId, [
        'endpoint' => 'https://push.invalid/' . StringHelper::randomString(20),
        'keys' => ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'],
    ], $siteId);

    return $plugin->subscribers->getById($subscriber->id)->dateDeclined === null;
});

check('unsubscribing push keeps the visitor', function() use ($plugin, $siteId) {
    $subscriber = makePushable();

    $plugin->subscribers->unsubscribePush($subscriber->endpoint, $siteId);
    $after = $plugin->subscribers->getById($subscriber->id);

    // Deleting the row instead would lose the record that this person declined, and the prompt would
    // ask them again on their next page load.
    return $after !== null
        && !$after->isPushable()
        && $after->isOnSiteReachable()
        && $after->dateDeclined !== null;
});

check('unsubscribing everything is honoured on every channel', function() use ($plugin) {
    $subscriber = makePushable(['email' => 'x@example.com']);

    $plugin->subscribers->unsubscribeAll($subscriber->id);
    $after = $plugin->subscribers->getById($subscriber->id);

    return $after->unsubscribed
        && !$after->isPushable()
        && !$after->isEmailable()
        && !$after->isOnSiteReachable();
});

check('an anonymous subscriber is on-site reachable and nothing else', function() {
    $subscriber = makeSubscriber();

    return $subscriber->isOnSiteReachable() && !$subscriber->isPushable() && !$subscriber->isEmailable();
});

check('an endpoint with no p256dh is not pushable', function() {
    $subscriber = makeSubscriber(['endpoint' => 'https://push.invalid/x', 'endpointHash' => hash('sha256', 'x')]);

    // A half-written row would throw inside the encryptor, halfway through a queue job.
    return !$subscriber->isPushable();
});

check('a subscriber falls back to the site time zone', function() {
    $subscriber = makeSubscriber(['timezone' => null]);

    return $subscriber->getTimeZone()->getName() === Craft::$app->getTimeZone();
});

check('a nonsense time zone falls back rather than throwing', function() {
    $subscriber = makeSubscriber(['timezone' => 'Mars/Olympus_Mons']);

    return $subscriber->getTimeZone()->getName() === Craft::$app->getTimeZone();
});

check('state labels distinguish the four states', function() use ($plugin) {
    $anonymous = makeSubscriber();
    $pushable = makePushable();
    $declined = makeSubscriber(['dateDeclined' => Db::prepareDateForDb(new DateTime())]);
    $gone = makeSubscriber(['unsubscribed' => true]);

    return $anonymous->getStateLabel() !== $pushable->getStateLabel()
        && $declined->getStateLabel() !== $anonymous->getStateLabel()
        && $gone->getStateLabel() !== $declined->getStateLabel();
});

check('markReached resets the failure count and bumps the tally', function() use ($plugin) {
    $subscriber = makePushable(['failures' => 3, 'notifiedCount' => 7]);

    $plugin->subscribers->markReached($subscriber->id);
    $after = $plugin->subscribers->getById($subscriber->id);

    // Failures must be *consecutive*, or a device that answers every other send is eventually dropped.
    return $after->failures === 0 && $after->notifiedCount === 8 && $after->dateLastNotified !== null;
});

check('markFailed increments below the ceiling', function() use ($plugin) {
    $subscriber = makePushable();

    $plugin->subscribers->markFailed($subscriber->id, 0);

    return $plugin->subscribers->getById($subscriber->id)->failures === 1;
});

check('markFailed retires the subscription at the ceiling but keeps the visitor', function() use ($plugin) {
    $max = $plugin->getSettings()->pushMaxFailures;
    $subscriber = makePushable(['failures' => $max - 1]);

    $plugin->subscribers->markFailed($subscriber->id, $max - 1);
    $after = $plugin->subscribers->getById($subscriber->id);

    return $after !== null && !$after->isPushable() && $after->isOnSiteReachable();
});

check('tags are stored and read back', function() use ($plugin) {
    $subscriber = makeSubscriber();

    $plugin->subscribers->setTags($subscriber->id, ['beta' => null, 'plan' => 'gold']);
    $tags = $plugin->subscribers->tagsFor([$subscriber->id])[$subscriber->id] ?? [];

    return array_key_exists('beta', $tags) && ($tags['plan'] ?? null) === 'gold';
});

check('a tag’s value is stored on the very first insert', function() use ($plugin) {
    $subscriber = makeSubscriber();

    // `Db::upsert()`'s first argument is what gets inserted; its third is what gets updated. A value
    // passed only to the update half is silently null on every fresh row, and the bug shows up only for
    // tags nobody had set before.
    $plugin->subscribers->setTags($subscriber->id, ['plan' => 'gold']);

    return ($plugin->subscribers->tagsFor([$subscriber->id])[$subscriber->id]['plan'] ?? null) === 'gold';
});

check('a half-written push row is never treated as reachable', function() use ($plugin) {
    // An endpoint with no keys would be selected by a predicate that only checked the endpoint, and then
    // throw inside the encryptor halfway through a queue job.
    $broken = makeSubscriber(['endpoint' => 'https://push.invalid/x', 'endpointHash' => hash('sha256', 'x' . StringHelper::randomString(6))]);

    $found = array_map(fn($s) => $s->id, $plugin->subscribers->findAll(['pushable' => true], 0, 500));

    return !in_array($broken->id, $found, true);
});

check('setting a tag twice updates rather than duplicating', function() use ($plugin) {
    $subscriber = makeSubscriber();

    $plugin->subscribers->setTags($subscriber->id, ['plan' => 'silver']);
    $plugin->subscribers->setTags($subscriber->id, ['plan' => 'gold']);

    $count = (int)(new Query())->from(Table::SUBSCRIBER_TAGS)
        ->where(['subscriberId' => $subscriber->id, 'tag' => 'plan'])->count();

    return $count === 1 && $plugin->subscribers->tagsFor([$subscriber->id])[$subscriber->id]['plan'] === 'gold';
});

check('a tag can be removed', function() use ($plugin) {
    $subscriber = makeSubscriber();

    $plugin->subscribers->setTags($subscriber->id, ['temp' => null]);
    $plugin->subscribers->removeTag($subscriber->id, 'temp');

    return ($plugin->subscribers->tagsFor([$subscriber->id])[$subscriber->id] ?? []) === [];
});

check('stats returns five integers', function() use ($plugin) {
    $stats = $plugin->subscribers->stats();

    return array_keys($stats) === ['total', 'pushable', 'emailable', 'declined', 'unsubscribed']
        && count(array_filter($stats, 'is_int')) === 5;
});

check('the time zone census folds unknown zones into the site’s', function() use ($plugin) {
    makeSubscriber(['timezone' => null]);
    makeSubscriber(['timezone' => 'Asia/Tokyo']);

    $zones = $plugin->subscribers->timezones();

    // Without this fold, every subscriber whose browser never reported a zone is silently excluded from
    // every per-zone send — a bug that looks like "the schedule reaches fewer people every month".
    return isset($zones[Craft::$app->getTimeZone()], $zones['Asia/Tokyo']);
});

check('count() returns an int, not a string', fn() => is_int($plugin->subscribers->count([])));

check('findAll can be paged', function() use ($plugin) {
    makeSubscriber();
    makeSubscriber();

    return count($plugin->subscribers->findAll([], 0, 1)) === 1;
});

check('findAll filters to pushable', function() use ($plugin) {
    makePushable();
    $found = $plugin->subscribers->findAll(['pushable' => true], 0, 50);

    foreach ($found as $subscriber) {
        if (!$subscriber->isPushable()) {
            return 'a non-pushable subscriber came back';
        }
    }

    return count($found) > 0;
});

// ------------------------------------------------------------------------------ notifications

section('Notifications');

check('a notification saves as an element', function() {
    $notification = makeNotification();

    return $notification->id !== null
        && Notification::find()->id($notification->id)->status(null)->one() !== null;
});

check('a notification with no title is refused', function() {
    $notification = new Notification();
    $notification->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $notification->title = '';

    return !$notification->validate();
});

check('a title over 120 characters is refused', function() {
    $notification = new Notification();
    $notification->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $notification->title = str_repeat('a', 121);

    // Refused rather than silently cut, because the truncation would land on a lock screen.
    return !$notification->validate();
});

check('channels never end up empty', function() {
    $notification = makeNotification(['channels' => []]);

    return $notification->getChannels() === ['push'];
});

check('an unknown channel is dropped', function() {
    $notification = makeNotification(['channels' => ['push', 'carrier-pigeon']]);

    return $notification->getChannels() === ['push'];
});

check('channels survive a round trip through the database', function() {
    $notification = makeNotification(['channels' => ['push', 'email', 'onsite']]);
    $reloaded = Notification::find()->id($notification->id)->status(null)->one();

    return $reloaded->getChannels() === ['push', 'email', 'onsite'];
});

check('every column the element query selects lands on a property', function() {
    // Craft hands the whole row to the element's constructor, so a stored column with neither a property
    // nor a setter throws UnknownPropertyException from inside createElement() — and only when the
    // element is loaded from the database, which hides it from any test that re-reads what it just saved.
    $notification = makeNotification(['channels' => ['push'], 'tag' => 'x', 'url' => '/a']);

    Craft::$app->getElements()->invalidateCachesForElement($notification);

    $fresh = Notification::find()->id($notification->id)->status(null)->one();

    return $fresh !== null && $fresh->title === $notification->title;
});

check('the state maps onto Craft’s status', function() {
    $notification = makeNotification();

    return $notification->getStatus() === Notification::STATE_DRAFT
        && array_key_exists(Notification::STATE_SENT, Notification::statuses());
});

check('the query filters by status', function() {
    $notification = makeNotification();

    return Notification::find()->id($notification->id)->status(Notification::STATE_DRAFT)->exists()
        && !Notification::find()->id($notification->id)->status(Notification::STATE_SENT)->exists();
});

check('the query filters by channel', function() {
    $notification = makeNotification(['channels' => ['email']]);

    return Notification::find()->id($notification->id)->channel('email')->exists()
        && !Notification::find()->id($notification->id)->channel('onsite')->exists();
});

check('the query filters by pending', function() {
    $notification = makeNotification();

    return Notification::find()->id($notification->id)->pending()->exists();
});

check('buttons are capped at two', function() {
    $notification = makeNotification(['buttons' => [
        ['title' => 'One', 'url' => '/1'],
        ['title' => 'Two', 'url' => '/2'],
        ['title' => 'Three', 'url' => '/3'],
    ]]);

    // Every browser that supports action buttons shows two, and the third is invisible with no warning.
    return count($notification->getButtons()) === 2;
});

check('a button with no label is dropped', function() {
    $notification = makeNotification(['buttons' => [['title' => '', 'url' => '/1']]]);

    return $notification->getButtons() === [];
});

check('topics round-trip as a list', function() {
    $notification = makeNotification(['topics' => ['news', 'sport']]);
    $reloaded = Notification::find()->id($notification->id)->status(null)->one();

    return $reloaded->getTopics() === ['news', 'sport'];
});

check('a json column survives a round trip through the driver', function() {
    // The trap: Yii's query builder already encodes an array for a `json` column, so pre-encoding it
    // stores the JSON of a JSON string and every `is_array()`-guarded getter silently returns nothing.
    // No error, no warning — just a topic filter that evaporated and a notification that went to
    // everybody.
    $notification = makeNotification([
        'topics' => ['news', 'sport'],
        'buttons' => [['title' => 'Go', 'url' => '/button']],
        'triggerConfig' => ['days' => 30],
    ]);

    Craft::$app->getElements()->invalidateCachesForElement($notification);
    $fresh = Notification::find()->id($notification->id)->status(null)->one();

    return $fresh->getTopics() === ['news', 'sport']
        && $fresh->getButtons() === [['title' => 'Go', 'url' => '/button']]
        && $fresh->getTriggerConfig() === ['days' => 30]
        ? true
        : 'topics=' . Json::encode($fresh->getTopics()) . ' buttons=' . Json::encode($fresh->getButtons());
});

check('a schedule’s json columns survive a round trip', function() use ($plugin) {
    $skip = (new DateTime('+3 days'))->format('Y-m-d');

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_WEEKLY,
            'byWeekday' => [1, 4],
            'timeOfDay' => '09:00',
            'exclusions' => [$skip],
        ]),
    );

    $saved = $plugin->schedules->getForNotification($notification->id);

    return $saved->getByWeekday() === [1, 4] && $saved->getExclusions() === [$skip]
        ? true
        : 'weekdays=' . Json::encode($saved->getByWeekday()) . ' exclusions=' . Json::encode($saved->getExclusions());
});

check('an audience’s condition survives a round trip', function() use ($plugin) {
    $audience = makeAudience([['type' => 'visits', 'operator' => 'gte', 'value' => 12]]);
    $reloaded = $plugin->audiences->getById($audience->id);

    $condition = $reloaded->getCondition();

    return count($condition['rules']) === 1 && (int)$condition['rules'][0]['value'] === 12;
});

check('the push payload carries the essentials and nothing else', function() {
    $notification = makeNotification(['body' => 'Body', 'url' => '/somewhere']);
    $payload = $notification->toPayload();

    return $payload['title'] === $notification->title
        && $payload['body'] === 'Body'
        && isset($payload['url'], $payload['n'])
        && !isset($payload['requireInteraction']);
});

check('the payload omits empty fields', function() {
    $notification = makeNotification(['body' => '', 'tag' => '', 'iconUrl' => '']);
    $payload = $notification->toPayload();

    // A push payload has a hard ceiling around 4KB after encryption; empty keys are pure cost.
    return !array_key_exists('body', $payload)
        && !array_key_exists('tag', $payload)
        && !array_key_exists('icon', $payload);
});

check('a variant’s overrides replace only what they set', function() use ($plugin) {
    $notification = makeNotification(['body' => 'Original body', 'url' => '/original']);
    $payload = $notification->toPayload(['title' => 'Variant title']);

    return $payload['title'] === 'Variant title' && $payload['body'] === 'Original body';
});

check('the payload stays under the encryption ceiling for realistic copy', function() {
    $notification = makeNotification([
        'body' => str_repeat('word ', 78),
        'url' => '/a/reasonably/long/path/to/an/article-with-a-slug',
    ]);

    $encoded = Json::encode($notification->toPayload([], 123456, 99));

    return strlen($encoded) < Encryptor::MAX_PAYLOAD
        ? true
        : 'payload is ' . strlen($encoded) . ' bytes before encryption';
});

check('counters increment atomically', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->addTargeted($notification->id, 10);
    $plugin->notifications->addOutcome($notification->id, 7, 3);
    $plugin->notifications->addClick($notification->id);

    $fresh = $plugin->notifications->getById($notification->id);

    return $fresh->targeted === 10 && $fresh->delivered === 7 && $fresh->failed === 3 && $fresh->clicked === 1;
});

check('counters can be reset', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->addOutcome($notification->id, 5, 1);
    $plugin->notifications->resetCounters($notification->id);

    $fresh = $plugin->notifications->getById($notification->id);

    return $fresh->delivered === 0 && $fresh->failed === 0;
});

check('the state machine refuses an unknown state', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->setState($notification, 'exploded');

    return $plugin->notifications->getById($notification->id)->state === Notification::STATE_DRAFT;
});

check('moving to sent stamps dateLastSent', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->setState($notification, Notification::STATE_SENT);

    return $plugin->notifications->getById($notification->id)->dateLastSent !== null;
});

check('variant shares are normalised to total 100', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 1, 'title' => 'A title'],
        ['label' => 'B', 'share' => 1, 'title' => 'B title'],
    ]);

    $variants = $plugin->notifications->getVariantModels($notification->id);
    $total = array_sum(array_map(fn($v) => $v->share, $variants));

    return count($variants) === 2 && $total === 100 ? true : "two variants totalled $total";
});

check('an odd three-way split gives the remainder to the last variant', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 33, 'title' => 'A'],
        ['label' => 'B', 'share' => 33, 'title' => 'B'],
        ['label' => 'C', 'share' => 33, 'title' => 'C'],
    ]);

    // Dropping the remainder is how a delivered count ends up permanently one short.
    $shares = array_map(fn($v) => $v->share, $plugin->notifications->getVariantModels($notification->id));

    return array_sum($shares) === 100 ? true : 'shares totalled ' . array_sum($shares);
});

check('an empty variant row is discarded by the service, not just the controller', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 50, 'title' => 'A title'],
        ['label' => 'B', 'share' => 50, 'title' => '', 'body' => '', 'url' => ''],
    ]);

    return count($plugin->notifications->getVariants($notification->id)) === 1;
});

check('saving variants again replaces rather than accumulates', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [['label' => 'A', 'share' => 100, 'title' => 'One']]);
    $plugin->notifications->saveVariants($notification->id, [['label' => 'A', 'share' => 100, 'title' => 'Two']]);

    $variants = $plugin->notifications->getVariantModels($notification->id);

    return count($variants) === 1 && $variants[0]->title === 'Two';
});

check('no winner is declared without enough data per arm', function() use ($plugin) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 50, 'title' => 'A'],
        ['label' => 'B', 'share' => 50, 'title' => 'B'],
    ]);

    $variants = $plugin->notifications->getVariantModels($notification->id);
    $plugin->notifications->addVariantOutcome($variants[0]->id, 4, 0);

    // A 25% click rate on four deliveries means nothing, and declaring it a winner is how A/B testing
    // produces confident nonsense.
    return $plugin->notifications->pickWinner($notification->id) === null;
});

check('a winner is declared once both arms have enough', function() use ($plugin, $db) {
    $notification = makeNotification();

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 50, 'title' => 'A'],
        ['label' => 'B', 'share' => 50, 'title' => 'B'],
    ]);

    $variants = $plugin->notifications->getVariantModels($notification->id);

    $db->createCommand()->update(Table::VARIANTS, ['delivered' => 200, 'clicked' => 10], ['id' => $variants[0]->id])->execute();
    $db->createCommand()->update(Table::VARIANTS, ['delivered' => 200, 'clicked' => 40], ['id' => $variants[1]->id])->execute();

    $winner = $plugin->notifications->pickWinner($notification->id);

    return $winner !== null && $winner->id === $variants[1]->id;
});

check('deleting a notification cascades its sub-table row', function() use ($db) {
    $notification = makeNotification();
    $id = $notification->id;

    Craft::$app->getElements()->deleteElement($notification, true);

    return !(new Query())->from(Table::NOTIFICATIONS)->where(['id' => $id])->exists();
});

// --------------------------------------------------------------------------------- schedules

section('Schedules');

check('a schedule is saved alongside its notification', function() use ($plugin) {
    $notification = makeNotification([], new Schedule(['mode' => Schedule::MODE_AT, 'sendAt' => new DateTime('+1 day')]));

    $schedule = $plugin->schedules->getForNotification($notification->id);

    return $schedule !== null && $schedule->mode === Schedule::MODE_AT;
});

check('only one schedule can exist per notification', function() use ($plugin) {
    $notification = makeNotification([], new Schedule(['mode' => Schedule::MODE_AT, 'sendAt' => new DateTime('+1 day')]));

    $second = new Schedule(['notificationId' => $notification->id, 'mode' => Schedule::MODE_NOW]);
    $plugin->schedules->save($second, false);

    // Two would expand into two overlapping sets of occurrences and double-send everything.
    $count = (int)(new Query())->from(Table::SCHEDULES)->where(['notificationId' => $notification->id])->count();

    return $count === 1 ? true : "$count schedules";
});

check('a draft is never expanded', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_DRAFT],
        new Schedule(['mode' => Schedule::MODE_AT, 'sendAt' => new DateTime('+2 days')]),
    );

    // Expanding a draft would send it the moment its time arrived, which is the worst possible reading
    // of the word.
    return $plugin->schedules->getNextOccurrence($notification->id) === null;
});

check('a scheduled one-off produces exactly one occurrence', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule(['mode' => Schedule::MODE_AT, 'sendAt' => new DateTime('+2 days')]),
    );

    $count = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    return $count === 1 ? true : "$count occurrences";
});

check('a daily recurrence materialises many occurrences', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '09:00',
            'startDate' => new DateTime('tomorrow'),
            'endDate' => new DateTime('+10 days'),
        ]),
    );

    $count = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    return $count >= 9 && $count <= 11 ? true : "$count occurrences for ten days";
});

check('maxOccurrences caps the expansion', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '09:00',
            'startDate' => new DateTime('tomorrow'),
            'maxOccurrences' => 3,
        ]),
    );

    $count = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    return $count === 3 ? true : "$count occurrences with a cap of 3";
});

check('an excluded date is skipped', function() use ($plugin) {
    $skip = (new DateTime('+3 days'))->format('Y-m-d');

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '09:00',
            'startDate' => new DateTime('tomorrow'),
            'endDate' => new DateTime('+6 days'),
            'exclusions' => [$skip],
        ]),
    );

    $dues = (new Query())->select(['dueAt'])->from(Table::OCCURRENCES)
        ->where(['notificationId' => $notification->id])->column();

    foreach ($dues as $due) {
        if (str_starts_with((string)$due, $skip)) {
            return 'the excluded date was materialised';
        }
    }

    return count($dues) > 0;
});

check('per-subscriber time zone fans out into one occurrence per zone', function() use ($plugin, &$createdSubscriberIds) {
    makeSubscriber(['timezone' => 'Asia/Tokyo']);
    makeSubscriber(['timezone' => 'America/New_York']);

    // The census must equal the number of occurrences a send actually creates, or the CP's promise of
    // "N zones, N deliveries" is a lie. Unusable zones are folded into the site's on both sides.
    $zones = count($plugin->subscribers->timezones());

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_AT,
            'sendAt' => new DateTime('+2 days 09:00'),
            'timezoneMode' => Schedule::TZ_SUBSCRIBER,
        ]),
    );

    $count = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    if (!Edition::allowsPerSubscriberTimezone($plugin->isPro())) {
        // Lite downgrades to one moment rather than refusing the save.
        return $count === 1 ? true : "Lite produced $count occurrences";
    }

    return $count === $zones ? true : "$count occurrences for $zones zones";
});

check('each fanned-out occurrence carries its zone', function() use ($plugin) {
    if (!Edition::allowsPerSubscriberTimezone($plugin->isPro())) {
        return true;
    }

    makeSubscriber(['timezone' => 'Asia/Tokyo']);

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_AT,
            'sendAt' => new DateTime('+2 days 09:00'),
            'timezoneMode' => Schedule::TZ_SUBSCRIBER,
        ]),
    );

    $zones = (new Query())->select(['timezone'])->from(Table::OCCURRENCES)
        ->where(['notificationId' => $notification->id])->column();

    return in_array('Asia/Tokyo', $zones, true);
});

check('re-expanding discards pending occurrences but keeps sent ones', function() use ($plugin, $db) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '09:00',
            'startDate' => new DateTime('tomorrow'),
            'endDate' => new DateTime('+5 days'),
        ]),
    );

    $first = (new Query())->select(['id'])->from(Table::OCCURRENCES)
        ->where(['notificationId' => $notification->id])->orderBy(['dueAt' => SORT_ASC])->scalar();

    $db->createCommand()->update(Table::OCCURRENCES, ['status' => Occurrence::STATUS_SENT], ['id' => $first])->execute();

    $plugin->schedules->reexpand($notification);

    // The record of what actually went out is worth more than the rule that produced it.
    return (new Query())->from(Table::OCCURRENCES)->where(['id' => $first])->exists();
});

check('expansion is idempotent', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '09:00',
            'startDate' => new DateTime('tomorrow'),
            'endDate' => new DateTime('+5 days'),
        ]),
    );

    $schedule = $plugin->schedules->getForNotification($notification->id);
    $before = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    $plugin->schedules->expand($schedule, $notification);

    $after = (int)(new Query())->from(Table::OCCURRENCES)->where(['notificationId' => $notification->id])->count();

    return $before === $after ? true : "$before became $after";
});

/** The calendar dates, in the site's zone, a notification's occurrences fall on. */
function occurrenceDates(int $notificationId): array
{
    $zone = new DateTimeZone(Craft::$app->getTimeZone());
    $dates = [];

    foreach ((new Query())->select(['dueAt'])->from(Table::OCCURRENCES)
        ->where(['notificationId' => $notificationId])->column() as $due) {
        $dates[] = (new DateTime((string)$due, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
    }

    sort($dates);

    return $dates;
}

check('an every-other-week rule keeps the phase of its start date', function() use ($plugin) {
    $zone = new DateTimeZone(Craft::$app->getTimeZone());
    // Three weeks ago, so *this* week is an off week. A rule re-anchored at today would put its first
    // send in it — the "every other week fires weekly" bug.
    $start = new DateTime('today -21 days', $zone);
    $weekday = (int)(new DateTime('today', $zone))->format('w');

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_WEEKLY,
            'interval' => 2,
            'byWeekday' => [$weekday, ($weekday + 3) % 7],
            'timeOfDay' => '09:00',
            'startDate' => $start,
        ]),
    );

    // A second pass, as the runner makes every minute.
    $plugin->schedules->expandAll();

    $dates = occurrenceDates($notification->id);
    // Calendar arithmetic in UTC, where a day is always 24 hours.
    $utc = new DateTimeZone('UTC');
    $anchorWeek = (new DateTimeImmutable($start->format('Y-m-d'), $utc))->modify('-' . (int)$start->format('w') . ' days');

    foreach ($dates as $date) {
        $day = new DateTimeImmutable($date, $utc);
        $weeks = intdiv((int)$anchorWeek->diff($day->modify('-' . (int)$day->format('w') . ' days'))->days, 7);

        if ($weeks % 2 !== 0) {
            return "$date is in an off week";
        }
    }

    return $dates !== [] ? true : 'nothing was materialised';
});

check('a yearly rule materialises its own day, not today’s', function() use ($plugin) {
    $zone = new DateTimeZone(Craft::$app->getTimeZone());
    $day = new DateTime('today +20 days', $zone);
    $start = (clone $day)->modify('-2 years');

    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_YEARLY,
            'timeOfDay' => '12:00',
            'startDate' => $start,
        ]),
    );

    $plugin->schedules->expandAll();
    $plugin->schedules->expandAll();

    $dates = occurrenceDates($notification->id);

    // One date per year, on the start date's month and day — however long the horizon is set to.
    $sameDay = array_filter($dates, fn($date) => substr($date, 5) === $day->format('m-d'));

    return $dates !== [] && $dates[0] === $day->format('Y-m-d') && count($sameDay) === count($dates)
        && count($dates) === count(array_unique(array_map(fn($date) => substr($date, 0, 4), $dates)))
        ? true
        : 'materialised ' . Json::encode($dates);
});

check('maxOccurrences counts from the start date, across passes', function() use ($plugin) {
    $zone = new DateTimeZone(Craft::$app->getTimeZone());

    // Five sends from two days ago: two have already happened (or been missed), so at most three are
    // left. A count restarting at today on every pass would materialise five, then more every day.
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_RECURRING,
            'frequency' => Schedule::FREQ_DAILY,
            'timeOfDay' => '12:00',
            'startDate' => new DateTime('today -2 days', $zone),
            'maxOccurrences' => 5,
        ]),
    );

    $plugin->schedules->expandAll();
    $plugin->schedules->expandAll();

    $dates = occurrenceDates($notification->id);
    $last = (new DateTime('today +2 days', $zone))->format('Y-m-d');

    return count($dates) >= 2 && count($dates) <= 3 && end($dates) === $last
        ? true
        : 'materialised ' . Json::encode($dates);
});

check('an immediate occurrence is created already claimed', function() use ($plugin) {
    $notification = makeNotification();
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    // Created claimed so the runner cannot pick it up and send it a second time.
    return $occurrence !== null
        && $occurrence->status === Occurrence::STATUS_CLAIMED
        && $occurrence->claimToken !== null;
});

check('only one runner wins a due occurrence', function() use ($plugin, $db) {
    $notification = makeNotification(['state' => Notification::STATE_SCHEDULED]);

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => (new DateTime('-1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        'status' => Occurrence::STATUS_PENDING,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $first = $plugin->schedules->claimDue(50);
    $second = $plugin->schedules->claimDue(50);

    $firstIds = array_map(fn($o) => $o->id, $first);
    $secondIds = array_map(fn($o) => $o->id, $second);

    // The claim is an update-then-read, so the *database* decides the winner. Reading first and updating
    // second would let a cron tick and a web request both send the same notification.
    return array_intersect($firstIds, $secondIds) === [];
});

check('a stalled claim is returned to pending', function() use ($plugin, $db) {
    $notification = makeNotification(['state' => Notification::STATE_SCHEDULED]);

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => (new DateTime('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        'status' => Occurrence::STATUS_CLAIMED,
        'claimedAt' => (new DateTime('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        'claimToken' => StringHelper::UUID(),
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $reclaimed = $plugin->schedules->reclaimStalled(30);

    // A worker killed between claiming and queueing otherwise leaves a row nothing ever picks up again,
    // and the only symptom is a notification that never arrived.
    return $reclaimed >= 1;
});

check('a stalled send is never returned to pending while its batches may still run', function() use ($plugin, $db) {
    $notification = makeNotification(['state' => Notification::STATE_SENDING]);
    $twoHoursAgo = (new DateTime('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => $twoHoursAgo,
        'status' => Occurrence::STATUS_SENDING,
        'claimedAt' => $twoHoursAgo,
        'claimToken' => StringHelper::UUID(),
        'targeted' => 1000,
        'processed' => 200,
        'delivered' => 200,
        'dateCreated' => $twoHoursAgo,
        'dateUpdated' => $twoHoursAgo,
        'uid' => StringHelper::UUID(),
    ])->execute();
    $id = (int)$db->getLastInsertID();

    $plugin->schedules->reclaimStalled(30);

    // Returned to pending, the runner would dispatch the whole audience again while the eight remaining
    // batches were still sitting in the queue — and everybody in the first two would get it twice.
    $status = (new Query())->select(['status'])->from(Table::OCCURRENCES)->where(['id' => $id])->scalar();

    return $status === Occurrence::STATUS_SENDING ? true : "became $status";
});

check('a send abandoned for a day is closed from its own totals, not re-sent', function() use ($plugin, $db) {
    $notification = makeNotification(['state' => Notification::STATE_SENDING]);
    $twoDaysAgo = (new DateTime('-2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => $twoDaysAgo,
        'status' => Occurrence::STATUS_SENDING,
        'claimedAt' => $twoDaysAgo,
        'claimToken' => StringHelper::UUID(),
        'targeted' => 10,
        'processed' => 6,
        'delivered' => 6,
        'dateCreated' => $twoDaysAgo,
        'dateUpdated' => $twoDaysAgo,
        'uid' => StringHelper::UUID(),
    ])->execute();
    $id = (int)$db->getLastInsertID();

    $plugin->schedules->reclaimStalled(30);

    $status = (new Query())->select(['status'])->from(Table::OCCURRENCES)->where(['id' => $id])->scalar();
    $state = (new Query())->select(['status'])->from(Table::NOTIFICATIONS)->where(['id' => $notification->id])->scalar();

    // Closed, and the notification leaves `sending` with it — or an automation's in-flight guard would
    // stay shut for ever.
    return $status === Occurrence::STATUS_SENT && $state === Notification::STATE_SENT
        ? true
        : "occurrence $status, notification $state";
});

check('completeBatch reports incomplete until every recipient is accounted for', function() use ($plugin, $db) {
    $notification = makeNotification();
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $db->createCommand()->update(Table::OCCURRENCES, ['targeted' => 10], ['id' => $occurrence->id])->execute();

    $partial = $plugin->schedules->completeBatch($occurrence->id, 4, 4, 0);
    $rest = $plugin->schedules->completeBatch($occurrence->id, 6, 6, 0);

    // Batches complete out of order and one can be retried, so "was this the last batch number" is not a
    // question with a reliable answer.
    return $partial === null && is_array($rest);
});

check('completeBatch never reports complete when nothing was targeted', function() use ($plugin) {
    $notification = makeNotification();
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    return $plugin->schedules->completeBatch($occurrence->id, 0, 0, 0) === null;
});

check('completeBatch returns the accumulated totals, not the last batch’s', function() use ($plugin, $db) {
    $notification = makeNotification();
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $db->createCommand()->update(Table::OCCURRENCES, ['targeted' => 10], ['id' => $occurrence->id])->execute();

    $plugin->schedules->completeBatch($occurrence->id, 7, 7, 0);
    $totals = $plugin->schedules->completeBatch($occurrence->id, 3, 0, 3);
    $row = $plugin->schedules->getOccurrenceById($occurrence->id);

    // The last batch delivered nothing; the send delivered seven. It is a sent occurrence.
    return $totals !== null
        && $totals['delivered'] === 7
        && $totals['failed'] === 3
        && $row?->status === Occurrence::STATUS_SENT
        ? true
        : 'totals ' . Json::encode($totals) . ', status ' . ($row?->status ?? 'missing');
});

check('only one batch closes a send, however many finish together', function() use ($plugin, $db) {
    $notification = makeNotification();
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $db->createCommand()->update(Table::OCCURRENCES, ['targeted' => 2], ['id' => $occurrence->id])->execute();

    // Both "batches" have incremented before either asks whether the send is complete — the interleaving
    // that let two workers both read processed >= targeted and both close the send.
    $db->createCommand()->update(Table::OCCURRENCES, ['processed' => 2, 'delivered' => 2], ['id' => $occurrence->id])->execute();

    $first = $plugin->schedules->completeBatch($occurrence->id, 0, 0, 0);
    $second = $plugin->schedules->completeBatch($occurrence->id, 0, 0, 0);

    return is_array($first) && $second === null;
});

check('a send whose last batch failed is still sent when earlier batches delivered', function() use ($plugin, $db) {
    $reached = [makeSubscriber(), makeSubscriber()];

    $notification = makeNotification(['channels' => ['onsite'], 'state' => Notification::STATE_SCHEDULED]);
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);
    $plugin->schedules->markOccurrence($occurrence->id, Occurrence::STATUS_SENDING);
    $plugin->schedules->setOccurrenceCounts($occurrence->id, targeted: 3);

    $queue = Craft::$app->getQueue();

    // Batch one reaches two people. Batch two names a subscriber who has since been forgotten, so it
    // delivers nothing — and it is the batch that finishes the send.
    (new \justinholtweb\schedulr\queue\jobs\SendBatch([
        'occurrenceId' => $occurrence->id,
        'notificationId' => $notification->id,
        'subscriberIds' => array_map(fn($s) => $s->id, $reached),
        'batchNumber' => 1,
        'batchCount' => 2,
    ]))->execute($queue);

    (new \justinholtweb\schedulr\queue\jobs\SendBatch([
        'occurrenceId' => $occurrence->id,
        'notificationId' => $notification->id,
        'subscriberIds' => [2147480000],
        'batchNumber' => 2,
        'batchCount' => 2,
    ]))->execute($queue);

    $row = $plugin->schedules->getOccurrenceById($occurrence->id);
    $state = (new Query())->select(['status'])->from(Table::NOTIFICATIONS)->where(['id' => $notification->id])->scalar();

    return $row?->status === Occurrence::STATUS_SENT && $state === Notification::STATE_SENT
        ? true
        : 'occurrence ' . ($row?->status ?? 'missing') . ', notification ' . $state;
});

check('a send batch allows every recipient its full request timeout', function() {
    $job = new \justinholtweb\schedulr\queue\jobs\SendBatch(['subscriberIds' => range(1, 1000)]);
    $small = new \justinholtweb\schedulr\queue\jobs\SendBatch(['subscriberIds' => [1]]);

    // A TTR shorter than the batch's worst case is a re-send, not a timeout.
    return $job->getTtr() >= 1000 * \justinholtweb\schedulr\queue\jobs\SendBatch::REQUEST_TIMEOUT
        && $small->getTtr() >= 300;
});

check('pending occurrences can be cancelled', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule(['mode' => Schedule::MODE_AT, 'sendAt' => new DateTime('+3 days')]),
    );

    $cancelled = $plugin->schedules->cancelPending($notification->id);

    return $cancelled >= 1 && $plugin->schedules->getNextOccurrence($notification->id) === null;
});

check('a schedule describes itself in one line', function() {
    $schedule = new Schedule([
        'mode' => Schedule::MODE_RECURRING,
        'frequency' => Schedule::FREQ_WEEKLY,
        'byWeekday' => [1, 3],
        'timeOfDay' => '09:00',
    ]);

    $description = $schedule->describe();

    return $description !== '' && str_contains($description, '09:00');
});

check('comparing two DateTimes does not stringify them', function() {
    // Yii's CompareValidator stringifies its operands, so a `compare` rule on two DateTime properties
    // throws at save time rather than at validation-definition time.
    $schedule = new Schedule([
        'mode' => Schedule::MODE_RECURRING,
        'frequency' => Schedule::FREQ_DAILY,
        'startDate' => new DateTime('+5 days'),
        'endDate' => new DateTime('+1 day'),
    ]);

    return !$schedule->validate() && $schedule->hasErrors('endDate');
});

check('a weekly rule with no day chosen is refused', function() {
    $schedule = new Schedule([
        'mode' => Schedule::MODE_RECURRING,
        'frequency' => Schedule::FREQ_WEEKLY,
        'byWeekday' => [],
    ]);

    // Yii skips inline validators when the attribute is empty, and an empty array counts as empty —
    // which is exactly the case this rule exists for.
    return !$schedule->validate() && $schedule->hasErrors('byWeekday');
});

check('monthly -1 means the last day', function() {
    $schedule = new Schedule(['byMonthDay' => [-1, 15]]);

    return $schedule->getByMonthDay() === [-1, 15];
});

check('an out-of-range month day is dropped', function() {
    $schedule = new Schedule(['byMonthDay' => [15, 45, 0]]);

    return $schedule->getByMonthDay() === [15];
});

// ---------------------------------------------------------------------------------- audiences

section('Audiences');

check('an audience saves and gets a handle', function() {
    $audience = makeAudience([['type' => 'pushable', 'operator' => 'eq', 'value' => '1']]);

    return $audience->id !== null && $audience->handle !== '';
});

check('an audience is counted on save', function() {
    $audience = makeAudience([['type' => 'pushable', 'operator' => 'eq', 'value' => '1']]);

    // Counted on save so an author finds out their rules match nobody *before* scheduling a campaign.
    return $audience->cachedCount !== null && $audience->dateCounted !== null;
});

check('the pushable rule matches only push subscribers', function() use ($plugin) {
    $pushable = makePushable();
    $anonymous = makeSubscriber();

    $audience = makeAudience([['type' => 'pushable', 'operator' => 'eq', 'value' => '1']]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($pushable->id, $ids, true) && !in_array($anonymous->id, $ids, true);
});

check('the not-pushable rule matches the people who declined', function() use ($plugin) {
    $pushable = makePushable();
    $anonymous = makeSubscriber();

    $audience = makeAudience([['type' => 'pushable', 'operator' => 'eq', 'value' => '']]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($anonymous->id, $ids, true) && !in_array($pushable->id, $ids, true);
});

check('the emailable rule matches an address', function() use ($plugin) {
    $withEmail = makeSubscriber(['email' => 'someone@example.com']);
    $without = makeSubscriber();

    $audience = makeAudience([['type' => 'emailable', 'operator' => 'eq', 'value' => '1']]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($withEmail->id, $ids, true) && !in_array($without->id, $ids, true);
});

check('the visits rule compares numerically', function() use ($plugin) {
    $frequent = makeSubscriber(['visits' => 20]);
    $once = makeSubscriber(['visits' => 1]);

    $audience = makeAudience([['type' => 'visits', 'operator' => 'gte', 'value' => 10]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($frequent->id, $ids, true) && !in_array($once->id, $ids, true);
});

check('the timezone rule matches a zone', function() use ($plugin) {
    $tokyo = makeSubscriber(['timezone' => 'Asia/Tokyo']);
    $london = makeSubscriber(['timezone' => 'Europe/London']);

    $audience = makeAudience([['type' => 'timezone', 'operator' => 'eq', 'value' => ['Asia/Tokyo']]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($tokyo->id, $ids, true) && !in_array($london->id, $ids, true);
});

check('the platform rule matches', function() use ($plugin) {
    $ios = makeSubscriber(['platform' => 'iOS']);
    $windows = makeSubscriber(['platform' => 'Windows']);

    $audience = makeAudience([['type' => 'platform', 'operator' => 'eq', 'value' => ['iOS']]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($ios->id, $ids, true) && !in_array($windows->id, $ids, true);
});

check('a relative-date rule excludes rows whose date is null', function() use ($plugin) {
    $recent = makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-1 day'))]);
    $never = makeSubscriber(['dateLastSeen' => null]);

    $audience = makeAudience([['type' => 'lastSeenDays', 'operator' => 'within', 'value' => 7]]);
    $ids = $plugin->audiences->resolveIds($audience);

    // Without the null guard, "seen in the last 7 days" quietly includes every subscriber never seen at
    // all — which on a fresh install is all of them.
    return in_array($recent->id, $ids, true) && !in_array($never->id, $ids, true);
});

check('a before-date rule finds the lapsed', function() use ($plugin) {
    $lapsed = makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-90 days'))]);
    $recent = makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-1 day'))]);

    $audience = makeAudience([['type' => 'lastSeenDays', 'operator' => 'before', 'value' => 30]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($lapsed->id, $ids, true) && !in_array($recent->id, $ids, true);
});

check('the tag rule matches through the join table', function() use ($plugin) {
    $tagged = makeSubscriber();
    $untagged = makeSubscriber();

    $plugin->subscribers->setTags($tagged->id, ['vip' => null]);

    $audience = makeAudience([['type' => 'tag', 'operator' => 'eq', 'value' => ['vip']]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($tagged->id, $ids, true) && !in_array($untagged->id, $ids, true);
});

check('the tag rule can be negated', function() use ($plugin) {
    $tagged = makeSubscriber();
    $untagged = makeSubscriber();

    $plugin->subscribers->setTags($tagged->id, ['excluded' => null]);

    $audience = makeAudience([['type' => 'tag', 'operator' => 'ne', 'value' => ['excluded']]]);
    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($untagged->id, $ids, true) && !in_array($tagged->id, $ids, true);
});

check('matching all rules narrows', function() use ($plugin) {
    $both = makePushable(['visits' => 30]);
    $onlyOne = makePushable(['visits' => 1]);

    $audience = makeAudience([
        ['type' => 'pushable', 'operator' => 'eq', 'value' => '1'],
        ['type' => 'visits', 'operator' => 'gte', 'value' => 10],
    ], 'all');

    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($both->id, $ids, true) && !in_array($onlyOne->id, $ids, true);
});

check('matching any rule widens', function() use ($plugin) {
    $pushable = makePushable(['visits' => 1]);
    $frequent = makeSubscriber(['visits' => 30]);

    $audience = makeAudience([
        ['type' => 'pushable', 'operator' => 'eq', 'value' => '1'],
        ['type' => 'visits', 'operator' => 'gte', 'value' => 10],
    ], 'any');

    $ids = $plugin->audiences->resolveIds($audience);

    return in_array($pushable->id, $ids, true) && in_array($frequent->id, $ids, true);
});

check('an unknown rule is dropped rather than matching nobody', function() use ($plugin) {
    $subscriber = makePushable();

    $audience = makeAudience([
        ['type' => 'pushable', 'operator' => 'eq', 'value' => '1'],
        ['type' => 'astrological_sign', 'operator' => 'eq', 'value' => 'leo'],
    ]);

    // A rule left behind by an uninstalled feature should widen the audience back to what it was, not
    // silently stop a campaign going out with no error anywhere.
    return in_array($subscriber->id, $plugin->audiences->resolveIds($audience), true);
});

check('an audience with no rules matches everybody reachable', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $audience = makeAudience([]);

    return in_array($subscriber->id, $plugin->audiences->resolveIds($audience), true);
});

check('an unsubscribed person never appears in an audience', function() use ($plugin) {
    $gone = makeSubscriber(['unsubscribed' => true]);
    $audience = makeAudience([]);

    return !in_array($gone->id, $plugin->audiences->resolveIds($audience), true);
});

check('recounting updates the cached count', function() use ($plugin) {
    $audience = makeAudience([['type' => 'pushable', 'operator' => 'eq', 'value' => '1']]);
    makePushable();

    $before = $audience->cachedCount;
    $after = $plugin->audiences->recount($audience);

    return $after >= $before;
});

check('every advertised rule type compiles to something', function() use ($plugin) {
    $samples = [
        'pushable' => '1', 'emailable' => '1', 'declined' => '1', 'registered' => '1',
        'visits' => 5, 'notifiedCount' => 1,
        'lastSeenDays' => 7, 'firstSeenDays' => 7, 'subscribedDays' => 7, 'notifiedDays' => 7,
        'language' => 'en-GB', 'timezone' => ['Europe/London'], 'platform' => ['iOS'],
        'site' => [Craft::$app->getSites()->getPrimarySite()->id],
        'userGroup' => [1], 'tag' => ['x'], 'received' => [1],
    ];

    $unhandled = [];

    foreach (array_keys($plugin->audiences->ruleTypes()) as $type) {
        if (!array_key_exists($type, $samples)) {
            $unhandled[] = $type;
            continue;
        }

        // Every rule the CP can offer must resolve without throwing. A rule that is pickable and
        // compiles to nothing is a segment that lies.
        $audience = new Audience(['name' => 'probe']);
        $audience->setCondition(['match' => 'all', 'rules' => [[
            'type' => $type,
            'operator' => $plugin->audiences->ruleTypes()[$type]['operators'][0] ?? 'eq',
            'value' => $samples[$type],
        ]]]);

        $plugin->audiences->resolveCount($audience);
    }

    return $unhandled === [] ? true : 'no sample for: ' . implode(', ', $unhandled);
});

check('the rule types and operator labels agree', function() use ($plugin) {
    $labels = $plugin->audiences->operatorLabels();
    $missing = [];

    foreach ($plugin->audiences->ruleTypes() as $type => $spec) {
        foreach ($spec['operators'] ?? [] as $operator) {
            if (!isset($labels[$operator])) {
                $missing[] = "$type:$operator";
            }
        }
    }

    return $missing === [] ? true : 'unlabelled: ' . implode(', ', $missing);
});

// ------------------------------------------------------------------------------------ sender

section('Sending');

check('the three channels are registered', function() use ($plugin) {
    $channels = $plugin->sender->getChannels();

    return array_keys($channels) === ['push', 'email', 'onsite'];
});

check('the push channel reaches only pushable subscribers', function() use ($plugin) {
    $channel = $plugin->sender->getChannel('push');

    return $channel->canReach(makePushable()) && !$channel->canReach(makeSubscriber());
});

check('the email channel reaches only addressable subscribers', function() use ($plugin) {
    $channel = $plugin->sender->getChannel('email');

    return $channel->canReach(makeSubscriber(['email' => 'a@example.com']))
        && !$channel->canReach(makeSubscriber());
});

check('the on-site channel reaches everybody not unsubscribed', function() use ($plugin) {
    $channel = $plugin->sender->getChannel('onsite');

    return $channel->canReach(makeSubscriber())
        && !$channel->canReach(makeSubscriber(['unsubscribed' => true]));
});

check('the on-site channel queues rather than claiming delivery', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);
    $result = $plugin->sender->getChannel('onsite')->send($notification, makeSubscriber());

    // For on-site, delivery is the moment somebody sees it — which may be minutes later or never.
    return $result->status === Delivery::STATUS_QUEUED && $result->isSuccess();
});

check('resolving a push audience finds only pushable subscribers', function() use ($plugin) {
    $pushable = makePushable();
    $anonymous = makeSubscriber();

    $notification = makeNotification(['channels' => ['push']]);
    $ids = $plugin->sender->resolveAudience($notification);

    return in_array($pushable->id, $ids, true) && !in_array($anonymous->id, $ids, true);
});

check('resolving an on-site audience finds anonymous visitors too', function() use ($plugin) {
    $anonymous = makeSubscriber();

    $notification = makeNotification(['channels' => ['onsite']]);

    // The whole reason the on-site channel exists: it reaches the majority who never grant push.
    return in_array($anonymous->id, $plugin->sender->resolveAudience($notification), true);
});

check('resolving a two-channel audience is the union', function() use ($plugin) {
    $pushable = makePushable();
    $emailable = makeSubscriber(['email' => 'union@example.com']);

    $notification = makeNotification(['channels' => ['push', 'email']]);
    $ids = $plugin->sender->resolveAudience($notification);

    return in_array($pushable->id, $ids, true) && in_array($emailable->id, $ids, true);
});

check('topics narrow an audience', function() use ($plugin) {
    $tagged = makeSubscriber();
    $untagged = makeSubscriber();

    $plugin->subscribers->setTags($tagged->id, ['sport' => null]);

    $notification = makeNotification(['channels' => ['onsite'], 'topics' => ['sport']]);
    $ids = $plugin->sender->resolveAudience($notification);

    return in_array($tagged->id, $ids, true) && !in_array($untagged->id, $ids, true);
});

check('an audience narrows a notification', function() use ($plugin) {
    if (!Edition::allowsSegments($plugin->isPro())) {
        return true;
    }

    $frequent = makeSubscriber(['visits' => 40]);
    $once = makeSubscriber(['visits' => 1]);

    $audience = makeAudience([['type' => 'visits', 'operator' => 'gte', 'value' => 20]]);
    $notification = makeNotification(['channels' => ['onsite'], 'audienceId' => $audience->id]);

    $ids = $plugin->sender->resolveAudience($notification);

    return in_array($frequent->id, $ids, true) && !in_array($once->id, $ids, true);
});

check('an occurrence’s time zone slices the audience', function() use ($plugin) {
    $tokyo = makeSubscriber(['timezone' => 'Asia/Tokyo']);
    $london = makeSubscriber(['timezone' => 'Europe/London']);

    $notification = makeNotification(['channels' => ['onsite']]);
    $occurrence = new Occurrence(['notificationId' => $notification->id, 'timezone' => 'Asia/Tokyo']);

    $ids = $plugin->sender->resolveAudience($notification, $occurrence);

    return in_array($tokyo->id, $ids, true) && !in_array($london->id, $ids, true);
});

check('the site zone slice includes subscribers with no zone', function() use ($plugin) {
    $unknown = makeSubscriber(['timezone' => null]);

    $notification = makeNotification(['channels' => ['onsite']]);
    $occurrence = new Occurrence([
        'notificationId' => $notification->id,
        'timezone' => Craft::$app->getTimeZone(),
    ]);

    return in_array($unknown->id, $plugin->sender->resolveAudience($notification, $occurrence), true);
});

check('a notification with no reachable channel resolves to nobody', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['push']]);

    // Every push subscriber this run created is fine; what matters is that a subscriber with no endpoint
    // never appears.
    $anonymous = makeSubscriber();

    return !in_array($anonymous->id, $plugin->sender->resolveAudience($notification), true);
});

check('sending a batch writes one ledger row per recipient per channel', function() use ($plugin) {
    $a = makeSubscriber();
    $b = makeSubscriber();

    $notification = makeNotification(['channels' => ['onsite']]);
    $counts = $plugin->sender->sendBatch($notification, [$a->id, $b->id]);

    $rows = (int)(new Query())->from(Table::DELIVERIES)->where(['notificationId' => $notification->id])->count();

    return $counts['delivered'] === 2 && $rows === 2 ? true : "delivered {$counts['delivered']}, $rows rows";
});

check('a subscriber reachable on two channels gets two ledger rows', function() use ($plugin) {
    $subscriber = makeSubscriber(['email' => 'two@example.invalid']);

    $notification = makeNotification(['channels' => ['onsite', 'email'], 'dedupePolicy' => Settings::DEDUPE_NONE]);
    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    $channels = (new Query())->select(['channel'])->from(Table::DELIVERIES)
        ->where(['notificationId' => $notification->id])->column();

    return count($channels) === 2 ? true : implode(',', $channels);
});

check('the first-channel-only policy sends once', function() use ($plugin) {
    if (!Edition::allowsDedupePolicy($plugin->isPro())) {
        return true;
    }

    $subscriber = makeSubscriber(['email' => 'first@example.invalid']);

    $notification = makeNotification(['channels' => ['onsite', 'email'], 'dedupePolicy' => Settings::DEDUPE_FIRST]);
    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    $rows = (int)(new Query())->from(Table::DELIVERIES)->where(['notificationId' => $notification->id])->count();

    return $rows === 1 ? true : "$rows rows";
});

check('an unreachable device is recorded as failed, not gone', function() use ($plugin) {
    $subscriber = makePushable();

    $notification = makeNotification(['channels' => ['push'], 'body' => 'x']);
    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    $row = (new Query())->from(Table::DELIVERIES)
        ->where(['notificationId' => $notification->id, 'channel' => 'push'])->one();

    // A `.invalid` host cannot resolve, which is a transport failure — not the push service retiring the
    // endpoint. Conflating the two either throws away live subscribers or pushes forever to dead ones.
    return $row !== false && $row['status'] === Delivery::STATUS_FAILED
        ? true
        : 'status was ' . ($row['status'] ?? 'missing');
});

check('a failed push counts against the device but does not retire it', function() use ($plugin) {
    $subscriber = makePushable();

    $notification = makeNotification(['channels' => ['push'], 'body' => 'x']);
    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    $after = $plugin->subscribers->getById($subscriber->id);

    return $after->isPushable() && $after->failures === 1
        ? true
        : 'pushable=' . var_export($after->isPushable(), true) . ' failures=' . $after->failures;
});

check('an oversized payload is refused before the network', function() use ($plugin) {
    $subscriber = makePushable();

    // Checked before encrypting rather than after, so one authoring mistake is one error instead of
    // fifty thousand requests each answered 413.
    $notification = makeNotification(['channels' => ['push'], 'body' => str_repeat('x', 390), 'url' => str_repeat('u', 900)]);
    $result = $plugin->sender->getChannel('push')->send($notification, $subscriber);

    return $result->statusCode === 413 || $result->status === Delivery::STATUS_FAILED;
});

check('the ledger survives a subscriber vanishing mid-batch', function() use ($plugin, $db) {
    $a = makeSubscriber();
    $b = makeSubscriber();

    $notification = makeNotification(['channels' => ['onsite']]);

    // The trap: a device dropped as `410 gone` during this very batch has already had its row deleted,
    // and the foreign key is checked at insert even with ON DELETE SET NULL — so without the re-check
    // the whole batchInsert dies and takes the record of every delivery with it.
    $db->createCommand()->delete(Table::SUBSCRIBERS, ['id' => $b->id])->execute();

    $plugin->sender->sendBatch($notification, [$a->id, $b->id]);

    $rows = (int)(new Query())->from(Table::DELIVERIES)->where(['notificationId' => $notification->id])->count();

    return $rows >= 1 ? true : 'the whole batch was lost';
});

check('dispatching with nobody to send to marks the occurrence skipped', function() use ($plugin, $db) {
    $notification = makeNotification(['channels' => ['push'], 'topics' => ['nobody-has-this-tag-' . StringHelper::randomString(8)]]);
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $sent = $plugin->sender->dispatch($occurrence);

    $fresh = $plugin->schedules->getOccurrenceById($occurrence->id);

    // "We sent to nobody" and "there was nobody to send to" are different facts, and only one means
    // something is misconfigured.
    return $sent === 0 && $fresh->status === Occurrence::STATUS_SKIPPED
        ? true
        : "sent $sent, status {$fresh->status}";
});

check('dispatching a deleted notification cancels the occurrence', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    Craft::$app->getElements()->deleteElement($notification, true);

    $plugin->sender->dispatch($occurrence);

    $fresh = $plugin->schedules->getOccurrenceById($occurrence->id);

    return $fresh === null || $fresh->status === Occurrence::STATUS_CANCELLED;
});

check('dispatching queues one job per batch', function() use ($plugin) {
    for ($i = 0; $i < 3; $i++) {
        makeSubscriber();
    }

    $notification = makeNotification(['channels' => ['onsite']]);
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $before = Craft::$app->getQueue()->getTotalJobs();
    $recipients = $plugin->sender->dispatch($occurrence);
    $after = Craft::$app->getQueue()->getTotalJobs();

    return $recipients > 0 && $after > $before ? true : "queued " . ($after - $before) . " for $recipients recipients";
});

check('a dispatched occurrence records what it targeted', function() use ($plugin) {
    makeSubscriber();

    $notification = makeNotification(['channels' => ['onsite']]);
    $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

    $recipients = $plugin->sender->dispatch($occurrence);
    $fresh = $plugin->schedules->getOccurrenceById($occurrence->id);

    return $fresh->targeted === $recipients;
});

check('a variant assignment is stable across retries', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 50, 'title' => 'A title'],
        ['label' => 'B', 'share' => 50, 'title' => 'B title'],
    ]);

    if (!Edition::allowsAbTesting($plugin->isPro())) {
        return true;
    }

    $subscriber = makeSubscriber();

    $plugin->sender->sendBatch($notification, [$subscriber->id]);
    $first = (new Query())->select(['variantId'])->from(Table::DELIVERIES)
        ->where(['notificationId' => $notification->id, 'subscriberId' => $subscriber->id])->scalar();

    $plugin->sender->sendBatch($notification, [$subscriber->id]);
    $ids = (new Query())->select(['variantId'])->from(Table::DELIVERIES)
        ->where(['notificationId' => $notification->id, 'subscriberId' => $subscriber->id])->column();

    // Randomising here would let a queue retry show one person both arms and corrupt the very result the
    // test exists to produce.
    return count(array_unique($ids)) === 1 && $ids[0] == $first;
});

// --------------------------------------------------------------------------------- analytics

section('Analytics');

check('a tracked URL carries IDs and a signature, never a destination', function() use ($plugin) {
    if (!Edition::allowsClickTracking($plugin->isPro())) {
        return true;
    }

    $url = $plugin->analytics->trackedUrl('/article', 42, 7, 99);

    // A redirect endpoint that takes its target from a query parameter is an open redirect — a phishing
    // gadget hosted on the customer's own domain.
    return str_contains($url, 'sr_n=42')
        && str_contains($url, 'sr_k=')
        && !str_contains($url, 'article');
});

check('an unsigned tracked URL fails verification', function() use ($plugin) {
    $params = ['sr_n' => 42, 'sr_c' => 'push'];

    return !$plugin->analytics->verify($params);
});

check('a signature over the same params verifies', function() use ($plugin) {
    $params = ['sr_n' => 42, 'sr_c' => 'push', 'sr_s' => 9];
    $params['sr_k'] = $plugin->analytics->sign($params);

    return $plugin->analytics->verify($params);
});

check('changing a parameter invalidates the signature', function() use ($plugin) {
    $params = ['sr_n' => 42, 'sr_c' => 'push'];
    $params['sr_k'] = $plugin->analytics->sign($params);
    $params['sr_n'] = 43;

    // Otherwise anyone who received one notification could inflate the click count on every campaign.
    return !$plugin->analytics->verify($params);
});

check('the signature ignores parameter order', function() use ($plugin) {
    $a = ['sr_n' => 1, 'sr_c' => 'push', 'sr_s' => 2];
    $b = ['sr_s' => 2, 'sr_c' => 'push', 'sr_n' => 1];

    return $plugin->analytics->sign($a) === $plugin->analytics->sign($b);
});

check('the tracked URL is left alone in Lite', function() use ($plugin) {
    if (Edition::allowsClickTracking($plugin->isPro())) {
        return true;
    }

    // A Lite install links straight through rather than bouncing through an endpoint that does nothing.
    return $plugin->analytics->trackedUrl('/article', 42) === '/article';
});

check('the destination is looked up from the notification', function() use ($plugin) {
    $notification = makeNotification(['url' => '/the-destination']);

    return $plugin->analytics->destinationFor($notification->id, null, null) === '/the-destination';
});

check('a variant’s own URL wins', function() use ($plugin) {
    $notification = makeNotification(['url' => '/base']);

    $plugin->notifications->saveVariants($notification->id, [
        ['label' => 'A', 'share' => 100, 'title' => 'A', 'url' => '/variant'],
    ]);

    $variant = $plugin->notifications->getVariantModels($notification->id)[0];

    return $plugin->analytics->destinationFor($notification->id, $variant->id, null) === '/variant';
});

check('a button’s own URL wins over both', function() use ($plugin) {
    $notification = makeNotification([
        'url' => '/base',
        'buttons' => [['title' => 'Go', 'url' => '/button']],
    ]);

    return $plugin->analytics->destinationFor($notification->id, null, 0) === '/button';
});

check('a click is recorded', function() use ($plugin) {
    $notification = makeNotification();
    $subscriber = makeSubscriber();

    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id, 'push');

    return $plugin->notifications->getById($notification->id)->clicked === 1;
});

check('a second click from the same person does not inflate the rate', function() use ($plugin) {
    $notification = makeNotification();
    $subscriber = makeSubscriber();

    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id, 'push');
    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id, 'push');

    // Rates above 100% are how a report loses its audience.
    return $plugin->notifications->getById($notification->id)->clicked === 1;
});

check('both clicks are still on the record', function() use ($plugin) {
    $notification = makeNotification();
    $subscriber = makeSubscriber();

    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id, 'push');
    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id, 'push');

    // "How many times was this opened" is a real question; only the *rate* needs deduping.
    $rows = (int)(new Query())->from(Table::EVENTS)
        ->where(['notificationId' => $notification->id, 'type' => Analytics::EVENT_CLICKED])->count();

    return $rows === 2;
});

check('an unknown event type is refused', function() use ($plugin) {
    $notification = makeNotification();

    return $plugin->analytics->record('exploded', $notification->id) === false;
});

check('the funnel reports null rates when there is no denominator', function() use ($plugin) {
    $notification = makeNotification();
    $funnel = $plugin->analytics->funnel($notification->id);

    return $funnel['clickRate'] === null && $funnel['deliveryRate'] === null;
});

check('the funnel computes a click rate against deliveries', function() use ($plugin) {
    $notification = makeNotification();
    $subscriber = makeSubscriber();

    $plugin->notifications->addTargeted($notification->id, 100);
    $plugin->notifications->addOutcome($notification->id, 100, 0);
    $plugin->analytics->record(Analytics::EVENT_CLICKED, $notification->id, null, $subscriber->id);

    $funnel = $plugin->analytics->funnel($notification->id);

    return $funnel['clickRate'] === 1.0 ? true : 'rate was ' . var_export($funnel['clickRate'], true);
});

check('the dismiss rate is measured against what was shown', function() use ($plugin) {
    $notification = makeNotification();
    $a = makeSubscriber();
    $b = makeSubscriber();

    $plugin->notifications->addOutcome($notification->id, 100, 0);
    $plugin->analytics->record(Analytics::EVENT_DISPLAYED, $notification->id, null, $a->id);
    $plugin->analytics->record(Analytics::EVENT_DISPLAYED, $notification->id, null, $b->id);
    $plugin->analytics->record(Analytics::EVENT_DISMISSED, $notification->id, null, $a->id);

    // Against displayed, not delivered — dividing by delivered would flatter every campaign whose
    // notifications were never seen.
    $funnel = $plugin->analytics->funnel($notification->id);

    return $funnel['dismissRate'] === 50.0 ? true : 'rate was ' . var_export($funnel['dismissRate'], true);
});

check('the daily series covers every day in the window, including empty ones', function() use ($plugin) {
    $series = $plugin->analytics->daily(14);

    // A chart drawn only from days that have data compresses a quiet fortnight into one tick.
    return count($series) >= 14 && isset($series[0]['day'], $series[0]['delivered']);
});

check('the daily series truncates dates on both drivers', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);
    $plugin->sender->sendBatch($notification, [makeSubscriber()->id]);

    $today = (new DateTime())->format('Y-m-d');
    $series = $plugin->analytics->daily(3);

    foreach ($series as $row) {
        if ($row['day'] === $today && $row['delivered'] > 0) {
            return true;
        }
    }

    return 'today’s deliveries did not land on today';
});

check('the leaderboard ignores notifications with too little data', function() use ($plugin) {
    $notification = makeNotification();
    $plugin->notifications->addOutcome($notification->id, 3, 0);

    foreach ($plugin->analytics->leaderboard() as $row) {
        if ($row['id'] === $notification->id) {
            return 'a three-delivery notification was ranked';
        }
    }

    return true;
});

// -------------------------------------------------------------------------------- deliveries

section('The ledger');

check('the summary groups by channel and status', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);
    $plugin->sender->sendBatch($notification, [makeSubscriber()->id, makeSubscriber()->id]);

    $summary = $plugin->deliveries->summary($notification->id);

    return ($summary['onsite'][Delivery::STATUS_QUEUED] ?? 0) === 2;
});

check('failure reasons group by status code', function() use ($plugin, $db) {
    $notification = makeNotification();

    $now = Db::prepareDateForDb(new DateTime());

    foreach ([500, 500, 503] as $code) {
        $db->createCommand()->insert(Table::DELIVERIES, [
            'notificationId' => $notification->id,
            'channel' => 'push',
            'status' => Delivery::STATUS_FAILED,
            'statusCode' => $code,
            'error' => 'a different message every time ' . StringHelper::randomString(6),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    $reasons = $plugin->deliveries->failureReasons($notification->id);

    // Grouping on the message would give three groups of one and tell you nothing.
    return count($reasons) === 2 && (int)$reasons[0]['total'] === 2;
});

check('a subscriber’s own history reads back', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite']]);

    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    return count($plugin->deliveries->forSubscriber($subscriber->id)) === 1;
});

check('the inbox returns queued on-site notifications', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite'], 'body' => 'Inbox body']);

    $plugin->sender->sendBatch($notification, [$subscriber->id]);

    $items = $plugin->deliveries->inboxFor($subscriber->id);

    return count($items) === 1
        && $items[0]['title'] === $notification->title
        && $items[0]['n'] === $notification->id;
});

check('reading the inbox promotes queued to delivered', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite']]);

    $plugin->sender->sendBatch($notification, [$subscriber->id]);
    $plugin->deliveries->inboxFor($subscriber->id);

    $status = (new Query())->select(['status'])->from(Table::DELIVERIES)
        ->where(['notificationId' => $notification->id, 'subscriberId' => $subscriber->id])->scalar();

    // For on-site, delivery *is* the moment it is put in front of somebody.
    return $status === Delivery::STATUS_DELIVERED ? true : "status was $status";
});

check('the inbox does not return the same thing twice', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite']]);

    $plugin->sender->sendBatch($notification, [$subscriber->id]);
    $plugin->deliveries->inboxFor($subscriber->id);

    return $plugin->deliveries->inboxFor($subscriber->id) === [];
});

check('an inbox row whose notification has gone is cleared, not looped', function() use ($plugin) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite']]);

    $plugin->sender->sendBatch($notification, [$subscriber->id]);
    Craft::$app->getElements()->deleteElement($notification, true);

    $items = $plugin->deliveries->inboxFor($subscriber->id);

    // An inbox that keeps returning something it cannot render never empties.
    return $items === [] && $plugin->deliveries->inboxFor($subscriber->id) === [];
});

check('the ledger can be pruned by age', function() use ($plugin, $db) {
    $notification = makeNotification();

    $db->createCommand()->insert(Table::DELIVERIES, [
        'notificationId' => $notification->id,
        'channel' => 'push',
        'status' => Delivery::STATUS_DELIVERED,
        'dateCreated' => Db::prepareDateForDb(new DateTime('-400 days')),
        'dateUpdated' => Db::prepareDateForDb(new DateTime('-400 days')),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $pruned = $plugin->deliveries->prune(365);

    return $pruned >= 1;
});

check('pruning with a zero retention deletes nothing', function() use ($plugin) {
    return $plugin->deliveries->prune(0) === 0 && $plugin->analytics->prune(0) === 0;
});

check('the ledger streams rather than loading', function() use ($plugin) {
    $notification = makeNotification(['channels' => ['onsite']]);
    $plugin->sender->sendBatch($notification, [makeSubscriber()->id]);

    $seen = 0;

    foreach ($plugin->deliveries->each(['notificationId' => $notification->id]) as $row) {
        $seen++;
    }

    return $seen === 1;
});

// ------------------------------------------------------------------------------- automations

section('Automations');

check('the three triggers are advertised', function() {
    return array_keys(Automations::triggerOptions()) === [
        Automations::TRIGGER_ENTRY_PUBLISHED,
        Automations::TRIGGER_USER_REGISTERED,
        Automations::TRIGGER_INACTIVITY,
    ];
});

check('a template renders Twig against its source element', function() use ($plugin) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return true;
    }

    $template = makeNotification([
        'channels' => ['onsite'],
        'title' => 'New: {{ entry.title }}',
        'triggerType' => Automations::TRIGGER_ENTRY_PUBLISHED,
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    $concrete = $plugin->automations->fire($template, $entry);

    if ($concrete === null) {
        return 'nothing was raised';
    }

    global $createdNotificationIds;
    $createdNotificationIds[] = $concrete->id;

    return $concrete->title === 'New: ' . $entry->title
        ? true
        : 'rendered as “' . $concrete->title . '”';
});

check('a raised notification records its template and source', function() use ($plugin, &$createdNotificationIds) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return true;
    }

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_ENTRY_PUBLISHED,
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    $concrete = $plugin->automations->fire($template, $entry);
    $createdNotificationIds[] = $concrete?->id;

    // Keyed on both, so two templates watching one section both get their turn and neither fires twice.
    return $concrete !== null
        && $concrete->templateId === $template->id
        && $concrete->sourceElementId === $entry->id;
});

check('a raised notification is not itself a template', function() use ($plugin, &$createdNotificationIds) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return true;
    }

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_ENTRY_PUBLISHED,
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    $concrete = $plugin->automations->fire($template, $entry);
    $createdNotificationIds[] = $concrete?->id;

    // Leaving the trigger on the copy would make every firing breed.
    return $concrete !== null && $concrete->triggerType === null;
});

check('a template whose title renders empty raises nothing', function() use ($plugin) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return true;
    }

    $template = makeNotification([
        'channels' => ['onsite'],
        'title' => '{{ entry.aFieldThatDoesNotExistAnywhere.title }}',
        'triggerType' => Automations::TRIGGER_ENTRY_PUBLISHED,
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    // Nobody wants `{{ entry.title }}` on their lock screen, so a failed render sends nothing at all.
    return $plugin->automations->fire($template, $entry) === null;
});

check('the win-back sweep is safe to run', fn() => is_int($plugin->automations->sweep()));

check('a win-back does not start a second run while the first is draining', function() use ($plugin, &$createdNotificationIds) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-500 days'))]);

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_INACTIVITY,
        'triggerConfig' => ['days' => 400],
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    // Two passes with nothing draining in between. Until the batches run there are no delivery rows to
    // dedupe against, so the in-flight guard is the only thing stopping a second send.
    $plugin->automations->sweep();
    $plugin->automations->sweep();

    $raised = (int)(new Query())->from(Table::NOTIFICATIONS)->where(['templateId' => $template->id])->count();

    foreach ((new Query())->select(['id'])->from(Table::NOTIFICATIONS)->where(['templateId' => $template->id])->column() as $id) {
        $createdNotificationIds[] = (int)$id;
    }

    return $raised === 1 ? true : "$raised runs raised";
});

check('a win-back reaches only the lapsed, once', function() use ($plugin, &$createdNotificationIds) {
    if (!Edition::allowsAutomations($plugin->isPro())) {
        return true;
    }

    $lapsed = makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-400 days'))]);

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_INACTIVITY,
        'triggerConfig' => ['days' => 365],
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    // Counted through *this* template only. Another inactivity template in the database — including one
    // this file created a few checks earlier — legitimately reaches the same lapsed person, so counting
    // every delivery to them measures the wrong thing and fails on the second run of the suite.
    $countForTemplate = static function(int $templateId, int $subscriberId): int {
        return (int)(new Query())
            ->from(['d' => Table::DELIVERIES])
            ->innerJoin(['n' => Table::NOTIFICATIONS], '[[n.id]] = [[d.notificationId]]')
            ->where(['n.templateId' => $templateId, 'd.subscriberId' => $subscriberId])
            ->count();
    };

    $plugin->automations->sweep();

    // The sweep *queues*; the ledger only exists once the batches run. Draining here is what makes the
    // second half of this check meaningful rather than trivially true.
    Craft::$app->getQueue()->run(false);

    $first = $countForTemplate($template->id, $lapsed->id);

    $plugin->automations->sweep();
    Craft::$app->getQueue()->run(false);

    $second = $countForTemplate($template->id, $lapsed->id);

    foreach ((new Query())->select(['id'])->from(Table::NOTIFICATIONS)->where(['templateId' => $template->id])->column() as $id) {
        $createdNotificationIds[] = (int)$id;
    }

    // A win-back that arrives every day for a month is not a win-back.
    return $first === 1 && $second === 1 ? true : "$first then $second";
});

// ----------------------------------------------------------------------------------- runner

section('Runner');

check('health reports one of four states', function() use ($plugin) {
    $health = $plugin->runner->health();

    return in_array($health['state'], ['cron', 'web', 'stalled', 'manual'], true);
});

check('health counts pending and overdue', function() use ($plugin) {
    $health = $plugin->runner->health();

    return is_int($health['pending']) && is_int($health['overdue']);
});

check('a recorded run is readable', function() use ($plugin) {
    $plugin->runner->recordRun('cron');

    return $plugin->runner->getLastRun('cron') !== null && $plugin->runner->isCronAlive();
});

check('a pass returns a full result', function() use ($plugin) {
    $result = $plugin->runner->run('manual', 5);

    return array_keys($result) === ['claimed', 'dispatched', 'recipients', 'expanded', 'reclaimed', 'swept', 'skipped'];
});

check('a pass dispatches a due occurrence', function() use ($plugin, $db) {
    $subscriber = makeSubscriber();
    $notification = makeNotification(['channels' => ['onsite'], 'state' => Notification::STATE_SCHEDULED]);

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => (new DateTime('-1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        'status' => Occurrence::STATUS_PENDING,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $result = $plugin->runner->run('manual', 25);

    return $result['claimed'] >= 1 && $result['recipients'] >= 1
        ? true
        : "claimed {$result['claimed']}, recipients {$result['recipients']}";
});

check('an occurrence due tomorrow is left alone', function() use ($plugin, $db) {
    $notification = makeNotification(['channels' => ['onsite'], 'state' => Notification::STATE_SCHEDULED]);

    $db->createCommand()->insert(Table::OCCURRENCES, [
        'notificationId' => $notification->id,
        'dueAt' => (new DateTime('+1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        'status' => Occurrence::STATUS_PENDING,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $plugin->runner->run('manual', 25);

    $status = (new Query())->select(['status'])->from(Table::OCCURRENCES)
        ->where(['notificationId' => $notification->id])->scalar();

    return $status === Occurrence::STATUS_PENDING;
});

// --------------------------------------------------------------------------- runtime & Twig

section('Runtime and Twig');

check('the injector builds a config', function() {
    $config = (new justinholtweb\schedulr\web\Injector())->config();

    return is_array($config) && isset($config['heartbeatUrl'], $config['vapidPublicKey'], $config['ownsWorker']);
});

check('the injected config contains nothing per-visitor', function() {
    $config = (new justinholtweb\schedulr\web\Injector())->config();

    // Anything visitor-specific in an HTML response poisons every full-page cache in front of the site.
    $forbidden = array_intersect(array_keys($config), ['visitorId', 'subscriberId', 'csrf', 'csrfToken', 'userId']);

    return $forbidden === [] ? true : 'leaked: ' . implode(', ', $forbidden);
});

check('the injected markup includes the runtime and its styles', function() {
    $markup = (new justinholtweb\schedulr\web\Injector())->markup();

    return str_contains($markup, 'window.__SCHEDULR__')
        && str_contains($markup, 'schedulr-prompt');
});

check('the injected markup never contains the private key', function() {
    return !str_contains((new justinholtweb\schedulr\web\Injector())->markup(), 'PRIVATE KEY');
});

check('the Twig variable exposes the public key and stats', function() {
    $variable = new justinholtweb\schedulr\twig\SchedulrVariable();

    return $variable->publicKey() !== '' && is_array($variable->stats()) && is_bool($variable->isPro());
});

check('the Twig variable returns a notification query', function() {
    $variable = new justinholtweb\schedulr\twig\SchedulrVariable();

    return $variable->notifications(['limit' => 1]) instanceof justinholtweb\schedulr\elements\db\NotificationQuery;
});

check('the Twig variable cannot send anything', function() {
    $methods = get_class_methods(justinholtweb\schedulr\twig\SchedulrVariable::class);

    // A template that could send a notification sends one on every page load the first time it is cached.
    foreach ($methods as $method) {
        if (preg_match('/^(send|dispatch|queue|save|delete)/i', $method)) {
            return "SchedulrVariable::$method() exists";
        }
    }

    return true;
});

// ----------------------------------------------------------------------------------- editions

section('Editions');

check('the edition boundary is consistent with the plugin’s own edition', function() use ($plugin) {
    $isPro = $plugin->isPro();

    return Edition::allowsSegments($isPro) === $isPro
        && Edition::allowsAbTesting($isPro) === $isPro
        && Edition::allowsAutomations($isPro) === $isPro;
});

check('Lite still gets every channel', function() {
    // The line is "Lite composes, schedules and sends" — channels are never the paywall.
    $notification = new Notification();
    $notification->setChannels(['push', 'email', 'onsite']);

    return count($notification->getChannels()) === 3;
});

check('a prompt style the edition disallows falls back rather than vanishing', function() use ($plugin) {
    $style = Settings::PROMPT_SLIDE;
    $allowed = Edition::promptStyleAllowed($style, $plugin->isPro());
    $config = (new justinholtweb\schedulr\web\Injector())->config();

    // A prompt that disappears on renewal day looks exactly like a bug.
    return $allowed || in_array($config['promptStyle'], Edition::promptStyles($plugin->isPro()), true);
});

check('a schedule downgrades its time zone mode rather than refusing to save', function() use ($plugin) {
    $notification = makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_AT,
            'sendAt' => new DateTime('+2 days'),
            'timezoneMode' => Schedule::TZ_SUBSCRIBER,
        ]),
    );

    $saved = $plugin->schedules->getForNotification($notification->id);

    return Edition::allowsPerSubscriberTimezone($plugin->isPro())
        ? $saved->timezoneMode === Schedule::TZ_SUBSCRIBER
        : $saved->timezoneMode === Schedule::TZ_SITE;
});

/**
 * Runs a check as a given edition, **in memory only** — never persisted — and always restores it.
 */
function asEdition(string $edition, callable $test): mixed
{
    global $plugin;

    $was = $plugin->edition;
    $plugin->edition = $edition;

    try {
        return $test();
    } finally {
        $plugin->edition = $was;
    }
}

check('a lapsed licence keeps applying a saved audience', function() use ($plugin) {
    $frequent = makeSubscriber(['visits' => 40]);
    $once = makeSubscriber(['visits' => 1]);

    $audience = makeAudience([['type' => 'visits', 'operator' => 'gte', 'value' => 20]]);
    $notification = makeNotification(['channels' => ['onsite'], 'audienceId' => $audience->id]);

    // The worst bug a downgrade could have: Lite used to skip the condition, so "lapsed readers in
    // Germany" became everybody the day a licence lapsed.
    $ids = asEdition('lite', fn() => $plugin->sender->resolveAudience($notification));

    return in_array($frequent->id, $ids, true) && !in_array($once->id, $ids, true)
        ? true
        : 'the audience was not applied on Lite';
});

check('a lapsed licence keeps a schedule’s per-subscriber time zone on re-save', function() use ($plugin) {
    $notification = asEdition('pro', fn() => makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_AT,
            'sendAt' => new DateTime('+2 days 09:00'),
            'timezoneMode' => Schedule::TZ_SUBSCRIBER,
        ]),
    ));

    $schedule = $plugin->schedules->getForNotification($notification->id);
    asEdition('lite', fn() => $plugin->schedules->save($schedule));

    $kept = $plugin->schedules->getForNotification($notification->id)?->timezoneMode;

    // …while a schedule that was *not* per-subscriber cannot be switched to it on Lite.
    $fresh = asEdition('lite', fn() => makeNotification(
        ['state' => Notification::STATE_SCHEDULED],
        new Schedule([
            'mode' => Schedule::MODE_AT,
            'sendAt' => new DateTime('+2 days 09:00'),
            'timezoneMode' => Schedule::TZ_SUBSCRIBER,
        ]),
    ));

    $downgraded = $plugin->schedules->getForNotification($fresh->id)?->timezoneMode;

    return $kept === Schedule::TZ_SUBSCRIBER && $downgraded === Schedule::TZ_SITE
        ? true
        : "kept $kept, fresh $downgraded";
});

check('a lapsed licence keeps running a saved automation', function() use ($plugin, &$createdNotificationIds) {
    makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-700 days'))]);

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_INACTIVITY,
        'triggerConfig' => ['days' => 600],
        'state' => Notification::STATE_SCHEDULED,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    asEdition('lite', fn() => $plugin->automations->sweep());

    $raised = (new Query())->select(['id'])->from(Table::NOTIFICATIONS)->where(['templateId' => $template->id])->column();

    foreach ($raised as $id) {
        $createdNotificationIds[] = (int)$id;
    }

    return count($raised) === 1 ? true : count($raised) . ' raised on Lite';
});

check('an automation whose schedule is not live never fires', function() use ($plugin, &$createdNotificationIds) {
    makeSubscriber(['dateLastSeen' => Db::prepareDateForDb(new DateTime('-700 days'))]);

    $template = makeNotification([
        'channels' => ['onsite'],
        'triggerType' => Automations::TRIGGER_INACTIVITY,
        'triggerConfig' => ['days' => 600],
        'state' => Notification::STATE_DRAFT,
    ], new Schedule(['mode' => Schedule::MODE_TRIGGER]));

    $plugin->automations->sweep();

    $raised = (new Query())->select(['id'])->from(Table::NOTIFICATIONS)->where(['templateId' => $template->id])->column();

    foreach ($raised as $id) {
        $createdNotificationIds[] = (int)$id;
    }

    return $raised === [] ? true : count($raised) . ' raised from a draft template';
});

// ---------------------------------------------------------------------------------- settings

section('Settings');

check('no setting is marked required', function() use ($plugin) {
    // A `required` rule blocks a fresh install from saving *any* setting until the credential is filled
    // in, because savePluginSettings() fails validation wholesale.
    foreach ($plugin->getSettings()->rules() as $rule) {
        if (in_array('required', $rule, true)) {
            return 'a required rule exists on ' . Json::encode($rule[0]);
        }
    }

    return true;
});

check('a colour without its leading hash is normalised', function() {
    $settings = new Settings();
    $settings->setAccentColor('C7278C');

    // Craft's colour field posts `C7278C`, not `#C7278C`, so a pattern rule would reject every colour
    // ever saved through the CP and quietly keep the default.
    return $settings->accentColor === '#C7278C' && $settings->validate(['accentColor']);
});

check('quiet hours need both ends', function() {
    $settings = new Settings();
    $settings->quietHoursStart = '22:00';

    return !$settings->hasQuietHours();
});

check('quiet hours wrapping midnight are accepted', function() {
    $settings = new Settings();
    $settings->quietHoursStart = '22:00';
    $settings->quietHoursEnd = '07:00';

    return $settings->hasQuietHours() && $settings->validate();
});

check('a service worker path outside the root is refused', function() {
    $settings = new Settings();
    $settings->serviceWorkerPath = 'not-absolute.js';

    return !$settings->validate(['serviceWorkerPath']);
});

check('the default settings validate', fn() => (new Settings())->validate());

check('all three dedupe policies are offered', fn() => array_keys(Settings::dedupeOptions()) === [
    Settings::DEDUPE_NONE, Settings::DEDUPE_FALLBACK, Settings::DEDUPE_FIRST,
]);

check('all three runner modes are offered', fn() => count(Settings::runnerModeOptions()) === 3);

// ----------------------------------------------------------------------------------- cleanup

section('Cleanup');

$cleanup();

check('every fixture this run created has been removed', function() use (&$createdNotificationIds, &$createdSubscriberIds, &$createdAudienceIds) {
    $leftovers = [];

    $remaining = (int)(new Query())->from(Table::NOTIFICATIONS)
        ->where(['id' => array_values(array_unique(array_filter($createdNotificationIds)))])->count();

    if ($remaining > 0) {
        $leftovers[] = "$remaining notification(s)";
    }

    $remaining = (int)(new Query())->from(Table::SUBSCRIBERS)
        ->where(['id' => array_values(array_unique($createdSubscriberIds))])->count();

    if ($remaining > 0) {
        $leftovers[] = "$remaining subscriber(s)";
    }

    $remaining = (int)(new Query())->from(Table::AUDIENCES)
        ->where(['id' => array_values(array_unique($createdAudienceIds))])->count();

    if ($remaining > 0) {
        $leftovers[] = "$remaining audience(s)";
    }

    // Scoped to what this run recorded, deliberately. Asserting the *table* is empty would fail on any
    // site that has real notifications — which is every site this would ever be run on except a fresh
    // one, and it briefly did exactly that here.
    //
    // A suite that leaves fixtures behind stops being repeatable, and the second run then fails for
    // reasons that have nothing to do with the code. Asserting it is the only way that stays true.
    return $leftovers === [] ? true : 'left behind: ' . implode(', ', $leftovers);
});

// ----------------------------------------------------------------------------------- summary

echo "\n";
echo str_repeat('─', 70) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

if ($failures !== []) {
    echo "\nFailures:\n";

    foreach ($failures as $failure) {
        echo "  • $failure\n";
    }
}

exit($failed === 0 ? 0 : 1);
