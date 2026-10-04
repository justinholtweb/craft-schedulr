<?php
/**
 * Schedulr security checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-schedulr/tests/integration/security.php
 *
 * The companion to `checks.php`, for the fixes that came out of the security review: the push-endpoint
 * allowlist, the send permission on scheduling, URL validation, CSV escaping, signed click links, the
 * two-step email unsubscribe and the event endpoint's attribution. Same rules as `checks.php` —
 * idempotent, self-cleaning, and asserting its own cleanup.
 *
 * The HTTP checks talk to the site's own web server on 127.0.0.1, because a controller's behaviour on
 * GET versus POST is the thing under test and a console request has neither. Nothing here reaches a push
 * service: every endpoint is on a `.invalid` host, which cannot resolve by definition, and the allowlist
 * is extended for it **in memory only** — the stored settings are never touched.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\db\Query;
use craft\elements\User;
use craft\events\AuthorizationCheckEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\schedulr\controllers\NotificationsController;
use justinholtweb\schedulr\controllers\ReportsController;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;
use yii\base\Event;
use yii\web\ForbiddenHttpException;

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
$settings = $plugin->getSettings();

$RUN = 'schedulr-sec-' . StringHelper::randomString(8);

// In memory only, and restored before the run ends. The `.invalid` TLD can never resolve, which is what
// makes it the one host it is safe to add for a test.
$originalExtraHosts = $settings->extraPushHosts;
$settings->extraPushHosts = array_merge($originalExtraHosts, ['push.invalid']);

$createdSubscriberIds = [];
$createdNotificationIds = [];
$createdUserIds = [];

const P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

function makeSubscriber(array $overrides = []): Subscriber
{
    global $db, $siteId, $createdSubscriberIds, $plugin;

    $now = Db::prepareDateForDb(new DateTime());

    $db->createCommand()->insert(Table::SUBSCRIBERS, array_merge([
        'siteId' => $siteId,
        'visitorId' => StringHelper::UUID(),
        'contentEncoding' => 'aes128gcm',
        'visits' => 1,
        'dateFirstSeen' => $now,
        'dateLastSeen' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $overrides))->execute();

    $id = (int)$db->getLastInsertID();
    $createdSubscriberIds[] = $id;

    return $plugin->subscribers->getById($id);
}

function makeNotification(array $attributes = []): Notification
{
    global $siteId, $createdNotificationIds, $plugin;

    $notification = new Notification();
    $notification->siteId = $siteId;
    $notification->title = 'Security ' . StringHelper::randomString(6);
    $notification->state = Notification::STATE_DRAFT;

    foreach ($attributes as $key => $value) {
        $notification->$key = $value;
    }

    $notification->setSchedule(new Schedule(['mode' => Schedule::MODE_NOW]));

    if (!$plugin->notifications->save($notification)) {
        throw new RuntimeException('Fixture notification would not save: ' . Json::encode($notification->getErrors()));
    }

    $createdNotificationIds[] = $notification->id;

    return $notification;
}

/** A CP user with exactly these Schedulr permissions, and no admin flag. */
function makeUser(array $permissions): User
{
    global $createdUserIds, $RUN;

    $user = new User();
    $user->username = $RUN . '-' . StringHelper::randomString(6);
    $user->email = $user->username . '@example.invalid';
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user, false)) {
        throw new RuntimeException('Fixture user would not save.');
    }

    $createdUserIds[] = $user->id;

    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, array_map('strtolower', $permissions));

    return Craft::$app->getUsers()->getUserById($user->id);
}

/**
 * One request to the site's own web server.
 *
 * @return array{status: int, location: string, body: string}
 */
function http(string $method, string $path, array $options = []): array
{
    $client = Craft::createGuzzleClient(['timeout' => 90, 'base_uri' => 'http://127.0.0.1/']);

    $response = $client->request($method, ltrim($path, '/'), array_merge([
        'http_errors' => false,
        'allow_redirects' => false,
    ], $options));

    return [
        'status' => $response->getStatusCode(),
        'location' => $response->getHeaderLine('Location'),
        'body' => (string)$response->getBody(),
    ];
}

