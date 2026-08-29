<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\Plugin;
use yii\web\Response;

/**
 * `GET /schedulr/inbox.json` — the on-site notifications waiting for one visitor.
 *
 * Exists separately from the heartbeat, which already returns the same list, for one reason: a site
 * that renders its own notification UI should not have to send a heartbeat to get the data, and
 * should not have to read Schedulr's runtime source to find out how. This is the documented URL.
 *
 * It is deliberately **not cacheable** and says so in a header. A visitor-specific payload behind a
 * CDN is one person's notifications shown to everybody, which is the same class of mistake as setting
 * a cookie on a cached HTML response.
 */
class InboxController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $visitorId = (string)($request->getParam('visitorId') ?? '');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $response = Craft::$app->getResponse();
        $response->setNoCacheHeaders();

        if ($visitorId === '') {
            return $this->asJson(['ok' => false, 'items' => []]);
        }

        $subscriber = $plugin->subscribers->getByVisitorId($visitorId, $siteId);

        if ($subscriber === null) {
            // Not an error. A visitor whose storage was cleared has a valid-looking ID nobody has
            // ever seen, and an empty inbox is the truthful answer.
            return $this->asJson(['ok' => true, 'items' => []]);
        }

        $limit = min(20, max(1, (int)$request->getParam('limit', 5)));

        return $this->asJson([
            'ok' => true,
            'items' => $plugin->deliveries->inboxFor($subscriber->id, $limit),
        ]);
    }
}
