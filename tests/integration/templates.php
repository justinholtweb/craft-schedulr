<?php
/**
 * Schedulr CP render checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-schedulr/tests/integration/templates.php
 *
 * Logs in to the control panel over HTTP as the harness admin and GETs every Schedulr CP screen,
 * asserting each one renders (200, no Twig or PHP error page) and carries the markup the CP UI guide
 * asks for: labelled fields, Craft's own nav include, `actionInput()` rather than a hand-written
 * action field, no hard-coded colours, no `.error-notice`, no pane nested in the content pane.
 *
 * Read-only. It creates nothing and changes no setting, so there is nothing to clean up. Detail
 * screens use whatever notification, subscriber and audience already exist; a screen with nothing to
 * show is reported as skipped rather than failed.
 *
 * Environment overrides: SCHEDULR_CP_HOST (default plugin-testing.ddev.site), SCHEDULR_CP_USER
 * (admin), SCHEDULR_CP_PASSWORD (claudepassword).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

$host = getenv('SCHEDULR_CP_HOST') ?: 'plugin-testing.ddev.site';
$user = getenv('SCHEDULR_CP_USER') ?: 'admin';
$password = getenv('SCHEDULR_CP_PASSWORD') ?: 'claudepassword';
$cpTrigger = Craft::$app->getConfig()->getGeneral()->cpTrigger ?: 'admin';

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];

function check(string $label, callable $test): void
{
    global $passed, $failed, $failures;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";

        return;
    }

    $failed++;
    $detail = $result === false ? '' : ' — ' . (string)$result;
    $failures[] = $label . $detail;
    echo "  ✗ $label$detail\n";
}

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  - $label (skipped: $why)\n";
}

$jar = new CookieJar();
$client = new Client([
    'base_uri' => 'http://127.0.0.1',
    'headers' => ['Host' => $host, 'User-Agent' => 'schedulr-template-checks'],
    'cookies' => $jar,
    'allow_redirects' => false,
    'http_errors' => false,
    'timeout' => 240,
]);

// ------------------------------------------------------------------------------------- login

echo "\nLogging in\n";

$loginPage = (string)$client->get("/$cpTrigger/login")->getBody();
preg_match('/csrfTokenValue":"([^"]+)"/', $loginPage, $m)
    || preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $loginPage, $m);
$csrf = $m[1] ?? null;

check('the login page exposes a CSRF token', fn() => $csrf !== null ?: 'no token found');

$login = $client->post("/$cpTrigger/actions/users/login", [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => [
        'CRAFT_CSRF_TOKEN' => $csrf,
        'loginName' => $user,
        'password' => $password,
    ],
]);

check('the harness admin can log in', fn() => $login->getStatusCode() === 200 ?: 'HTTP ' . $login->getStatusCode() . ': ' . substr((string)$login->getBody(), 0, 200));

// ---------------------------------------------------------------------------------- fixtures

$notificationId = (new Query())->select('n.id')->from(['n' => '{{%schedulr_notifications}}'])
    ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[n.id]]')
    ->where(['e.dateDeleted' => null])->orderBy(['n.id' => SORT_DESC])->scalar() ?: null;
$subscriberId = (new Query())->select('id')->from('{{%schedulr_subscribers}}')->orderBy(['id' => SORT_ASC])->scalar() ?: null;
$audienceId = (new Query())->select('id')->from('{{%schedulr_audiences}}')->orderBy(['id' => SORT_ASC])->scalar() ?: null;

// ------------------------------------------------------------------------------------ helpers

/**
 * GETs a CP page and returns its body, or throws with why it is not a rendered page.
 */
function page(string $path): string
{
    global $client, $cpTrigger;

    $response = $client->get("/$cpTrigger/$path");
    $status = $response->getStatusCode();
    $body = (string)$response->getBody();

    if ($status >= 300 && $status < 400) {
        throw new RuntimeException("redirected to " . $response->getHeaderLine('Location'));
    }

    if ($status !== 200) {
        preg_match('/<title>([^<]*)<\/title>/', $body, $t);
        preg_match('/class="error-message"[^>]*>([^<]+)/', $body, $e);
        throw new RuntimeException("HTTP $status " . trim(($t[1] ?? '') . ' ' . ($e[1] ?? '')));
    }

    foreach (['Twig\\Error', 'Twig Runtime Error', 'Twig Syntax Error', 'Internal Server Error', 'yii\\base\\ErrorException'] as $marker) {
        if (str_contains($body, $marker)) {
            throw new RuntimeException("error page ($marker)");
        }
    }

    return $body;
}

/**
 * The content pane only, so assertions are not tripped by Craft's own chrome.
 */