$cleanup = function() use (&$createdSubscriberIds, &$createdNotificationIds, &$createdUserIds, $db, $settings, $originalExtraHosts) {
    $settings->extraPushHosts = $originalExtraHosts;

    $notificationIds = array_values(array_unique(array_filter($createdNotificationIds)));
    $subscriberIds = array_values(array_unique(array_filter($createdSubscriberIds)));

    // Events are a ledger and outlive their subjects on purpose (`SET NULL`), so the ones this run
    // wrote are removed by hand before their subjects go.
    try {
        if ($notificationIds !== []) {
            $db->createCommand()->delete(Table::EVENTS, ['notificationId' => $notificationIds])->execute();
        }

        if ($subscriberIds !== []) {
            $db->createCommand()->delete(Table::EVENTS, ['subscriberId' => $subscriberIds])->execute();
        }
    } catch (Throwable) {
    }

    foreach ($notificationIds as $id) {
        try {
            $element = Notification::find()->id($id)->status(null)->one();

            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        } catch (Throwable) {
        }
    }

    foreach ($subscriberIds as $id) {
        try {
            $db->createCommand()->delete(Table::SUBSCRIBERS, ['id' => $id])->execute();
        } catch (Throwable) {
        }
    }

    foreach (array_unique($createdUserIds) as $id) {
        try {
            $user = Craft::$app->getUsers()->getUserById($id);

            if ($user !== null) {
                Craft::$app->getElements()->deleteElement($user, true);
            }
        } catch (Throwable) {
        }
    }
};

register_shutdown_function($cleanup);

echo "Schedulr security checks (run {$RUN}, edition " . ($plugin->isPro() ? 'Pro' : 'Lite') . ")\n";

// ------------------------------------------------------------------------- push endpoints

section('Push endpoint allowlist');

foreach ([
    'Chrome (FCM)' => 'https://fcm.googleapis.com/fcm/send/dXJ0c2FtcGxlOkFQQTkxYkg',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABk-sample',
    'Edge (WNS)' => 'https://wns2-par02p.notify.windows.com/w/?token=BQYAAABsample',
    'Safari' => 'https://web.push.apple.com/QGzZ7sample',
    'an explicit :443' => 'https://fcm.googleapis.com:443/fcm/send/abc',
] as $label => $endpoint) {
    check("a real $label endpoint is accepted", fn() => $plugin->subscribers->isAcceptableEndpoint($endpoint));
}

foreach ([
    'an arbitrary https host' => 'https://intranet.example.com/hook',
    'a lookalike suffix' => 'https://evilfcm.googleapis.com/fcm/send/x',
    'an allowlisted name as a subdomain of another host' => 'https://fcm.googleapis.com.attacker.example/x',
    'an allowlisted name in the userinfo' => 'https://fcm.googleapis.com@attacker.example/x',
    'credentials on an allowlisted host' => 'https://user:pass@fcm.googleapis.com/fcm/send/x',
    'a non-443 port' => 'https://fcm.googleapis.com:8443/fcm/send/x',
    'plain http' => 'http://fcm.googleapis.com/fcm/send/x',
    'a bare IP' => 'https://169.254.169.254/latest/meta-data',
    'embedded whitespace' => "https://fcm.googleapis.com/fcm/send/x\r\nHost: evil",
    'a backslash' => 'https://fcm.googleapis.com\\@attacker.example/x',
] as $label => $endpoint) {
    check("$label is refused", fn() => !$plugin->subscribers->isAcceptableEndpoint($endpoint));
}

check('a bare TLD in extraPushHosts does not open the allowlist', function() use ($plugin, $settings) {
    $before = $settings->extraPushHosts;
    $settings->extraPushHosts = array_merge($before, ['com']);

    try {
        return !$plugin->subscribers->isAcceptableEndpoint('https://attacker.com/x');
    } finally {
        $settings->extraPushHosts = $before;
    }
});

