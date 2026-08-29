<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the service worker.
 *
 * A route rather than a published asset, because a worker's scope is the path it is served from: one
 * published to `/cpresources/…` can only ever control `/cpresources/`, and push to it silently never
 * arrives with no error anywhere to explain why.
 *
 * The response is cached for a short period and keyed on the worker's own configuration, so a rotated
 * keypair produces different bytes immediately — otherwise every browser keeps re-subscribing with a
 * public key the server no longer holds.
 */
class WorkerController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->interop->ownsServiceWorker()) {
            // On a site where PWA owns the registration there is no Schedulr worker, and serving one
            // anyway invites somebody to register it — which would replace PWA's and take offline,
            // caching and the install prompt with it.
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $cache = Craft::$app->getCache();
        $key = $plugin->serviceWorker->cacheKey($site);

        $script = $cache->getOrSet($key, fn() => $plugin->serviceWorker->render($site), 3600);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $script;

        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/javascript; charset=UTF-8');

        // The browser re-fetches a worker at most once a day on its own; a short max-age means a
        // configuration change reaches devices within the hour instead of tomorrow.
        $headers->set('Cache-Control', 'public, max-age=600');

        // Without this the worker's scope is its own directory even when served from the root on some
        // configurations, and a scope narrower than the site is a worker that controls nothing.
        $headers->set('Service-Worker-Allowed', '/');

        return $response;
    }
}
