<?php

declare(strict_types=1);

namespace justinholtweb\schedulr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\queue\jobs\PruneLedger;
use justinholtweb\schedulr\services\Analytics;
use justinholtweb\schedulr\services\Audiences;
use justinholtweb\schedulr\services\Automations;
use justinholtweb\schedulr\services\Deliveries;
use justinholtweb\schedulr\services\Interop;
use justinholtweb\schedulr\services\Keys;
use justinholtweb\schedulr\services\Notifications;
use justinholtweb\schedulr\services\Runner;
use justinholtweb\schedulr\services\Schedules;
use justinholtweb\schedulr\services\Sender;
use justinholtweb\schedulr\services\ServiceWorker;
use justinholtweb\schedulr\services\Subscribers;
use justinholtweb\schedulr\twig\SchedulrVariable;
use justinholtweb\schedulr\web\Injector;
use yii\base\Event;
use yii\log\Logger;

/**
 * Schedulr — scheduled notifications for Craft CMS.
 *
 * One message, composed once, delivered over web push, email and on-site, on a schedule, to a
 * segment, in each subscriber's own time zone.
 *
 * @property-read Keys $keys
 * @property-read Interop $interop
 * @property-read Subscribers $subscribers
 * @property-read Notifications $notifications
 * @property-read Schedules $schedules
 * @property-read Runner $runner
 * @property-read Sender $sender
 * @property-read Audiences $audiences
 * @property-read Deliveries $deliveries
 * @property-read Analytics $analytics
 * @property-read Automations $automations
 * @property-read ServiceWorker $serviceWorker
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW_NOTIFICATIONS = 'schedulr:viewNotifications';
    public const PERMISSION_MANAGE_NOTIFICATIONS = 'schedulr:manageNotifications';
    public const PERMISSION_SEND_NOTIFICATIONS = 'schedulr:sendNotifications';
    public const PERMISSION_DELETE_NOTIFICATIONS = 'schedulr:deleteNotifications';
    public const PERMISSION_VIEW_SUBSCRIBERS = 'schedulr:viewSubscribers';
    public const PERMISSION_MANAGE_SUBSCRIBERS = 'schedulr:manageSubscribers';
    public const PERMISSION_EXPORT_SUBSCRIBERS = 'schedulr:exportSubscribers';
    public const PERMISSION_MANAGE_AUDIENCES = 'schedulr:manageAudiences';
    public const PERMISSION_VIEW_ANALYTICS = 'schedulr:viewAnalytics';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'schedulr';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /** The settings screens render read-only when admin changes are disallowed, so keep the link. */
    public bool $hasReadOnlyCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'keys' => Keys::class,
                'interop' => Interop::class,
                'subscribers' => Subscribers::class,
                'notifications' => Notifications::class,
                'schedules' => Schedules::class,
                'runner' => Runner::class,
                'sender' => Sender::class,
                'audiences' => Audiences::class,
                'deliveries' => Deliveries::class,
                'analytics' => Analytics::class,
                'automations' => Automations::class,
                'serviceWorker' => ServiceWorker::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerSystemMessages();
        $this->registerTwig();
        $this->registerGarbageCollection();

        // Deferred so nothing here runs during install, when the tables do not exist yet.
        Craft::$app->onInit(function() {
            $this->automations->register();
            $this->registerRuntimeInjection();
            $this->registerWebRunner();
        });
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    // ------------------------------------------------------------------------------ logging

    public static function info(string $message): void
    {
        Craft::getLogger()->log($message, Logger::LEVEL_INFO, self::LOG_CATEGORY);
    }

    public static function warning(string $message): void
    {
        Craft::getLogger()->log($message, Logger::LEVEL_WARNING, self::LOG_CATEGORY);
    }

    public static function error(string $message): void
    {
        Craft::getLogger()->log($message, Logger::LEVEL_ERROR, self::LOG_CATEGORY);
    }

    // ------------------------------------------------------------------------------- setup

    /**
     * Adopt what PWA already has, on the way in.
     *
     * Here rather than in the migration because project-config writes from a migration are
     * buffered and can land before the plugin's own row exists, and because the adoption needs
     * PWA's *services*, which are only reachable once the plugin container is up.
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            return;
        }

        $this->interop->adoptFromPwa();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('schedulr', 'Schedulr');

        $user = Craft::$app->getUser();

        if ($user->checkPermission(self::PERMISSION_VIEW_NOTIFICATIONS)) {
            $item['subnav']['notifications'] = [
                'label' => Craft::t('schedulr', 'Notifications'),
                'url' => 'schedulr/notifications',
            ];
            $item['subnav']['schedule'] = [
                'label' => Craft::t('schedulr', 'Schedule'),
                'url' => 'schedulr/schedule',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_SUBSCRIBERS)) {
            $item['subnav']['subscribers'] = [
                'label' => Craft::t('schedulr', 'Subscribers'),
                'url' => 'schedulr/subscribers',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_AUDIENCES)) {
            $item['subnav']['audiences'] = [
                'label' => Craft::t('schedulr', 'Audiences'),
                'url' => 'schedulr/audiences',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_ANALYTICS)) {
            $item['subnav']['reports'] = [
                'label' => Craft::t('schedulr', 'Reports'),
                'url' => 'schedulr/reports',
            ];
        }

        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('schedulr', 'Settings'),
                'url' => 'schedulr/settings',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Schedulr's settings are several screens, not one pane.
     *
     * Never a redirect to `settings/plugins/schedulr` — that *is* the URL Craft renders
     * `settingsHtml()` at, so overriding it that way is an infinite redirect that answers 302
     * forever and never renders.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('schedulr/settings'));
    }

    // --------------------------------------------------------------------------- wiring

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Notification::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'schedulr' => 'schedulr/notifications/index',
                'schedulr/notifications' => 'schedulr/notifications/index',
                'schedulr/notifications/new' => 'schedulr/notifications/edit',
                'schedulr/notifications/<notificationId:\d+>' => 'schedulr/notifications/edit',
                'schedulr/notifications/<notificationId:\d+>/deliveries' => 'schedulr/reports/deliveries',
                'schedulr/schedule' => 'schedulr/schedule/index',
                'schedulr/subscribers' => 'schedulr/subscribers/index',
                'schedulr/subscribers/export' => 'schedulr/subscribers/export',
                'schedulr/subscribers/<subscriberId:\d+>' => 'schedulr/subscribers/detail',
                'schedulr/audiences' => 'schedulr/audiences/index',
                'schedulr/audiences/new' => 'schedulr/audiences/edit',
                'schedulr/audiences/<audienceId:\d+>' => 'schedulr/audiences/edit',
                'schedulr/reports' => 'schedulr/reports/index',
                'schedulr/reports/export' => 'schedulr/reports/export',
                'schedulr/settings' => 'schedulr/settings/index',
                'schedulr/settings/general' => 'schedulr/settings/general',
                'schedulr/settings/prompt' => 'schedulr/settings/prompt',
                'schedulr/settings/push' => 'schedulr/settings/push',
                'schedulr/settings/email' => 'schedulr/settings/email',
                'schedulr/settings/on-site' => 'schedulr/settings/on-site',
                'schedulr/settings/delivery' => 'schedulr/settings/delivery',
                'schedulr/settings/privacy' => 'schedulr/settings/privacy',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            // Documented, stable URLs as well as the action routes, so a site can build its own
            // front end against a URL that does not contain the word "actions".
            $event->rules['schedulr/heartbeat'] = 'schedulr/subscribe/heartbeat';
            $event->rules['schedulr/subscribe'] = 'schedulr/subscribe/subscribe';
            $event->rules['schedulr/unsubscribe'] = 'schedulr/subscribe/unsubscribe';
            $event->rules['schedulr/inbox.json'] = 'schedulr/inbox/index';
            $event->rules['schedulr/go'] = 'schedulr/track/click';

            // The worker has to be served from the site root, or its scope covers only the
            // directory it sits in and it can never control the pages that matter.
            $path = ltrim($settings->serviceWorkerPath, '/');

            if ($path !== '') {
                $event->rules[$path] = 'schedulr/worker/index';
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('schedulr', 'Schedulr'),
                'permissions' => [
                    self::PERMISSION_VIEW_NOTIFICATIONS => [
                        'label' => Craft::t('schedulr', 'View notifications'),
                        'nested' => [
                            self::PERMISSION_MANAGE_NOTIFICATIONS => [
                                'label' => Craft::t('schedulr', 'Create and edit notifications'),
                                // Writing a notification and *sending* one are different acts.
                                // A draft can be reviewed; a send cannot be recalled from a
                                // hundred thousand lock screens.
                                'nested' => [
                                    self::PERMISSION_SEND_NOTIFICATIONS => [
                                        'label' => Craft::t('schedulr', 'Send and schedule notifications'),
                                    ],
                                ],
                            ],
                            self::PERMISSION_DELETE_NOTIFICATIONS => [
                                'label' => Craft::t('schedulr', 'Delete notifications'),
                            ],
                            self::PERMISSION_VIEW_ANALYTICS => [
                                'label' => Craft::t('schedulr', 'View reports'),
                            ],
                        ],
                    ],
                    // Deliberately not nested under notifications. The subscriber list is a list
                    // of identifiable people's browsers, addresses and habits; writing an
                    // announcement is not.
                    self::PERMISSION_VIEW_SUBSCRIBERS => [
                        'label' => Craft::t('schedulr', 'View subscribers'),
                        'nested' => [
                            self::PERMISSION_MANAGE_SUBSCRIBERS => [
                                'label' => Craft::t('schedulr', 'Edit and remove subscribers'),
                            ],
                            self::PERMISSION_EXPORT_SUBSCRIBERS => [
                                'label' => Craft::t('schedulr', 'Export subscribers'),
                            ],
                            self::PERMISSION_MANAGE_AUDIENCES => [
                                'label' => Craft::t('schedulr', 'Manage audiences'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerSystemMessages(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function(RegisterEmailMessagesEvent $event) {
            foreach (Notifications::systemMessages() as $message) {
                $event->messages[] = $message;
            }
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('schedulr', SchedulrVariable::class);
        });
    }

    private function registerRuntimeInjection(): void
    {
        if (!Craft::$app->getRequest()->getIsSiteRequest() || !$this->getSettings()->injectRuntime) {
            return;
        }

        (new Injector())->attach();
    }

    /**
     * The web fallback runner, WP-Cron shaped.
     *
     * Hooked to the *end* of a request so a visitor never waits on it, behind a mutex and a
     * minimum interval so a burst of traffic cannot start twenty of them.
     */
    private function registerWebRunner(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        if ($this->getSettings()->runnerMode !== Settings::RUNNER_AUTO) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            $this->runner->runFromWeb();
        });
    }

    /**
     * Two housekeeping tasks, hooked to Craft's own garbage collection.
     *
     * Both are queued rather than inline: pruning a year of delivery rows inside somebody's page
     * load is not a trade worth making.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $settings = $this->getSettings();

            if ($settings->ledgerRetentionDays > 0 || $settings->subscriberRetentionDays > 0) {
                Queue::push(new PruneLedger([
                    'ledgerDays' => $settings->ledgerRetentionDays,
                    'subscriberDays' => $settings->subscriberRetentionDays,
                ]));
            }
        });
    }
}
