<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;

/**
 * Schedulr settings — everything a developer configures, as opposed to everything a marketer
 * authors.
 *
 * The split matters and is deliberate: this model is project config, so it deploys. Notifications,
 * audiences and schedules are *not* here, because "audience: lapsed readers" arriving in a
 * deployment is nobody's idea of a good afternoon.
 *
 * Note that nothing here is `required`. A fresh install must be able to save any setting without
 * first filling in a credential, or `savePluginSettings()` fails validation wholesale and the
 * screen becomes unusable.
 */
class Settings extends Model
{
    // --------------------------------------------------------------------------- prompts

    public const PROMPT_NATIVE = 'native';
    public const PROMPT_BELL = 'bell';
    public const PROMPT_SLIDE = 'slide';
    public const PROMPT_CUSTOM = 'custom';

    // ---------------------------------------------------------------------------- runner

    /** A one-minute cron calling `craft schedulr/run`. The only mode that sends on time. */
    public const RUNNER_CRON = 'cron';

    /** Cron if it is running, otherwise piggyback on web requests. The default. */
    public const RUNNER_AUTO = 'auto';

    /** Never run automatically. For sites driving Schedulr entirely from their own code. */
    public const RUNNER_MANUAL = 'manual';

    // ------------------------------------------------------------------------ delivery

    /** Send every enabled channel to everyone reachable on it. */
    public const DEDUPE_NONE = 'none';

    /** Email only the people the push did not reach. */
    public const DEDUPE_FALLBACK = 'fallback';

    /** One channel per person, in the notification's channel order. */
    public const DEDUPE_FIRST = 'first';

    // ------------------------------------------------------------------------- general

    /**
     * @var bool Whether Schedulr injects its runtime into front-end pages.
     *
     * Off leaves every endpoint working for a site that would rather call them from its own
     * bundle. Never a reason to disable the plugin.
     */
    public bool $injectRuntime = true;

    /**
     * @var string[] URI patterns the runtime is never injected into.
     */
    public array $excludedUris = [];

    /**
     * @var string The opt-in prompt style.
     */
    public string $promptStyle = self::PROMPT_BELL;

    /**
     * @var int Page views before the prompt is shown. Zero prompts immediately, which is the
     *          single most effective way to be blocked forever.
     */
    public int $promptAfterViews = 2;

    /**
     * @var int Seconds on the page before the prompt is shown.
     */
    public int $promptAfterSeconds = 8;

    /**
     * @var int Days before a dismissed prompt is offered again. Zero never re-asks.
     */
    public int $promptReaskDays = 30;

    /**
     * @var string Prompt heading.
     */
    public string $promptHeading = 'Stay in the loop';

    /**
     * @var string Prompt body.
     */
    public string $promptBody = 'Get a notification when we publish something new.';

    /**
     * @var string Accept button label.
     */
    public string $promptAccept = 'Allow';

    /**
     * @var string Decline button label.
     */
    public string $promptDecline = 'No thanks';

    /**
     * @var string Accent colour used by the prompt and the on-site banner.
     *
     * Craft's colour field posts hex *without* the leading `#`, so this is normalised on the way
     * in rather than validated against a pattern that the CP can never satisfy.
     */
    public string $accentColor = '#C7278C';

    // ----------------------------------------------------------------------------- push

    /**
     * @var string The `sub` claim on the VAPID JWT — a mailto: or https: URL identifying the site
     *             to the push service. Enough push services reject an absent one that a default
     *             beats an intermittent failure.
     */
    public string $pushSubject = '';

    /**
     * @var int Consecutive send failures before a device is dropped from the list.
     */
    public int $pushMaxFailures = 5;

    /**
     * @var string Default notification icon URL. Relative paths resolve against the site.
     */
    public string $defaultIcon = '';

    /**
     * @var string Default badge (monochrome, Android status bar).
     */
    public string $defaultBadge = '';

    /**
     * @var bool Whether to register a service worker at all.
     *
     * Forced off in effect when PWA owns the worker — see `services\Interop`. This setting is the
     * escape hatch for a site with a hand-written worker of its own.
     */
    public bool $registerServiceWorker = true;