check('extraPushHosts validates as hostnames', function() {
    $model = new Settings();
    $model->extraPushHosts = ['push.example.net', 'https://not-a-host/'];

    return !$model->validate(['extraPushHosts']);
});

check('subscribe() refuses an endpoint off the allowlist', function() use ($plugin, $siteId) {
    return $plugin->subscribers->subscribe(StringHelper::UUID(), [
        'endpoint' => 'https://hooks.example.com/' . StringHelper::randomString(10),
        'keys' => ['p256dh' => P256DH, 'auth' => AUTH],
    ], $siteId) === null;
});

check('the push channel refuses a stored endpoint off the allowlist without a request', function() use ($plugin) {
    // Written directly, as PWA adoption or a site's own code could. The send must stop before the network.
    $endpoint = 'https://hooks.example.com/' . StringHelper::randomString(10);
    $subscriber = makeSubscriber([
        'endpoint' => $endpoint,
        'endpointHash' => hash('sha256', $endpoint),
        'p256dh' => P256DH,
        'auth' => AUTH,
    ]);

    $notification = makeNotification(['body' => 'x']);
    $result = $plugin->sender->getChannel('push')->send($notification, $subscriber);

    return $result->isFailure() && str_contains((string)$result->error, 'recognised push service')
        ? true
        : 'result: ' . Json::encode($result);
});

// ----------------------------------------------------------------------- global unsubscribe

section('Unsubscribe from everything');

check('re-subscribing to push does not clear a global unsubscribe', function() use ($plugin, $siteId) {
    $visitorId = StringHelper::UUID();
    $subscriber = makeSubscriber(['visitorId' => $visitorId, 'unsubscribed' => true]);

    $plugin->subscribers->subscribe($visitorId, [
        'endpoint' => 'https://push.invalid/' . StringHelper::randomString(20),
        'keys' => ['p256dh' => P256DH, 'auth' => AUTH],
    ], $siteId);

    $after = $plugin->subscribers->getById($subscriber->id);

    return $after->unsubscribed && !$after->isPushable() && !$after->isOnSiteReachable()
        ? true
        : 'unsubscribed=' . var_export($after->unsubscribed, true);
});

check('an unsubscribe token round-trips', function() use ($plugin) {
    $token = $plugin->subscribers->unsubscribeToken(4242);

    return $plugin->subscribers->subscriberIdFromUnsubscribeToken($token) === 4242;
});

check('a token over a bare ID — any other signed value — is not an unsubscribe token', function() use ($plugin) {
    $bare = Craft::$app->getSecurity()->hashData('4242');

    return $plugin->subscribers->subscriberIdFromUnsubscribeToken($bare) === null;
});

// --------------------------------------------------------------------- send permission gate

section('Scheduling needs the send permission');

$manager = makeUser([Plugin::PERMISSION_VIEW_NOTIFICATIONS, Plugin::PERMISSION_MANAGE_NOTIFICATIONS]);
$sender = makeUser([
    Plugin::PERMISSION_VIEW_NOTIFICATIONS,
    Plugin::PERMISSION_MANAGE_NOTIFICATIONS,
    Plugin::PERMISSION_SEND_NOTIFICATIONS,
]);

check('the fixture users have the permissions they were given', fn() => $manager->can(Plugin::PERMISSION_MANAGE_NOTIFICATIONS)
    && !$manager->can(Plugin::PERMISSION_SEND_NOTIFICATIONS)
    && $sender->can(Plugin::PERMISSION_SEND_NOTIFICATIONS)
    && !$manager->admin);

check('a manager enabling a schedule is saved as a draft', function() use ($manager) {
    $notification = new Notification();
    $notification->state = Notification::STATE_SCHEDULED;

    $downgraded = NotificationsController::enforceSendPermission($notification, false, $manager);

    return $downgraded && $notification->state === Notification::STATE_DRAFT;
});

