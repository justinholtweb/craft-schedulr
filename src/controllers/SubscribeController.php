<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Analytics;
use justinholtweb\schedulr\services\Subscribers;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The public endpoints the runtime talks to.
 *
 * All of them are unauthenticated and CSRF-exempt, which is a decision that needs justifying rather
 * than assuming:
 *
 * - **Unauthenticated** because a visitor who has never signed in is exactly who this is for.
 * - **CSRF-exempt** because the service worker calling `pushsubscriptionchange` has no page, no
 *   session and therefore no token — and because injecting a per-session token into every HTML
 *   response poisons every full-page cache in front of the site.
 *
 * What replaces CSRF is that none of these endpoints can do anything harmful, plus two cheap guards:
 * the runtime endpoints only answer requests a cross-site page cannot forge without a preflight
 * (`Subscribers::isRuntimeRequest()`), and every one that can create a row is rate-limited per IP.
 * The worst a forged request achieves is recording a visit that did not happen or unsubscribing a
 * browser whose endpoint the attacker already knows — and knowing the endpoint means being able to
 * unsubscribe it directly with the push service anyway.
 *
 * The email unsubscribe is the exception that carries its own authentication — a signed token — and
 * so is exempt from the runtime check: a mail provider's RFC 8058 one-click POST comes from its own
 * servers as a form post.
 */
