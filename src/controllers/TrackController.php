<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Analytics;
use justinholtweb\schedulr\services\Subscribers;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
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
        $counted = $notificationId > 0 && $plugin->analytics->verify($params);

        if (!$counted && !$this->mayRedirectUnsigned($notificationId)) {
            // An unsigned link to a notification that has never gone out is not a link anybody was
            // sent. Redirecting anyway would turn `/schedulr/go?sr_n=…` into a way to preview, and
            // be bounced to, the destination of a draft — a notification nobody has approved.
            throw new NotFoundHttpException();
        }

        // A badly signed link to a notification that *has* been sent still redirects — it simply is
        // not counted. The reader who tapped a notification is entitled to arrive somewhere, and a
        // signing change on the server's side (a rotated security key) must not strand them.
        $variantId = isset($params['sr_v']) ? (int)$params['sr_v'] : null;
        $buttonIndex = isset($params['sr_b']) ? (int)$params['sr_b'] : null;
        $destination = $plugin->analytics->destinationFor($notificationId, $variantId, $buttonIndex);

        if ($counted) {
            $plugin->analytics->record(
                Analytics::EVENT_CLICKED,
                $notificationId,
                $variantId,
                isset($params['sr_s']) ? (int)$params['sr_s'] : null,
                (string)$params['sr_c'],
                $destination,
            );
        }

        return $this->redirect($this->safeDestination($destination));
    }

    /**
     * The worker and the on-site runtime reporting what happened.
     *
     * Unauthenticated, so it accepts only what it can attribute: the on-site runtime's visitor UUID —
     * a secret only that browser holds — or, from the worker, a subscriber ID together with the
     * signature the push payload carried for it. A bare numeric `s` is refused; it would let anybody
     * write events against every subscriber on the list by counting. Each (type, notification,
     * subscriber) is written once, so a replayed request grows nothing.
     */
    public function actionEvent(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->subscribers->isRuntimeRequest()) {
            throw new BadRequestHttpException('Expected a JSON request from this site.');
        }

        if (!$plugin->subscribers->throttle('event', Subscribers::RATE_EVENT)) {
            $response = $this->asJson(['ok' => false]);
            $response->setStatusCode(429);

            return $response;
        }

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

        $variantId = isset($body['v']) && is_numeric($body['v']) ? (int)$body['v'] : null;
        $subscriberId = null;
        $raw = $body['s'] ?? null;

        if (is_string($raw) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)) {
            // The on-site runtime knows its visitor ID, not its subscriber ID.
            $subscriberId = $plugin->subscribers
                ->getByVisitorId(strtolower($raw), Craft::$app->getSites()->getCurrentSite()->id)?->id;
        } elseif (is_numeric($raw) && is_string($body['k'] ?? null)) {
            $candidate = (int)$raw;

            if ($candidate > 0 && $plugin->analytics->verifyEventSignature($notificationId, $variantId, $candidate, $body['k'])) {
                $subscriberId = $candidate;
            }
        }

        if ($subscriberId === null) {
            // Unattributable. An anonymous event row is noise in every rate that divides by people,
            // and accepting them is the unbounded write this endpoint must not offer.
            return $this->asJson(['ok' => false]);
        }

        if ($plugin->notifications->getById($notificationId) === null) {
            return $this->asJson(['ok' => false]);
        }

        $channel = isset($body['channel']) && is_string($body['channel']) ? $body['channel'] : null;

        $plugin->analytics->recordOnce($type, $notificationId, $variantId, $subscriberId, $channel);

        return $this->asJson(['ok' => true]);
    }

    /**
     * Whether a link that does not verify may still be followed.
     *
     * Only for a notification that has actually gone out: then the link is plausibly one somebody was
     * sent before a key rotation, and the destination is already public.
     */
    private function mayRedirectUnsigned(int $notificationId): bool
    {
        if ($notificationId <= 0) {
            return false;
        }

        $notification = Plugin::getInstance()->notifications->getById($notificationId);

        if ($notification === null) {
            return false;
        }

        return $notification->dateLastSent !== null
            || $notification->delivered > 0
            || in_array($notification->state, [Notification::STATE_SENDING, Notification::STATE_SENT], true);
    }

    /**
     * Where to send somebody when the destination is missing or unusable.
     *
     * A notification whose URL was never set lands on the site's home page rather than 404ing —
     * arriving somewhere sensible beats an error page for a reader who did nothing wrong.
     */
    private function safeDestination(?string $destination): string
    {
        $destination = trim((string)$destination);

        // Absolute URLs to other hosts are allowed — a notification legitimately links to a partner's
        // announcement — but only ones an author actually stored, which is why this is checked here
        // and not against a request parameter. The scheme is checked again on the way out: a
        // `javascript:` destination stored before validation existed must not become a redirect.
        if (!Analytics::isSafeDestination($destination)) {
            return UrlHelper::siteUrl('/');
        }

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