check('a manager attaching an automation has it removed', function() use ($manager) {
    $notification = new Notification();
    $notification->triggerType = 'entry.published';
    $notification->triggerConfig = ['sectionIds' => [1]];

    $downgraded = NotificationsController::enforceSendPermission($notification, false, $manager);

    return $downgraded && $notification->triggerType === null && $notification->getTriggerConfig() === [];
});

check('a manager saving a plain draft is not downgraded', function() use ($manager) {
    $notification = new Notification();

    return NotificationsController::enforceSendPermission($notification, false, $manager) === false
        && $notification->state === Notification::STATE_DRAFT;
});

check('a manager cannot edit a notification that is already scheduled', function() use ($manager) {
    $stored = makeNotification(['state' => Notification::STATE_SCHEDULED]);
    $wasArmed = NotificationsController::isArmed($stored);

    try {
        NotificationsController::enforceSendPermission($stored, $wasArmed, $manager);
    } catch (ForbiddenHttpException) {
        return $wasArmed;
    }

    return 'no exception';
});

check('a sender may schedule and automate', function() use ($sender) {
    $notification = new Notification();
    $notification->state = Notification::STATE_SCHEDULED;
    $notification->triggerType = 'entry.published';

    return NotificationsController::enforceSendPermission($notification, true, $sender) === false
        && $notification->state === Notification::STATE_SCHEDULED
        && $notification->triggerType === 'entry.published';
});

check('EVENT_AUTHORIZE_VIEW can grant access Schedulr’s permissions do not', function() {
    $nobody = makeUser([]);
    $notification = new Notification();

    if ($notification->canView($nobody)) {
        return 'a user with no permissions could already view';
    }

    $handler = function(AuthorizationCheckEvent $event) use ($nobody) {
        if ($event->user->id === $nobody->id) {
            $event->authorized = true;
        }
    };

    Event::on(Notification::class, Element::EVENT_AUTHORIZE_VIEW, $handler);

    try {
        return $notification->canView($nobody);
    } finally {
        Event::off(Notification::class, Element::EVENT_AUTHORIZE_VIEW, $handler);
    }
});

// ------------------------------------------------------------------------------- URLs

section('Notification URLs');

foreach (['https://example.com/a', 'http://example.com', '/news', 'news/today', '?q=1', '#top', ''] as $url) {
    check("“{$url}” is a safe URL", fn() => Notification::isSafeUrl($url));
}

foreach ([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    " javascript:alert(1)",
    "java\tscript:alert(1)",
    "java\nscript:alert(1)",
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'https:\\\\evil.example',
] as $url) {
    check('“' . addcslashes($url, "\t\n") . '” is refused', fn() => !Notification::isSafeUrl($url));
}

check('a notification with a javascript: link does not validate', function() {
    $notification = new Notification();
    $notification->title = 'x';
    $notification->url = 'javascript:alert(document.cookie)';

    return !$notification->validate(['url']) && $notification->hasErrors('url');
});

check('a javascript: image, icon or badge does not validate', function() {
    $notification = new Notification();
    $notification->title = 'x';
    $notification->imageUrl = 'javascript:a()';
    $notification->iconUrl = 'data:image/svg+xml,<svg onload=alert(1)>';
    $notification->badgeUrl = 'vbscript:x';

    $notification->validate(['imageUrl', 'iconUrl', 'badgeUrl']);

    return $notification->hasErrors('imageUrl') && $notification->hasErrors('iconUrl') && $notification->hasErrors('badgeUrl');
});

check('a javascript: button link does not validate', function() {
    $notification = new Notification();
    $notification->title = 'x';
    $notification->buttons = [['title' => 'Go', 'url' => 'javascript:alert(1)']];

    return !$notification->validate(['buttons']);
});

check('the default icon setting refuses a javascript: URL', function() {
    $model = new Settings();
    $model->defaultIcon = 'javascript:alert(1)';

    return !$model->validate(['defaultIcon']);
});

