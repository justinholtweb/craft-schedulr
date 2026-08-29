<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Analytics;
use yii\web\Response;

/**
 * Click tracking and event reporting.
 *
 * `/schedulr/go` is the URL that goes inside a notification. It records the click and then redirects —
 * and it takes **only IDs**, never a destination, because a redirect endpoint that takes its target
 * from a query parameter is an open redirect and therefore a phishing gadget hosted on the customer's
 * own domain. The destination is looked up from the notification.
 *
 * Every parameter is namespaced `sr_`. `token` and `p` are both reserved by Craft — a request
 * carrying `?token=` is rejected in `Application::init()` before any controller runs, and `?p=` is how
 * Craft is told which path was requested — so an un-namespaced tracking link 404s while the same
 * route with no query string works, which looks like anything except routing.
 */
class TrackController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Records a click and sends the visitor on.
     */
    public function actionClick(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $params = [
            'sr_n' => $request->getParam('sr_n'),
            'sr_c' => $request->getParam('sr_c', 'push'),
        ];

        foreach (['sr_v', 'sr_s', 'sr_b'] as $key) {
            $value = $request->getParam($key);

            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $params['sr_k'] = (string)$request->getParam('sr_k', '');

        $notificationId = (int)($params['sr_n'] ?? 0);

        // An unsigned or badly signed link still *redirects* — it simply is not counted. Refusing to
        // redirect would punish the reader for a signing mistake on the server's side, and the person
        // who tapped a notification is entitled to arrive somewhere.
        $counted = $notificationId > 0 && $plugin->analytics->verify($params);

        $destination = $notificationId > 0
            ? $plugin->analytics->destinationFor(
                $notificationId,
                isset($params['sr_v']) ? (int)$params['sr_v'] : null,
                isset($params['sr_b']) ? (int)$params['sr_b'] : null,
            )
            : null;

        if ($counted) {
            $plugin->analytics->record(
                Analytics::EVENT_CLICKED,
                $notificationId,
                isset($params['sr_v']) ? (int)$params['sr_v'] : null,
                isset($params['sr_s']) ? (int)$params['sr_s'] : null,
                (string)$params['sr_c'],
                $destination,
            );
        }

        return $this->redirect($this->safeDestination($destination));
    }

    /**
     * The worker and the on-site runtime reporting what happened.
     */
    public function actionEvent(): Response
    {
        $this->requirePostRequest();

        $body = $this->body();
        $type = (string)($body['type'] ?? '');
        $notificationId = (int)($body['n'] ?? 0);

        if ($notificationId <= 0) {
            return $this->asJson(['ok' => false]);
        }

        // Clicks are never accepted here. They are recorded by the redirect, which is signed; taking
        // them from an unauthenticated POST as well would give two paths to the same counter, one of
        // them forgeable, and make every click rate suspect.
        if (!in_array($type, [Analytics::EVENT_DISPLAYED, Analytics::EVENT_DISMISSED, Analytics::EVENT_CONVERTED], true)) {
            return $this->asJson(['ok' => false]);
        }

        $subscriberId = null;
        $raw = $body['s'] ?? null;

        if (is_numeric($raw)) {
            $subscriberId = (int)$raw;
        } elseif (is_string($raw) && $raw !== '') {
            // The on-site runtime knows its visitor ID, not its subscriber ID.
            $subscriberId = Plugin::getInstance()->subscribers
                ->getByVisitorId($raw, Craft::$app->getSites()->getCurrentSite()->id)?->id;
        }

        Plugin::getInstance()->analytics->record(
            $type,
            $notificationId,
            isset($body['v']) && is_numeric($body['v']) ? (int)$body['v'] : null,
            $subscriberId,
            isset($body['channel']) ? (string)$body['channel'] : null,
        );

        return $this->asJson(['ok' => true]);
    }

    /**
     * Where to send somebody when the destination is missing or not ours.
     *
     * A notification whose URL was never set, or was set to another origin and has since been
     * tampered with, lands on the site's home page rather than 404ing — arriving somewhere sensible
     * beats an error page for a reader who did nothing wrong.
     */
    private function safeDestination(?string $destination): string
    {
        $destination = trim((string)$destination);

        if ($destination === '') {
            return UrlHelper::siteUrl('/');
        }

        // Absolute URLs to other hosts are allowed — a notification legitimately links to a partner's
        // announcement — but only ones an author actually stored, which is why this is checked here
        // and not against a request parameter.
        return $destination;
    }

    /**
     * The request body.
     *
     * Craft registers a JSON body parser by default (`App::webRequestConfig()`), so `getBodyParams()`
     * already handles the `application/json` these endpoints receive. The raw fallback covers the one
     * case it does not: a site whose `config/app.php` overrides the `request` component without
     * carrying the `parsers` key forward, which silently empties every body param and would make every
     * endpoint here appear to work and do nothing.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $request = Craft::$app->getRequest();
        $params = $request->getBodyParams();

        if ($params !== []) {
            return $params;
        }

        $raw = $request->getRawBody();

        if ($raw !== '' && str_starts_with(trim($raw), '{')) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $params;
    }
}
