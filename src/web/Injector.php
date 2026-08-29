<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\Plugin;
use yii\base\Event;
use yii\web\Response;

/**
 * Puts the runtime on the page.
 *
 * Injection rather than an asset bundle, because the runtime must be present on pages a site's
 * templates were never edited for — including cached ones — and because `{{ craft.schedulr.runtime }}`
 * being required in every layout is a support burden and a reason the plugin "doesn't work".
 *
 * Three traps are handled here, each of which cost somebody a day in a sibling plugin:
 *
 * 1. **Craft does not use `Response::FORMAT_HTML` for site templates.** It has its own
 *    `craft\web\TemplateResponseFormatter::FORMAT` (the string `template`), so a filter guarded on
 *    `format !== FORMAT_HTML` matches *nothing* and does nothing, silently. The `Content-Type` header
 *    is tested instead — which is also the correct test, because that formatter takes the MIME type
 *    from the template's extension, so `feed.rss.twig` and `manifest.json.twig` are template
 *    responses that must be left alone.
 * 2. **`sendContentLengthHeader` stamps `content-length` during `prepare()`**, i.e. *before*
 *    `EVENT_AFTER_PREPARE`. Lengthening the body without restamping truncates the page at exactly the
 *    byte the injection started, which presents as a broken template rather than a broken header.
 * 3. **No per-session token is injected.** A cache-varying value in an HTML response poisons every
 *    full-page cache in front of the site, so the visitor identity is generated in the browser and
 *    the endpoints do not use CSRF at all.
 */
class Injector
{
    public function attach(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            /** @var Response $response */
            $response = $event->sender;

            $this->inject($response);
        });
    }

    private function inject(Response $response): void
    {
        if ($response->getIsEmpty() || !$response->getIsSuccessful()) {
            return;
        }

        $contentType = (string)$response->getHeaders()->get('content-type');

        if (!str_contains(strtolower($contentType), 'text/html')) {
            return;
        }

        $html = (string)$response->content;

        if ($html === '' || !str_contains($html, '</body>')) {
            return;
        }

        if ($this->isExcluded()) {
            return;
        }

        $markup = $this->markup();

        if ($markup === '') {
            return;
        }

        // The *last* `</body>`, in case one appears inside an inlined SVG or a code sample earlier in
        // the document.
        $position = strrpos($html, '</body>');

        if ($position === false) {
            return;
        }

        $response->content = substr($html, 0, $position) . $markup . substr($html, $position);

        // Restamped, not removed: removing it makes a keep-alive connection hang waiting for bytes
        // that are never coming.
        $headers = $response->getHeaders();

        if ($headers->has('content-length')) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }

    private function isExcluded(): bool
    {
        $patterns = Plugin::getInstance()->getSettings()->excludedUris;

        if ($patterns === []) {
            return false;
        }

        $uri = Craft::$app->getRequest()->getFullPath();

        foreach ($patterns as $pattern) {
            $pattern = trim((string)$pattern);

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $uri) {
                return true;
            }

            // Glob-ish, not regex. An author writing `blog/*` should not have to know that `*` means
            // "zero or more of the preceding token" somewhere else.
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

            if (preg_match($regex, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `<script>` and `<style>` the runtime needs.
     *
     * Inlined rather than linked. Two extra requests on every page load to deliver four kilobytes is a
     * bad trade, and a linked file is a file a strict CSP has to be told about.
     */
    public function markup(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $config = $this->config();

        if ($config === null) {
            return '';
        }

        $runtime = @file_get_contents(dirname(__DIR__) . '/resources/runtime/schedulr.js');
        $css = @file_get_contents(dirname(__DIR__) . '/resources/runtime/schedulr.css');

        if ($runtime === false) {
            return '';
        }

        $out = '';

        if ($css !== false && $settings->accentColor !== '') {
            // The accent is applied with a custom property on the two roots rather than by rewriting
            // the stylesheet, so a site can still override it from its own CSS.
            $css .= sprintf(
                "\n.schedulr-prompt,.schedulr-toast{--schedulr-accent:%s;}\n",
                $settings->accentColor,
            );
        }

        if ($css !== false) {
            $out .= Html::style($css);
        }

        // A `<script>` element holds raw text — HTML entities in it are never decoded — so the config
        // is carried as JSON rather than escaped markup.
        $out .= Html::script('window.__SCHEDULR__=' . Json::encode($config) . ';');
        $out .= Html::script($runtime);

        return $out;
    }

    /**
     * What the runtime is told.
     *
     * Everything here is public: it lands in the source of every page. Nothing may be added to it that
     * varies per visitor, or the page stops being cacheable.
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $isPro = $plugin->isPro();

        $style = $settings->promptStyle;

        if (!Edition::promptStyleAllowed($style, $isPro)) {
            // A downgrade, not a silent removal: a lapsed licence keeps prompting, in the nearest
            // style Lite has. A prompt that vanishes on renewal day looks exactly like a bug.
            $style = Settings::PROMPT_BELL;
        }

        try {
            $publicKey = $plugin->keys->getPublicKey();
        } catch (\Throwable $e) {
            Plugin::error('Could not read the VAPID public key: ' . $e->getMessage());

            return null;
        }

        return [
            'heartbeatUrl' => UrlHelper::siteUrl('schedulr/heartbeat'),
            'subscribeUrl' => UrlHelper::siteUrl('schedulr/subscribe'),
            'unsubscribeUrl' => UrlHelper::siteUrl('schedulr/unsubscribe'),
            'eventUrl' => UrlHelper::actionUrl('schedulr/track/event', null, null, false),
            'workerUrl' => $plugin->serviceWorker->scriptUrl(),
            'ownsWorker' => $plugin->interop->ownsServiceWorker(),
            'vapidPublicKey' => $publicKey,
            'promptStyle' => $style,
            'promptAfterViews' => $settings->promptAfterViews,
            'promptAfterSeconds' => $settings->promptAfterSeconds,
            'promptReaskDays' => $settings->promptReaskDays,
            'promptHeading' => $settings->promptHeading,
            'promptBody' => $settings->promptBody,
            'promptAccept' => $settings->promptAccept,
            'promptDecline' => $settings->promptDecline,
            'onSiteRender' => $settings->onSiteRender,
            'onSitePosition' => $settings->onSitePosition,
            'onSiteAutoDismiss' => $settings->onSiteAutoDismiss,
        ];
    }
}