check('push action buttons carry their own index through the tracked redirect', function() use ($plugin) {
    $before = $plugin->edition;
    $plugin->edition = Plugin::EDITION_PRO;

    try {
        $notification = makeNotification([
            'url' => '/main',
            'buttons' => [['title' => 'One', 'url' => '/one'], ['title' => 'Two', 'url' => '/two']],
        ]);

        $payload = $notification->toPayload([], 77, null);
        $actions = $payload['actions'] ?? [];

        return count($actions) === 2
            && str_contains($actions[0]['url'], 'sr_b=0')
            && str_contains($actions[1]['url'], 'sr_b=1')
            && !str_contains($actions[0]['url'], '/one')
            && isset($payload['k'])
            ? true
            : Json::encode($payload);
    } finally {
        $plugin->edition = $before;
    }
});

// ------------------------------------------------------------------------------- signing

section('Signing');

check('click signatures are not a raw HMAC under the security key', function() use ($plugin) {
    $params = ['sr_c' => 'push', 'sr_n' => 42];
    $raw = substr(hash_hmac('sha256', http_build_query($params), (string)Craft::$app->getConfig()->getGeneral()->securityKey), 0, 12);

    return $plugin->analytics->sign($params) !== $raw;
});

check('an event signature binds notification, variant and subscriber', function() use ($plugin) {
    $k = $plugin->analytics->eventSignature(10, null, 20);

    return $plugin->analytics->verifyEventSignature(10, null, 20, $k)
        && !$plugin->analytics->verifyEventSignature(10, null, 21, $k)
        && !$plugin->analytics->verifyEventSignature(11, null, 20, $k)
        && !$plugin->analytics->verifyEventSignature(10, 1, 20, $k);
});

// ---------------------------------------------------------------------------------- CSV

section('CSV export');

check('formula-leading cells are defused', function() {
    $row = ReportsController::csvSafe(['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', "\tx", "\rx", 'plain', 42, '-7', null]);

    return $row === ["'=HYPERLINK(\"http://x\")", "'+1+1", "'-2+3", "'@SUM(A1)", "'\tx", "'\rx", 'plain', 42, '-7', null]
        ? true
        : Json::encode($row);
});

// ---------------------------------------------------------------------------- emailable

section('Email reach');

check('a subscriber reachable through their Craft user counts as emailable', function() use ($plugin, $siteId) {
    $user = makeUser([]);
    $before = $plugin->subscribers->stats($siteId)['emailable'];
    $subscriber = makeSubscriber(['userId' => $user->id]);
    $after = $plugin->subscribers->stats($siteId)['emailable'];

    $listed = array_map(fn(Subscriber $s) => $s->id, $plugin->subscribers->findAll(['emailable' => true, 'ids' => [$subscriber->id]]));

    return $subscriber->isEmailable() && $after === $before + 1 && $listed === [$subscriber->id]
        ? true
        : "before=$before after=$after";
});

// --------------------------------------------------------------------------- over HTTP

section('Public endpoints (HTTP)');

$reachable = false;

try {
    $reachable = http('GET', 'schedulr/go')['status'] > 0;
} catch (Throwable $e) {
    echo "  (web server not reachable: {$e->getMessage()})\n";
}