function content(string $body): string
{
    $start = strpos($body, 'id="main-content"');
    $end = strpos($body, '<footer', $start ?: 0);

    return $start === false ? $body : substr($body, $start, ($end ?: strlen($body)) - $start);
}

/**
 * The structural rules every Schedulr screen should satisfy.
 *
 * @return true|string
 */
function conventions(string $body): bool|string
{
    $html = content($body);
    $problems = [];

    // In inline styles only: a colour field's value is a hex string and is fine.
    // Craft's own colour input previews its value in a style attribute, so that one is allowed.
    if (preg_match('/<(?![^>]*class="[^"]*color-preview)[^>]*style="[^"]*#[0-9a-f]{6}[^>]*>/i', $html, $hex)) {
        $problems[] = 'hard-coded colour: ' . substr($hex[0], 0, 160);
    }

    if (str_contains($html, 'error-notice') || str_contains($html, 'select-inline')) {
        $problems[] = 'class that does not exist in Craft 5';
    }

    if (preg_match('/class="[^"]*\bbtn delete\b/', $html)) {
        $problems[] = '`btn delete` instead of `btn caution`';
    }

    if (preg_match('/<div class="pane"/', $html)) {
        $problems[] = 'pane nested in the content pane';
    }

    // Every <label> must point at something, or be a legend-style group heading.
    if (preg_match_all('/<label(?![^>]*\bfor=)[^>]*>/', $html, $labels)) {
        $problems[] = count($labels[0]) . ' label(s) without `for`';
    }

    return $problems === [] ? true : implode('; ', $problems);
}

// ------------------------------------------------------------------------------ template source

echo "\nTemplate sources\n";

// Checked against the source, not the output: actionInput() renders the very same hidden input a
// hand-written one does, so only the template can tell them apart.
$templateDir = dirname(__DIR__, 2) . '/src/templates';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'twig' || $file->getFilename() === '_unsubscribed.twig') {
        continue;
    }

    $relative = substr($file->getPathname(), strlen($templateDir) + 1);
    $source = (string)file_get_contents($file->getPathname());

    check("$relative has no hand-written action/id inputs, hex colours or em-dash copy", function() use ($source) {
        $problems = [];

        if (preg_match('/<input[^>]+type="hidden"/', $source)) {
            $problems[] = 'hand-written hidden input';
        }

        if (preg_match('/#[0-9A-F]{6}\b/i', $source)) {
            $problems[] = 'hex colour';
        }

        // Em-dashes inside translatable strings ('…'|t), not in Twig comments.
        if (preg_match("/'[^'\n]*\u{2014}[^'\n]*'\|t\(/u", $source)) {
            $problems[] = 'em-dash in a translatable string';
        }

        return $problems === [] ? true : implode('; ', $problems);
    });
}

// -------------------------------------------------------------------------------------- pages

$pages = [
    'notifications' => 'Notifications index',
    'notifications/new' => 'New notification',
    'schedule' => 'Schedule',
    'subscribers' => 'Subscribers index',
    'subscribers?filter=pushable&search=zz-no-match' => 'Subscribers, filtered to nobody',
    'audiences' => 'Audiences index',
    'audiences/new' => 'New audience',
    'reports' => 'Reports',
    'reports?days=7' => 'Reports, 7 days',
    'settings/general' => 'Settings: General',
    'settings/prompt' => 'Settings: Opt-in prompt',
    'settings/push' => 'Settings: Web push',
    'settings/email' => 'Settings: Email',
    'settings/on-site' => 'Settings: On-site',
    'settings/delivery' => 'Settings: Delivery',
    'settings/privacy' => 'Settings: Privacy',
];

if ($notificationId) {
    $pages["notifications/$notificationId"] = "Notification #$notificationId";
    $pages["notifications/$notificationId/deliveries"] = "Delivery report #$notificationId";
    $pages["notifications/$notificationId/deliveries?failed=1"] = "Delivery report #$notificationId, failures only";
}

if ($subscriberId) {
    $pages["subscribers/$subscriberId"] = "Subscriber #$subscriberId";
}

if ($audienceId) {
    $pages["audiences/$audienceId"] = "Audience #$audienceId";
}

$bodies = [];

echo "\nRendering every CP screen\n";

foreach ($pages as $path => $label) {
    check("$label renders", function() use ($path, &$bodies) {
        $bodies[$path] = page("schedulr/$path");

        return true;
    });

    if (isset($bodies[$path])) {
        check("$label follows the CP conventions", fn() => conventions($bodies[$path]));
    }
}