    /**
     * @var string Path the worker is served from. It must be at the root to claim the whole site:
     *             a worker at `/assets/sw.js` can only ever control `/assets/`.
     */
    public string $serviceWorkerPath = '/schedulr-worker.js';

    // ---------------------------------------------------------------------------- email

    /**
     * @var string From name for notification emails. Empty uses the system setting.
     */
    public string $emailFromName = '';

    /**
     * @var string From address. Empty uses the system setting.
     */
    public string $emailFromEmail = '';

    /**
     * @var string Twig template rendering the notification email. Empty uses the bundled one.
     */
    public string $emailTemplate = '';

    // -------------------------------------------------------------------------- on-site

    /**
     * @var bool Whether the on-site channel renders its own banner. Off leaves the inbox endpoint
     *           available for a site that renders notifications itself.
     */
    public bool $onSiteRender = true;

    /**
     * @var string Where the on-site banner sits.
     */
    public string $onSitePosition = 'bottom-right';

    /**
     * @var int Seconds the on-site banner stays before dismissing itself. Zero waits for a click.
     */
    public int $onSiteAutoDismiss = 12;

    // --------------------------------------------------------------------------- runner

    /**
     * @var string How due notifications get sent.
     */
    public string $runnerMode = self::RUNNER_AUTO;

    /**
     * @var int Minimum seconds between web-fallback runs. The fallback is WP-Cron shaped: it can
     *          only run when somebody visits, so a quiet site sends late. Lowering this does not
     *          make a site with no visitors punctual.
     */
    public int $runnerMinInterval = 60;

    /**
     * @var int Recipients per queue job. Larger is fewer jobs and more memory per job; a job that
     *          dies loses its place for this many people until it is retried.
     */
    public int $batchSize = 200;

    /**
     * @var int Days of future occurrences the expander keeps materialised.
     */
    public int $expandHorizonDays = 90;

    /**
     * @var int Most notifications one subscriber may receive per `frequencyCapDays`. Zero is no cap.
     *
     * Enforced by *excluding* capped subscribers while the audience is resolved rather than by
     * skipping them at send time — thousands of `skipped` ledger rows per send would bury the skips
     * that mean something.
     */
    public int $frequencyCapCount = 0;

    /**
     * @var int The window the cap counts over.
     */
    public int $frequencyCapDays = 7;

    /**
     * @var string Start of the hours nothing may be delivered in, "HH:MM". Empty is no quiet hours.
     *
     * Quiet hours **defer**, they do not drop: an occurrence landing inside the window is moved to
     * the end of it. Dropping would mean a daily 07:00 notification silently never sending on a
     * site whose quiet hours run to 08:00, with no record that anything was skipped.
     */
    public string $quietHoursStart = '';

    /**
     * @var string End of the quiet window, "HH:MM". A window that wraps midnight is normal and
     *             supported — 22:00 to 07:00 is the usual answer.
     */
    public string $quietHoursEnd = '';

    // -------------------------------------------------------------------------- privacy

    /**
     * @var int Days of delivery and event rows to keep. Zero keeps them forever, which is a
     *          decision a site should make deliberately rather than by omission.
     */
    public int $ledgerRetentionDays = 90;

    /**
     * @var int Days a subscriber may go unseen before being forgotten. Zero never forgets.
     */
    public int $subscriberRetentionDays = 0;

    /**
     * @var bool Whether the subscriber's user agent is stored. Off still records the platform,
     *           which is what segments actually need.
     */
    public bool $storeUserAgent = true;

    // --------------------------------------------------------------------------- interop

    /**
     * @var bool Whether to defer to PWA's keys, subscribers and service worker when PWA is
     *           installed. Off makes Schedulr stand alone on a site that also runs PWA, which
     *           will prompt the visitor twice and can only end with one worker replacing the
     *           other. Off is supported; it is not advisable.
     */
    public bool $deferToPwa = true;

    // -------------------------------------------------------------------------- helpers

