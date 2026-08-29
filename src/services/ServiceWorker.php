<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Site;
use justinholtweb\schedulr\Plugin;
use RuntimeException;

/**
 * Generates the service worker.
 *
 * The worker is a *file served at a URL*, not an asset bundle, for two reasons that both bite. Its
 * scope is the directory it is served from, so it has to be at the site root — a worker published
 * to `/cpresources/…` can only ever control `/cpresources/`, and push to it silently never
 * arrives. And it has to be byte-identical between requests, or the browser treats every load as a
 * new worker and reinstalls it forever; so the configuration is substituted into the script once
 * and cached, rather than fetched by the worker at runtime.
 */
class ServiceWorker extends Component
{
    private const PLACEHOLDER = '/*__SCHEDULR_CONFIG__*/ {}';

    /** The worker script for one site, configuration substituted in. */
    public function render(?Site $site = null): string
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();

        $script = @file_get_contents($this->scriptPath());

        if ($script === false) {
            throw new RuntimeException('Schedulr’s service worker script is missing.');
        }

        if (!str_contains($script, self::PLACEHOLDER)) {
            throw new RuntimeException('Schedulr’s service worker has no configuration placeholder.');
        }

        return str_replace(self::PLACEHOLDER, Json::encode($this->config($site)), $script);
    }

    /**
     * What the worker needs to know.
     *
     * Kept to the minimum, because every value here is public: the worker script is fetched
     * unauthenticated by every browser that visits.
     *
     * @return array<string, mixed>
     */
    public function config(Site $site): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [
            'version' => Plugin::getInstance()->getVersion(),
            'siteId' => $site->id,
            'defaultTitle' => $site->getName(),
            'homeUrl' => UrlHelper::siteUrl('/', null, null, $site->id),
            'defaultIcon' => $this->absolute($settings->defaultIcon, $site),
            'defaultBadge' => $this->absolute($settings->defaultBadge, $site),
            'vapidPublicKey' => Plugin::getInstance()->keys->getPublicKey(),
            'subscribeUrl' => UrlHelper::siteUrl('schedulr/subscribe', null, null, $site->id),
            'eventUrl' => UrlHelper::actionUrl('schedulr/track/event', null, null, false),
        ];
    }

    /**
     * The URL the worker is served from.
     *
     * Null when PWA owns the registration — there is no Schedulr worker on that site, and handing
     * the runtime a URL it must not register is how the accident happens.
     */
    public function scriptUrl(?Site $site = null): ?string
    {
        if (!Plugin::getInstance()->interop->ownsServiceWorker()) {
            return null;
        }

        $site ??= Craft::$app->getSites()->getCurrentSite();
        $path = ltrim(Plugin::getInstance()->getSettings()->serviceWorkerPath, '/');

        if ($path === '') {
            return null;
        }

        return UrlHelper::siteUrl($path, null, null, $site->id);
    }

    /**
     * A cache key that changes when anything the worker embeds changes.
     *
     * The keys are in here on purpose: a rotated keypair must produce a different worker, or every
     * browser keeps the old public key and re-subscribes to a pair the server no longer holds.
     */
    public function cacheKey(Site $site): string
    {
        return 'schedulr:sw:' . $site->id . ':' . md5(Json::encode($this->config($site)));
    }

    /**
     * Resolves a configured icon path against the site.
     *
     * A relative icon in a *worker* is resolved against the worker's own URL rather than the page,
     * which is nearly but not quite the same thing, and produces a notification with no icon on
     * exactly the sites that put their worker somewhere unusual.
     */
    private function absolute(string $value, Site $site): string
    {
        $value = trim(App::parseEnv($value) ?: '');

        if ($value === '' || preg_match('/^(https?:)?\/\//', $value)) {
            return $value;
        }

        return UrlHelper::siteUrl($value, null, null, $site->id);
    }

    private function scriptPath(): string
    {
        return dirname(__DIR__) . '/resources/sw.js';
    }
}