if ($reachable) {
    check('/schedulr/go 404s for an unsigned link to an unsent notification', function() {
        $notification = makeNotification(['url' => '/draft-destination']);
        $response = http('GET', 'schedulr/go?sr_n=' . $notification->id);

        return $response['status'] === 404 ? true : 'status ' . $response['status'] . ' → ' . $response['location'];
    });

    check('/schedulr/go redirects a correctly signed link', function() use ($plugin) {
        $notification = makeNotification(['url' => '/signed-destination']);
        $params = ['sr_n' => $notification->id, 'sr_c' => 'push'];
        $params['sr_k'] = $plugin->analytics->sign($params);

        $response = http('GET', 'schedulr/go?' . http_build_query($params));

        return $response['status'] === 302 && str_contains($response['location'], '/signed-destination')
            ? true
            : 'status ' . $response['status'] . ' → ' . $response['location'];
    });

    check('/schedulr/go still redirects an unsigned link once the notification has gone out', function() use ($db) {
        $notification = makeNotification(['url' => '/sent-destination']);
        $db->createCommand()->update(Table::NOTIFICATIONS, [
            'dateLastSent' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $notification->id])->execute();

        $response = http('GET', 'schedulr/go?sr_n=' . $notification->id);

        return $response['status'] === 302 && str_contains($response['location'], '/sent-destination')
            ? true
            : 'status ' . $response['status'];
    });

    check('/schedulr/go never redirects to a stored javascript: URL', function() use ($plugin, $db) {
        // Written past validation, as a row from before it existed would be.
        $notification = makeNotification(['url' => '/placeholder']);
        $db->createCommand()->update(Table::NOTIFICATIONS, ['url' => 'javascript:alert(1)'], ['id' => $notification->id])->execute();

        $params = ['sr_n' => $notification->id, 'sr_c' => 'push'];
        $params['sr_k'] = $plugin->analytics->sign($params);
        $response = http('GET', 'schedulr/go?' . http_build_query($params));

        return $response['status'] === 302 && !str_contains(strtolower($response['location']), 'javascript')
            ? true
            : 'status ' . $response['status'] . ' → ' . $response['location'];
    });

    check('GET on an unsubscribe link asks, and does not unsubscribe', function() use ($plugin) {
        $subscriber = makeSubscriber(['email' => 'scan@example.invalid']);
        $token = $plugin->subscribers->unsubscribeToken($subscriber->id);

        $response = http('GET', 'schedulr/unsubscribe?sr_u=' . rawurlencode($token));
        $after = $plugin->subscribers->getById($subscriber->id);

        return $response['status'] === 200 && str_contains($response['body'], '<form') && !$after->unsubscribed
            ? true
            : 'status ' . $response['status'] . ', unsubscribed=' . var_export($after->unsubscribed, true);
    });

    check('the confirmation form’s POST unsubscribes', function() use ($plugin) {
        $subscriber = makeSubscriber(['email' => 'form@example.invalid']);
        $token = $plugin->subscribers->unsubscribeToken($subscriber->id);

        $response = http('POST', 'schedulr/unsubscribe', ['form_params' => ['sr_u' => $token]]);

        return $response['status'] === 200 && $plugin->subscribers->getById($subscriber->id)->unsubscribed
            ? true
            : 'status ' . $response['status'];
    });

    check('an RFC 8058 one-click POST unsubscribes', function() use ($plugin) {
        $subscriber = makeSubscriber(['email' => 'oneclick@example.invalid']);
        $token = $plugin->subscribers->unsubscribeToken($subscriber->id);

        $response = http('POST', 'schedulr/unsubscribe?sr_u=' . rawurlencode($token), [
            'form_params' => ['List-Unsubscribe' => 'One-Click'],
        ]);

        return $response['status'] === 200 && $plugin->subscribers->getById($subscriber->id)->unsubscribed
            ? true
            : 'status ' . $response['status'];
    });

    check('a forged unsubscribe token is refused', function() {
        $response = http('POST', 'schedulr/unsubscribe', ['form_params' => ['sr_u' => 'forged']]);

        return $response['status'] === 400 ? true : 'status ' . $response['status'];
    });

    check('a cross-site text/plain heartbeat is refused', function() {
        $response = http('POST', 'schedulr/heartbeat', [
            'headers' => ['Content-Type' => 'text/plain', 'Sec-Fetch-Site' => 'cross-site'],
            'body' => Json::encode(['visitorId' => StringHelper::UUID()]),
        ]);

        return $response['status'] === 400 ? true : 'status ' . $response['status'];
    });

    check('a JSON heartbeat from the runtime is answered', function() use ($plugin, $siteId, &$createdSubscriberIds) {
        $visitorId = StringHelper::UUID();
        $response = http('POST', 'schedulr/heartbeat', [
            'headers' => ['Accept' => 'application/json'],
            'json' => ['visitorId' => $visitorId],
        ]);

        $row = $plugin->subscribers->getByVisitorId($visitorId);

        if ($row !== null) {
            $createdSubscriberIds[] = $row->id;
        }

        return $response['status'] === 200 && (Json::decode($response['body'])['ok'] ?? false) === true
            ? true
            : 'status ' . $response['status'] . ' ' . substr($response['body'], 0, 200);
    });

    check('an event with a bare numeric subscriber ID is refused', function() use ($db) {
        $notification = makeNotification();
        $subscriber = makeSubscriber();

        $response = http('POST', 'actions/schedulr/track/event', [
            'json' => ['type' => 'displayed', 'n' => $notification->id, 's' => $subscriber->id],
        ]);

        $rows = (int)(new Query())->from(Table::EVENTS)->where(['notificationId' => $notification->id])->count();

        return $rows === 0 && (Json::decode($response['body'])['ok'] ?? null) === false
            ? true
            : "status {$response['status']}, $rows row(s)";
    });

    check('a signed worker event is recorded once, however often it is sent', function() use ($plugin) {
        $notification = makeNotification();
        $subscriber = makeSubscriber();
        $k = $plugin->analytics->eventSignature($notification->id, null, $subscriber->id);

        foreach ([1, 2, 3] as $_) {
            http('POST', 'actions/schedulr/track/event', [
                'json' => ['type' => 'displayed', 'n' => $notification->id, 's' => $subscriber->id, 'k' => $k],
            ]);
        }

        $rows = (int)(new Query())->from(Table::EVENTS)
            ->where(['notificationId' => $notification->id, 'subscriberId' => $subscriber->id])->count();

        return $rows === 1 ? true : "$rows row(s)";
    });

    check('an on-site event attributed by visitor UUID is recorded', function() {
        $notification = makeNotification();
        $subscriber = makeSubscriber();

        http('POST', 'actions/schedulr/track/event', [
            'json' => ['type' => 'dismissed', 'n' => $notification->id, 's' => $subscriber->visitorId, 'channel' => 'onsite'],
        ]);

        $rows = (int)(new Query())->from(Table::EVENTS)
            ->where(['notificationId' => $notification->id, 'subscriberId' => $subscriber->id])->count();

        return $rows === 1 ? true : "$rows row(s)";
    });
} else {
    check('the site’s web server is reachable for the HTTP checks', fn() => 'skipped — not reachable');
}