    /**
     * @return array<string, string>
     */
    public static function promptStyleOptions(): array
    {
        return [
            self::PROMPT_NATIVE => Craft::t('schedulr', 'Native browser dialog only'),
            self::PROMPT_BELL => Craft::t('schedulr', 'Bell button'),
            self::PROMPT_SLIDE => Craft::t('schedulr', 'Slide-down panel'),
            self::PROMPT_CUSTOM => Craft::t('schedulr', 'Custom — my own markup'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function runnerModeOptions(): array
    {
        return [
            self::RUNNER_AUTO => Craft::t('schedulr', 'Cron if available, web requests if not'),
            self::RUNNER_CRON => Craft::t('schedulr', 'Cron only'),
            self::RUNNER_MANUAL => Craft::t('schedulr', 'Never automatically'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function dedupeOptions(): array
    {
        return [
            self::DEDUPE_NONE => Craft::t('schedulr', 'Every channel, to everyone reachable'),
            self::DEDUPE_FALLBACK => Craft::t('schedulr', 'Email only the people push did not reach'),
            self::DEDUPE_FIRST => Craft::t('schedulr', 'One channel per person'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function onSitePositionOptions(): array
    {
        return [
            'top-left' => Craft::t('schedulr', 'Top left'),
            'top-right' => Craft::t('schedulr', 'Top right'),
            'bottom-left' => Craft::t('schedulr', 'Bottom left'),
            'bottom-right' => Craft::t('schedulr', 'Bottom right'),
        ];
    }

    /**
     * Whether quiet hours are configured at all.
     *
     * Both ends, not either: a start with no end is a window with no exit, and the sane reading of
     * a half-configured window is that it is not configured.
     */
    public function hasQuietHours(): bool
    {
        return $this->quietHoursStart !== '' && $this->quietHoursEnd !== ''
            && $this->quietHoursStart !== $this->quietHoursEnd;
    }

    /**
     * Craft's colour field posts `C7278C`, not `#C7278C`.
     *
     * A model validating `/^#[0-9a-f]{6}$/` therefore rejects every colour ever saved through the
     * CP and quietly keeps its default — invisible while the default *is* the colour being
     * tested with, and reported as "the colour picker doesn't work" the moment somebody changes it.
     */
    public function setAccentColor(string $value): void
    {
        $value = trim($value);

        if ($value !== '' && !str_starts_with($value, '#')) {
            $value = '#' . $value;
        }

        $this->accentColor = $value;
    }

    protected function defineRules(): array
    {
        return [
            [['injectRuntime', 'registerServiceWorker', 'onSiteRender', 'storeUserAgent', 'deferToPwa'], 'boolean'],
            [['promptStyle'], 'in', 'range' => array_keys(self::promptStyleOptions())],
            [['runnerMode'], 'in', 'range' => array_keys(self::runnerModeOptions())],
            [['onSitePosition'], 'in', 'range' => array_keys(self::onSitePositionOptions())],
            [
                [
                    'promptAfterViews', 'promptAfterSeconds', 'promptReaskDays', 'pushMaxFailures',
                    'onSiteAutoDismiss', 'runnerMinInterval', 'batchSize', 'expandHorizonDays',
                    'ledgerRetentionDays', 'subscriberRetentionDays',
                ],
                'integer',
                'min' => 0,
            ],
            [['frequencyCapCount', 'frequencyCapDays'], 'integer', 'min' => 0],
            [['quietHoursStart', 'quietHoursEnd'], 'match', 'pattern' => '/^\d{1,2}:\d{2}$/', 'skipOnEmpty' => true],
            [['batchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['expandHorizonDays'], 'integer', 'min' => 1, 'max' => 730],
            [['runnerMinInterval'], 'integer', 'min' => 10],
            [['pushMaxFailures'], 'integer', 'min' => 1],
            [['accentColor'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'skipOnEmpty' => true],
            [['emailFromEmail'], 'email', 'skipOnEmpty' => true],
            [['serviceWorkerPath'], 'match', 'pattern' => '/^\/[A-Za-z0-9._\/-]+\.js$/', 'skipOnEmpty' => true],
            [['excludedUris'], 'safe'],
        ];
    }
}
