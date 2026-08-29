<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The subscriber list.
 *
 * Hand-built rather than an element index, because subscribers are not elements — five and six figures
 * of them would land in the `elements` table for no benefit. The cost of that decision is paid here:
 * paging, filtering and searching are written out.
 *
 * Guarded by its own permission, deliberately not nested under notifications. This screen is a list of
 * identifiable people's browsers, addresses and habits; writing an announcement is not.
 */
class SubscribersController extends Controller
{
    private const PER_PAGE = 100;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_SUBSCRIBERS);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $page = max(1, (int)$request->getParam('page', 1));
        $filter = (string)$request->getParam('filter', '');
        $search = trim((string)$request->getParam('search', ''));

        $criteria = ['search' => $search !== '' ? $search : null];

        match ($filter) {
            'pushable' => $criteria['pushable'] = true,
            'emailable' => $criteria['emailable'] = true,
            'unsubscribed' => $criteria['unsubscribed'] = true,
            default => null,
        };

        $total = $plugin->subscribers->count($criteria);

        return $this->renderTemplate('schedulr/subscribers/_index', [
            'title' => Craft::t('schedulr', 'Subscribers'),
            'subscribers' => $plugin->subscribers->findAll($criteria, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'stats' => $plugin->subscribers->stats(),
            'timezones' => $plugin->subscribers->timezones(),
            'total' => $total,
            'page' => $page,
            'pageCount' => (int)ceil($total / self::PER_PAGE),
            'filter' => $filter,
            'search' => $search,
            'canExport' => Edition::allowsExport($plugin->isPro())
                && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT_SUBSCRIBERS),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_SUBSCRIBERS),
        ]);
    }

    public function actionDetail(int $subscriberId): Response
    {
        $plugin = Plugin::getInstance();
        $subscriber = $plugin->subscribers->getById($subscriberId);

        if ($subscriber === null) {
            throw new NotFoundHttpException('Subscriber not found.');
        }

        $tags = $plugin->subscribers->tagsFor([$subscriber->id]);

        return $this->renderTemplate('schedulr/subscribers/_detail', [
            'title' => $subscriber->getEmailAddress() ?? $subscriber->visitorId,
            'subscriber' => $subscriber,
            'tags' => $tags[$subscriber->id] ?? [],
            'deliveries' => $plugin->deliveries->forSubscriber($subscriber->id),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_SUBSCRIBERS),
        ]);
    }

    public function actionUnsubscribe(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SUBSCRIBERS);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('subscriberId');
        Plugin::getInstance()->subscribers->unsubscribeAll($id);

        return $this->asSuccess(Craft::t('schedulr', 'Subscriber unsubscribed.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SUBSCRIBERS);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('subscriberId');
        Plugin::getInstance()->subscribers->delete($id);

        return $this->asSuccess(
            Craft::t('schedulr', 'Subscriber deleted.'),
            redirect: 'schedulr/subscribers',
        );
    }

    public function actionSaveTags(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SUBSCRIBERS);

        $request = Craft::$app->getRequest();
        $id = (int)$request->getRequiredBodyParam('subscriberId');
        $raw = (string)$request->getBodyParam('tags', '');

        $plugin = Plugin::getInstance();
        $existing = $plugin->subscribers->tagsFor([$id])[$id] ?? [];

        $wanted = [];

        foreach (preg_split('/[,\n]/', $raw) ?: [] as $line) {
            $line = trim((string)$line);

            if ($line === '') {
                continue;
            }

            // `key=value` or a bare tag. A tag with no value is the common case — "beta", "vip" — and
            // must not require typing an equals sign.
            [$tag, $value] = array_pad(explode('=', $line, 2), 2, null);
            $wanted[trim((string)$tag)] = $value !== null ? trim($value) : null;
        }

        foreach (array_keys($existing) as $tag) {
            if (!array_key_exists($tag, $wanted)) {
                $plugin->subscribers->removeTag($id, (string)$tag);
            }
        }

        $plugin->subscribers->setTags($id, $wanted);

        return $this->asSuccess(Craft::t('schedulr', 'Tags saved.'));
    }

    /**
     * CSV of the current filter.
     *
     * Streamed rather than assembled: an export of a hundred thousand subscribers built in memory is
     * a 500 on exactly the sites most likely to want one.
     */
    public function actionExport(): Response
    {
        $plugin = Plugin::getInstance();

        $this->requirePermission(Plugin::PERMISSION_EXPORT_SUBSCRIBERS);

        if (!Edition::allowsExport($plugin->isPro())) {
            throw new \yii\web\ForbiddenHttpException('Exporting subscribers needs Schedulr Pro.');
        }

        $request = Craft::$app->getRequest();
        $filter = (string)$request->getParam('filter', '');

        $criteria = [];

        match ($filter) {
            'pushable' => $criteria['pushable'] = true,
            'emailable' => $criteria['emailable'] = true,
            'unsubscribed' => $criteria['unsubscribed'] = true,
            default => null,
        };

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->setDownloadHeaders('schedulr-subscribers-' . date('Y-m-d') . '.csv', 'text/csv');

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'id', 'state', 'email', 'userId', 'language', 'timezone', 'platform',
            'visits', 'notified', 'firstSeen', 'lastSeen', 'subscribed',
        ]);

        $offset = 0;

        while (true) {
            $batch = $plugin->subscribers->findAll($criteria, $offset, 500);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $subscriber) {
                fputcsv($handle, [
                    $subscriber->id,
                    $subscriber->getStateLabel(),
                    // The address, never the endpoint. A push endpoint is a credential: anyone holding
                    // one can unsubscribe that device, and a spreadsheet emailed around the office is
                    // not where credentials belong.
                    $subscriber->getEmailAddress(),
                    $subscriber->userId,
                    $subscriber->language,
                    $subscriber->timezone,
                    $subscriber->platform,
                    $subscriber->visits,
                    $subscriber->notifiedCount,
                    $subscriber->dateFirstSeen?->format('Y-m-d H:i'),
                    $subscriber->dateLastSeen?->format('Y-m-d H:i'),
                    $subscriber->dateSubscribed?->format('Y-m-d H:i'),
                ]);
            }

            $offset += 500;
        }

        rewind($handle);
        $response->content = (string)stream_get_contents($handle);
        fclose($handle);

        return $response;
    }
}
