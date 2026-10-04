<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\Plugin;
use Throwable;
use yii\db\Query;

/**
 * Standing aside for PWA.
 *
 * Schedulr installs and works alone. But a site running **both** plugins has one browser, one
 * permission grant and — this is the part that decides everything else — **one service worker
 * registration per scope**, and a push subscription belongs to a *registration*, not to a site.
 * Two independent push stacks on one origin is not a degraded experience, it is a broken one:
 * whichever registered last owns the subscription and the other one's list is silently dead.
 *
 * So when PWA is present and `deferToPwa` is on, Schedulr borrows three things:
 *
 * - **The keypair.** A second VAPID pair would orphan every subscription PWA already holds.
 * - **The subscribers**, copied in once on install, so the visitor is never prompted twice.
 * - **The registration.** Schedulr does not register a worker at all; its runtime subscribes
 *   through `navigator.serviceWorker.ready`, which is PWA's registration.
 *
 * The third of those works with no changes to PWA whatsoever, because PWA's worker renders
 * `{title, body, icon, badge, tag, requireInteraction, url}` and opens `data.url` on click — which
 * is exactly Schedulr's payload shape. Click tracking survives too, because Schedulr tracks
 * clicks by putting its redirect in `url` rather than by asking the worker to report anything.
 *
 * Every read of PWA goes through this class and is wrapped, because PWA can be uninstalled
 * between one request and the next and nothing here is worth taking a site down for.
 */
class Interop extends Component
{
    public const PWA_HANDLE = 'pwa';

    private ?bool $pwaActive = null;

    /** @var array{publicKey: string, privateKey: string}|false|null */
    private array|false|null $pwaKeys = null;

    /**
     * Whether PWA is installed, enabled, and Schedulr is set to defer to it.
     */
    public function isPwaActive(): bool
    {
        if ($this->pwaActive !== null) {
            return $this->pwaActive;
        }

        if (!Plugin::getInstance()->getSettings()->deferToPwa) {
            return $this->pwaActive = false;
        }

        try {
            $plugin = Craft::$app->getPlugins()->getPlugin(self::PWA_HANDLE);
        } catch (Throwable) {
            return $this->pwaActive = false;
        }

        return $this->pwaActive = $plugin !== null
            && Craft::$app->getPlugins()->isPluginEnabled(self::PWA_HANDLE);
    }