class SubscribeController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Called on every page load: records the visit and answers the runtime's questions.
     *
     * One request per page load, not three. The heartbeat, the prompt decision and the on-site inbox
     * are all answered together because they all need the same subscriber row, and three round trips
     * to fetch it three times is three times the latency for no more information.
     */
    public function actionHeartbeat(): Response
    {
        $this->requirePostRequest();
        $this->requireRuntimeRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->subscribers->throttle('heartbeat', Subscribers::RATE_HEARTBEAT)) {
            return $this->tooManyRequests();
        }

        // Deliberately **not** `requireAcceptsJson()`. This endpoint answers JSON whatever the request
        // asked for, so requiring the header buys nothing and costs everything: a client that omits it
        // gets a 400, and the runtime is then silently dead on every page of the site while the same
        // request from curl works perfectly.
        $body = $this->body();

        $visitorId = (string)($body['visitorId'] ?? '');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $subscriber = $plugin->subscribers->touch(
            $visitorId,
            $siteId,
            $this->cleanTimezone($body['timezone'] ?? null),
            isset($body['language']) ? (string)$body['language'] : null,
            // A fresh UUID per request is a new row per request. Returning visitors never reach this,
            // so the budget is spent only on rows that would be created.
            static fn() => $plugin->subscribers->throttle('visitor', Subscribers::RATE_NEW_VISITOR),
        );

        if ($subscriber === null) {
            // A malformed visitor ID, or a new one over the creation limit. Answering 200 with nothing useful rather than an error, because
            // the runtime's correct response is to carry on and try again next page — and a 400 in
            // the console on every page load is a support ticket.
            return $this->asJson(['ok' => false]);
        }

        if (!empty($body['declined'])) {
            $plugin->subscribers->decline($visitorId, $siteId);
            $subscriber = $plugin->subscribers->getById($subscriber->id) ?? $subscriber;
        }

        return $this->asJson([
            'ok' => true,
            'subscribed' => $subscriber->isPushable(),
            'mayPrompt' => $subscriber->dateDeclined === null || $this->reaskDue($subscriber->dateDeclined),
            // The server's word beats the browser's memory: a device retired as `410 gone` still holds
            // a local subscription object and would otherwise never re-subscribe.
            // Never for someone who unsubscribed from everything: the subscribe that followed could not
            // make them pushable, and the runtime would then re-subscribe on every page load forever.
            'resubscribe' => !$subscriber->unsubscribed
                && !$subscriber->isPushable()
                && ($body['permission'] ?? null) === 'granted',
            'inbox' => $plugin->getSettings()->onSiteRender
                ? $plugin->deliveries->inboxFor($subscriber->id)
                : [],
        ]);
    }

    /**
     * Records a push subscription.
     */
    public function actionSubscribe(): Response
    {
        $this->requirePostRequest();
        $this->requireRuntimeRequest();

        if (!Plugin::getInstance()->subscribers->throttle('subscribe', Subscribers::RATE_SUBSCRIBE)) {
            return $this->tooManyRequests();
        }

        $body = $this->body();
        $subscription = $body['subscription'] ?? null;

        if (!is_array($subscription)) {
            return $this->asJson(['ok' => false, 'error' => 'No subscription supplied.']);
        }

        $plugin = Plugin::getInstance();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // A worker calling this from `pushsubscriptionchange` has no visitor ID — it has no page and
        // no localStorage — so it sends the endpoint it is replacing instead, and the row is found
        // that way.
        $visitorId = (string)($body['visitorId'] ?? '');
        $previous = trim((string)($body['previousEndpoint'] ?? ''));

        if ($visitorId === '' && $previous !== '') {
            $existing = $plugin->subscribers->getByEndpointHash(hash('sha256', $previous), $siteId);
            $visitorId = $existing->visitorId ?? '';
        }

        $subscriber = $plugin->subscribers->subscribe(
            $visitorId,
            $subscription,
            $siteId,
            $this->cleanTimezone($body['timezone'] ?? null),
            isset($body['language']) ? (string)$body['language'] : null,
        );

        if ($subscriber === null) {
            return $this->asJson(['ok' => false, 'error' => 'The subscription could not be recorded.']);
        }

        return $this->asJson(['ok' => true, 'subscribed' => $subscriber->isPushable()]);
    }

    /**
     * Forgets a push subscription, or unsubscribes from everything.
     *
     * Two shapes, because it answers both a JavaScript call and a link in an email. The link has to
     * work with no JavaScript and no session — but a GET on it only renders a confirmation with a
     * one-button form, and the unsubscribe itself is a POST. Link scanners, corporate mail filters and
     * "safe browsing" previews fetch every URL in a message; a GET that acted would unsubscribe people
     * who never touched the link. Gmail and Outlook's one-click unsubscribe is an RFC 8058 POST
     * (`List-Unsubscribe=One-Click`) to the same URL, which is the same POST branch.
     */
    public function actionUnsubscribe(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        // The signed link from an email. Signed rather than carrying a bare ID, so one person's
        // footer link cannot be edited into a link that unsubscribes somebody else.
        $token = $request->getParam('sr_u');

        if (is_string($token) && $token !== '') {
            $id = $plugin->subscribers->subscriberIdFromUnsubscribeToken($token);

            if ($id === null) {
                throw new BadRequestHttpException('That unsubscribe link is not valid.');
            }

            $variables = [
                'siteName' => Craft::$app->getSites()->getCurrentSite()->getName(),
                'token' => $token,
                'actionUrl' => UrlHelper::siteUrl('schedulr/unsubscribe'),
                'confirmed' => false,
            ];

            if (!$request->getIsPost()) {
                return $this->renderTemplate('schedulr/_unsubscribed', $variables, View::TEMPLATE_MODE_CP);
            }

            $subscriber = $plugin->subscribers->getById($id);

            if ($subscriber !== null) {
                $plugin->subscribers->unsubscribeAll($subscriber->id);
                $plugin->analytics->record(
                    Analytics::EVENT_UNSUBSCRIBED,
                    null,
                    subscriberId: $subscriber->id,
                    channel: 'email',
                );
            }

            if ($request->getAcceptsJson()) {
                return $this->asJson(['ok' => true]);
            }

            return $this->renderTemplate('schedulr/_unsubscribed', ['confirmed' => true] + $variables, View::TEMPLATE_MODE_CP);
        }

        $this->requirePostRequest();
        $this->requireRuntimeRequest();

        $body = $this->body();
        $endpoint = trim((string)($body['endpoint'] ?? ''));

        if ($endpoint === '') {
            return $this->asJson(['ok' => false]);
        }

        $plugin->subscribers->unsubscribePush($endpoint, Craft::$app->getSites()->getCurrentSite()->id);

        return $this->asJson(['ok' => true]);
    }

    // -------------------------------------------------------------------------- internals

    /**
     * Refuses a request a cross-site page could have forged. See `Subscribers::isRuntimeRequest()`.
     *
     * The runtime and the worker both send `Content-Type: application/json`, so this costs legitimate
     * traffic nothing.
     */
    private function requireRuntimeRequest(): void
    {
        if (!Plugin::getInstance()->subscribers->isRuntimeRequest()) {
            throw new BadRequestHttpException('Expected a JSON request from this site.');
        }
    }

    private function tooManyRequests(): Response
    {
        $response = $this->asJson(['ok' => false, 'error' => 'Too many requests.']);
        $response->setStatusCode(429);

        return $response;
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

    /**
     * A time zone name the browser reported, checked before it is stored.
     *
     * Attacker-controlled and later used to build a `DateTimeZone`, so it is validated against PHP's
     * own list rather than merely truncated — a stored string that cannot be constructed is a
     * scheduled send that throws for every subscriber in that "zone".
     */
    private function cleanTimezone(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 64) {
            return null;
        }

        return in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    private function reaskDue(\DateTime $declined): bool
    {
        $days = Plugin::getInstance()->getSettings()->promptReaskDays;

        if ($days <= 0) {
            return false;
        }

        return (int)$declined->diff(new \DateTime())->days >= $days;
    }
}