// ----------------------------------------------------------------------------------- cleanup

section('Cleanup');

$cleanup();

check('every fixture this run created has been removed', function() use (&$createdNotificationIds, &$createdSubscriberIds, &$createdUserIds, $settings, $originalExtraHosts) {
    $leftovers = [];

    $count = (int)(new Query())->from(Table::NOTIFICATIONS)
        ->where(['id' => array_values(array_unique(array_filter($createdNotificationIds)))])->count();

    if ($count > 0) {
        $leftovers[] = "$count notification(s)";
    }

    $count = (int)(new Query())->from(Table::SUBSCRIBERS)
        ->where(['id' => array_values(array_unique($createdSubscriberIds))])->count();

    if ($count > 0) {
        $leftovers[] = "$count subscriber(s)";
    }

    $count = (int)(new Query())->from(Table::EVENTS)->where(['or',
        ['notificationId' => array_values(array_unique(array_filter($createdNotificationIds)))],
        ['subscriberId' => array_values(array_unique($createdSubscriberIds))],
    ])->count();

    if ($count > 0) {
        $leftovers[] = "$count event(s)";
    }

    $count = (int)(new Query())->from(\craft\db\Table::USERS)->where(['id' => array_values(array_unique($createdUserIds))])->count();

    if ($count > 0) {
        $leftovers[] = "$count user(s)";
    }

    if ($settings->extraPushHosts !== $originalExtraHosts) {
        $leftovers[] = 'the in-memory push host list';
    }

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