foreach (['notification' => $notificationId, 'subscriber' => $subscriberId, 'audience' => $audienceId] as $what => $id) {
    if (!$id) {
        skip("$what detail screen", "no $what exists in this install");
    }
}

// ------------------------------------------------------------------------- specific behaviours

echo "\nSpecific screens\n";

$settings = $bodies['settings/general'] ?? '';

check('settings use Craft’s nav include, with the current pane marked', function() use ($settings) {
    if (!preg_match('/<nav aria-label="Settings">(.*?)<\/nav>/s', $settings, $nav)) {
        return 'no <nav aria-label="Settings">';
    }

    return preg_match('/<a\b(?=[^>]*\bsel\b)(?=[^>]*aria-current="page")(?=[^>]*settings\/general[?"])[^>]*>/', $nav[1]) === 1
        ?: 'current pane not marked: ' . substr(trim(preg_replace('/\s+/', ' ', $nav[1])), 0, 300);
});

check('settings post through actionInput() and remember their pane', fn() =>
    (str_contains($settings, 'name="action" value="schedulr/settings/save"') && str_contains($settings, 'name="pane" value="general"'))
        ?: 'action or pane input missing');

check('the settings pane title is the header h1, not a second h1 in the content', function() use ($settings) {
    $h1s = preg_match_all('/<h1\b/', $settings);

    return $h1s === 1 ?: "$h1s h1 elements";
});

check('the cron line is a copytext field', fn() =>
    str_contains($bodies['settings/delivery'] ?? '', 'class="copytext"') ?: 'no copytext');

check('frequency caps say what they need instead of appending “— Pro”', function() use ($bodies) {
    $html = $bodies['settings/delivery'] ?? '';

    if (str_contains($html, '— Pro')) {
        return 'still concatenated';
    }

    return Craft::$app->getPlugins()->getPlugin('schedulr')->isPro()
        || str_contains($html, 'Requires Schedulr Pro.')
        ?: 'no Pro warning on the disabled fields';
});

check('the VAPID public key, when there is one, is a copytext field', function() use ($bodies) {
    $html = $bodies['settings/push'] ?? '';

    return !str_contains($html, 'VAPID public key') || str_contains($html, 'id="vapidPublicKey"') || !str_contains($html, 'copytext')
        ? true
        : 'public key not in a copytext';
});

check('“Save and send now” is a confirming form action', function() use ($bodies) {
    $html = $bodies['notifications/new'] ?? '';

    return (str_contains($html, 'formsubmit') && str_contains($html, 'data-confirm=') && str_contains($html, 'sendNow'))
        ?: 'no formsubmit with data-confirm and sendNow params';
});

check('notification action buttons are an editable table', fn() =>
    str_contains($bodies['notifications/new'] ?? '', 'id="buttons"') && str_contains($bodies['notifications/new'] ?? '', 'class="editable')
        ?: 'no editable table');

check('the audience select has a label', fn() =>
    preg_match('/<label[^>]+for="audienceId"/', $bodies['notifications/new'] ?? '') === 1 ?: 'no label for #audienceId');

check('the reports chart is described for assistive tech and has a table fallback', function() use ($bodies) {
    $html = $bodies['reports'] ?? '';

    if (str_contains($html, 'Nothing has been sent in this period.')) {
        return true;
    }

    return (str_contains($html, 'role="img"') && str_contains($html, 'aria-label="Deliveries per day') && str_contains($html, '<details'))
        ?: 'chart lacks role/aria-label/table';
});

check('the report range toggle marks the current range', fn() =>
    preg_match('/class="btn small active"[^>]*aria-current="page"/', $bodies['reports?days=7'] ?? '') === 1 ?: 'no aria-current');

check('an empty subscriber filter says so', fn() =>
    str_contains($bodies['subscribers?filter=pushable&search=zz-no-match'] ?? '', 'class="zilch') ?: 'no zilch empty state');

check('the subscriber search has an accessible label', fn() =>
    preg_match('/<label for="subscriber-search"/', $bodies['subscribers'] ?? '') === 1 ?: 'no label');

if ($subscriberId) {
    check('subscriber delete is a caution button with a confirm', fn() =>
        preg_match('/<button[^>]*id="delete-subscriber"[^>]*class="caution btn"|<button[^>]*class="caution btn"[^>]*id="delete-subscriber"/', $bodies["subscribers/$subscriberId"] ?? '') === 1
            || preg_match('/id="delete-subscriber"[^>]*data-confirm=/', $bodies["subscribers/$subscriberId"] ?? '') === 1
            ?: 'not a caution button');
}

// ----------------------------------------------------------------------------------- summary

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed passed, $failed failed, $skipped skipped\n";

if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
}

exit($failed > 0 ? 1 : 0);