    /**
     * PWA's VAPID pair, or null when PWA is not in charge.
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    public function getPwaKeys(): ?array
    {
        if ($this->pwaKeys !== null) {
            return $this->pwaKeys === false ? null : $this->pwaKeys;
        }

        if (!$this->isPwaActive()) {
            $this->pwaKeys = false;

            return null;
        }

        try {
            $pwa = Craft::$app->getPlugins()->getPlugin(self::PWA_HANDLE);
            $push = $pwa->push;

            $public = (string)$push->getPublicKey();
            $private = (string)$push->getPrivateKey();

            if ($public === '' || $private === '') {
                $this->pwaKeys = false;

                return null;
            }

            return $this->pwaKeys = ['publicKey' => $public, 'privateKey' => $private];
        } catch (Throwable $e) {
            Plugin::warning('Could not read PWA’s VAPID keys, falling back to Schedulr’s own: ' . $e->getMessage());
            $this->pwaKeys = false;

            return null;
        }
    }

    /**
     * Whether Schedulr registers and serves its own service worker.
     *
     * False when PWA owns the registration. Registering ours over PWA's would replace it
     * outright — offline pages, caching and the install prompt all gone, with no error anywhere
     * to explain it.
     */
    public function ownsServiceWorker(): bool
    {
        if (!Plugin::getInstance()->getSettings()->registerServiceWorker) {
            return false;
        }

        if (!$this->isPwaActive()) {
            return true;
        }

        try {
            $pwa = Craft::$app->getPlugins()->getPlugin(self::PWA_HANDLE);
            $settings = $pwa->getSettings();

            // PWA installed but with its worker switched off leaves the scope free, so Schedulr
            // takes it — a site should not lose push because of a setting in a different plugin.
            return !($settings->enabled && $settings->serviceWorkerEnabled);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Copies PWA's push subscribers across, once.
     *
     * Called from `afterInstall`. Without it, a site that has spent a year building a push list
     * in PWA installs Schedulr and is shown an empty subscriber screen — which reads as "this
     * plugin doesn't work" rather than "these two tables are separate".
     *
     * Idempotent: keyed on the endpoint hash, so running it twice adopts nobody twice, and a
     * subscriber Schedulr already knows keeps its own visitor identity and history.
     *
     * @return int How many were adopted.
     */
    public function adoptFromPwa(): int
    {
        if (!$this->isPwaActive()) {
            return 0;
        }

        try {
            $rows = (new Query())
                ->select(['siteId', 'userId', 'endpoint', 'endpointHash', 'p256dh', 'auth', 'contentEncoding', 'userAgent', 'platform', 'dateLastSeen', 'dateCreated'])
                ->from('{{%pwa_subscribers}}')
                ->all();
        } catch (Throwable $e) {
            Plugin::warning('Could not read PWA’s subscribers: ' . $e->getMessage());

            return 0;
        }

        if ($rows === []) {
            return 0;
        }

        $existing = (new Query())
            ->select(['endpointHash'])
            ->from(Table::SUBSCRIBERS)
            ->where(['not', ['endpointHash' => null]])
            ->column();

        $existing = array_flip($existing);
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $now = Db::prepareDateForDb(new DateTime());
        $adopted = [];
        $refused = 0;
        $subscribers = Plugin::getInstance()->subscribers;

        foreach ($rows as $row) {
            // Recomputed rather than trusted: Schedulr looks rows up by *its* hash of the endpoint, and
            // a row whose stored hash disagreed would be a subscriber nothing could ever find again.
            $endpoint = trim((string)$row['endpoint']);
            $hash = $endpoint !== '' ? hash('sha256', $endpoint) : '';

            if ($hash === '' || isset($existing[$hash])) {
                continue;
            }

            // PWA's table is filled by PWA's own public subscribe endpoint, whose checks Schedulr does
            // not control. Adopting a row is storing a URL this server will POST to, so it passes the
            // same allowlist a fresh subscription does.
            if (!$subscribers->isAcceptableEndpoint($endpoint)) {
                $refused++;

                continue;
            }

            $existing[$hash] = true;

            $adopted[] = [
                'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : $primarySiteId,
                'userId' => $row['userId'] !== null ? (int)$row['userId'] : null,
                // A brand new visitor identity: PWA has no equivalent, and inventing one from the
                // endpoint would mean the same person looked like two visitors if they ever
                // resubscribed.
                'visitorId' => StringHelper::UUID(),
                'endpoint' => $endpoint,
                'endpointHash' => $hash,
                'p256dh' => (string)$row['p256dh'],
                'auth' => (string)$row['auth'],
                'contentEncoding' => (string)($row['contentEncoding'] ?: 'aes128gcm'),
                'userAgent' => $row['userAgent'] !== null ? substr((string)$row['userAgent'], 0, 500) : null,
                'platform' => $row['platform'] !== null ? (string)$row['platform'] : null,
                'visits' => 1,
                'dateFirstSeen' => $row['dateCreated'] ?: $now,
                'dateLastSeen' => $row['dateLastSeen'] ?: $now,
                'dateSubscribed' => $row['dateCreated'] ?: $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ];
        }

        if ($refused > 0) {
            Plugin::warning('Did not adopt ' . $refused . ' PWA subscriber(s) whose endpoint is not on a recognised push service.');
        }

        if ($adopted === []) {
            return 0;
        }

        Craft::$app->getDb()->createCommand()->batchInsert(
            Table::SUBSCRIBERS,
            array_keys($adopted[0]),
            array_map('array_values', $adopted),
        )->execute();

        Plugin::info('Adopted ' . count($adopted) . ' push subscriber(s) from PWA.');

        return count($adopted);
    }

    /**
     * What the front-end runtime needs to know about who owns the worker.
     *
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        return [
            'ownsWorker' => $this->ownsServiceWorker(),
            'pwa' => $this->isPwaActive(),
        ];
    }

    /** For the settings screen, which should explain itself rather than look mysteriously inert. */
    public function status(): array
    {
        $active = $this->isPwaActive();

        return [
            'installed' => $active,
            'keys' => $active && $this->getPwaKeys() !== null,
            'worker' => $active && !$this->ownsServiceWorker(),
            'subscribers' => $active ? $this->countPwaSubscribers() : 0,
        ];
    }

    private function countPwaSubscribers(): int
    {
        try {
            return (int)(new Query())->from('{{%pwa_subscribers}}')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Json is imported for the runtime config the injector serialises; kept explicit for phpstan. */
    public function encodeRuntimeConfig(): string
    {
        return Json::encode($this->runtimeConfig());
    }
}
