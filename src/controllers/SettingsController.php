<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\push\Encryptor;
use yii\web\Response;

/**
 * Settings, across seven panes.
 *
 * Every pane posts to the same `save` action and each carries only its own fields, which is why the save
 * merges into the existing settings rather than replacing them: a partial post that replaced the model
 * would silently reset every field the current pane does not show.
 *
 * Note that nothing in the settings model is `required`. A fresh install must be able to save any pane
 * without first filling in a credential, or `savePluginSettings()` fails validation wholesale and the
 * screen becomes unusable — including the screen you would use to fix it.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        // Admin, but not "admin changes allowed": the panes stay viewable on an environment where
        // `allowAdminChanges` is off and render read-only there. Each write re-checks strictly.
        $this->requireAdmin(false);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('schedulr/settings/general');
    }

    public function actionGeneral(): Response
    {
        return $this->pane('general');
    }

    public function actionPrompt(): Response
    {
        return $this->pane('prompt');
    }

    public function actionPush(): Response
    {
        return $this->pane('push');
    }

    public function actionEmail(): Response
    {
        return $this->pane('email');
    }

    public function actionOnSite(): Response
    {
        return $this->pane('on-site');
    }

    public function actionDelivery(): Response
    {
        return $this->pane('delivery');
    }

    public function actionPrivacy(): Response
    {
        return $this->pane('privacy');
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!is_array($posted)) {
            $posted = [];
        }

        $this->applyPosted($settings, $posted);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('schedulr', 'Couldn’t save settings.'));

            // Re-rendered with the same model, so the posted values survive and every field can show
            // its own errors.
            $pane = (string)Craft::$app->getRequest()->getBodyParam('pane', 'general');
            $panes = self::panes();

            if (!isset($panes[$pane])) {
                $pane = 'general';
            }

            return $this->pane($pane, $settings);
        }

        Craft::$app->getSession()->setNotice(Craft::t('schedulr', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Replaces the VAPID keypair. Refuses when PWA owns the keys.
     */
    public function actionRotateKeys(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();

        if ($plugin->interop->getPwaKeys() !== null) {
            return $this->asFailure(Craft::t('schedulr', 'PWA owns the keys on this site. Rotate them in PWA.'));
        }

        $drop = (bool)Craft::$app->getRequest()->getBodyParam('dropSubscribers', true);
        $plugin->keys->rotate($drop);

        return $this->asSuccess(Craft::t('schedulr', 'A new keypair has been generated. Every device will need to subscribe again.'));
    }

    /**
     * Adopts PWA's subscribers by hand.
     *
     * Also done automatically on install; this exists for the site that installed Schedulr *first* and
     * PWA afterwards, where the automatic pass found nothing.
     */
    public function actionAdoptPwa(): Response
    {
        $this->requirePostRequest();

        $count = Plugin::getInstance()->interop->adoptFromPwa();

        return $this->asSuccess(Craft::t('schedulr', 'Adopted {count} subscriber(s) from PWA.', ['count' => $count]));
    }

    /**
     * Sends a push to one device, to prove the whole chain works.
     *
     * The most valuable button on the settings screen, because web push fails silently: a push service
     * returns 201 for a message it will deliver *and* for one whose encryption is subtly wrong. A test
     * send is the only way to find out which without waiting for a campaign.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscriberId = (int)Craft::$app->getRequest()->getBodyParam('subscriberId', 0);

        $subscriber = $subscriberId > 0
            ? $plugin->subscribers->getById($subscriberId)
            : ($plugin->subscribers->findAll(['pushable' => true], 0, 1)[0] ?? null);

        if ($subscriber === null) {
            return $this->asFailure(Craft::t('schedulr', 'There are no push subscribers yet. Visit the site and allow notifications first.'));
        }

        $notification = new \justinholtweb\schedulr\elements\Notification();
        $notification->title = Craft::t('schedulr', 'Schedulr test');
        $notification->body = Craft::t('schedulr', 'If you can read this, push is working.');
        $notification->url = \craft\helpers\UrlHelper::siteUrl('/');

        $result = $plugin->sender->getChannel('push')?->send($notification, $subscriber);

        if ($result === null) {
            return $this->asFailure(Craft::t('schedulr', 'The push channel is unavailable.'));
        }

        if (!$result->isSuccess()) {
            return $this->asFailure(Craft::t('schedulr', 'The push service said: {error} ({code})', [
                'error' => $result->error ?: Craft::t('schedulr', 'no detail'),
                'code' => $result->statusCode ?? Craft::t('schedulr', 'no status'),
            ]));
        }

        return $this->asSuccess(Craft::t('schedulr', 'The push service accepted it. It should appear on that device shortly.'));
    }

    /**
     * Checks whether openssl can do what web push needs, before anybody wonders why nothing arrives.
     */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();

        $checks = [];

        $checks[] = [
            'label' => Craft::t('schedulr', 'openssl is available'),
            'ok' => extension_loaded('openssl'),
        ];

        $checks[] = [
            'label' => Craft::t('schedulr', 'P-256 keys can be generated'),
            'ok' => in_array('prime256v1', openssl_get_curve_names() ?: [], true),
        ];

        $ok = true;

        try {
            // Not a capability probe but an end-to-end one: this is the same code path a real send
            // takes, so if it throws here it would have thrown silently inside a queue job.
            $keys = Encryptor::generateKeys();
            Encryptor::vapidHeader('https://example.com/push/abc', 'mailto:test@example.com', $keys['publicKey'], $keys['privateKey']);
        } catch (\Throwable $e) {
            $ok = false;
        }

        $checks[] = [
            'label' => Craft::t('schedulr', 'A VAPID header can be signed'),
            'ok' => $ok,
        ];

        return $this->asJson(['checks' => $checks]);
    }

    // ---------------------------------------------------------------------------- internals

    /**
     * The seven panes, in sidebar order.
     *
     * @return array<string, string>
     */
    private static function panes(): array
    {
        return [
            'general' => Craft::t('schedulr', 'General'),
            'prompt' => Craft::t('schedulr', 'Opt-in prompt'),
            'push' => Craft::t('schedulr', 'Web push'),
            'email' => Craft::t('schedulr', 'Email'),
            'on-site' => Craft::t('schedulr', 'On-site'),
            'delivery' => Craft::t('schedulr', 'Delivery'),
            'privacy' => Craft::t('schedulr', 'Privacy'),
        ];
    }

    /**
     * Renders one pane. `$settings` is passed back in after a failed save, so the form keeps what was
     * posted and every field can show its own errors.
     */
    private function pane(string $handle, ?Settings $settings = null): Response
    {
        $plugin = Plugin::getInstance();
        $panes = self::panes();

        $extra = match ($handle) {
            'push' => [
                'keySource' => $plugin->keys->getSource(),
                'hasKeys' => $plugin->keys->hasKeys(),
                'publicKey' => $plugin->keys->hasKeys() ? $plugin->keys->getPublicKey() : null,
                'interop' => $plugin->interop->status(),
                'workerUrl' => $plugin->serviceWorker->scriptUrl(),
            ],
            'delivery' => ['health' => $plugin->runner->health()],
            'privacy' => ['stats' => $plugin->subscribers->stats()],
            default => [],
        };

        return $this->renderTemplate('schedulr/settings/_' . $handle, array_merge([
            // The pane's own name is the page title, so the header carries it once and the content
            // does not repeat it as a second h1.
            'title' => $panes[$handle] ?? Craft::t('schedulr', 'Settings'),
            'selectedPane' => $handle,
            'panes' => $panes,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'settings' => $settings ?? $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'promptStyles' => $this->promptStyleOptions($plugin->isPro()),
            'runnerModes' => Settings::runnerModeOptions(),
            'dedupeOptions' => Settings::dedupeOptions(),
            'positions' => Settings::onSitePositionOptions(),
        ], $extra));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function promptStyleOptions(bool $isPro): array
    {
        $out = [];

        foreach (Settings::promptStyleOptions() as $value => $label) {
            $allowed = Edition::promptStyleAllowed($value, $isPro);

            $out[] = [
                'value' => $value,
                'label' => $allowed ? $label : Craft::t('schedulr', '{style} (requires Pro)', ['style' => $label]),
                'disabled' => !$allowed,
            ];
        }

        return $out;
    }

    /**
     * Copies posted values onto the settings model, one property at a time.
     *
     * Cast against each property's declared type rather than assigned raw, because a CP number field
     * posts an **empty string** when cleared and a typed `int` property assigned `''` is a `TypeError`,
     * not a zero — so an author emptying a field would fatal the save.
     *
     * @param array<string, mixed> $posted
     */
    private function applyPosted(Settings $settings, array $posted): void
    {
        $reflection = new \ReflectionObject($settings);

        foreach ($posted as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);

            if (!$property->isPublic()) {
                continue;
            }

            $type = $property->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            // The colour setter normalises Craft's missing `#`; going through the property directly
            // would store `C7278C` and fail the pattern rule.
            if ($name === 'accentColor') {
                $settings->setAccentColor((string)$value);

                continue;
            }

            if ($typeName === 'array') {
                if (is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])));
                }

                $settings->$name = is_array($value) ? $value : [];

                continue;
            }

            if ($typeName === 'int') {
                // Left at its default rather than zeroed. An author who cleared the field wants the
                // sensible value back, not "0 seconds" or "no retention".
                if ($value === '' || $value === null || !is_numeric($value)) {
                    continue;
                }

                $settings->$name = (int)$value;

                continue;
            }

            if ($typeName === 'bool') {
                $settings->$name = (bool)$value;

                continue;
            }

            if ($typeName === 'string') {
                $settings->$name = (string)$value;
            }
        }
    }
}
