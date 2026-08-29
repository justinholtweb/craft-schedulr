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
 * Reports.
 *
 * Two screens: an overview across everything, and the ledger for one notification.
 *
 * Lite reaches both. What Pro adds is the *click* half — the funnel, the rates, the leaderboard — while
 * Lite still sees delivered and failed counts, because a send whose outcome is unknown is a send nobody
 * makes twice, and hiding that behind a paywall would make the free edition feel broken rather than
 * limited.
 */
class ReportsController extends Controller
{
    private const PER_PAGE = 100;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_ANALYTICS);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $isPro = $plugin->isPro();
        $days = min(365, max(7, (int)Craft::$app->getRequest()->getParam('days', 30)));

        $tracking = Edition::allowsClickTracking($isPro);

        $leaderboard = [];

        if ($tracking) {
            foreach ($plugin->analytics->leaderboard() as $row) {
                $row['notification'] = $plugin->notifications->getById($row['id']);
                $leaderboard[] = $row;
            }
        }

        return $this->renderTemplate('schedulr/reports/_index', [
            'title' => Craft::t('schedulr', 'Reports'),
            'days' => $days,
            'series' => $plugin->analytics->daily($days),
            'stats' => $plugin->subscribers->stats(),
            'leaderboard' => $leaderboard,
            'tracking' => $tracking,
            'isPro' => $isPro,
        ]);
    }

    public function actionDeliveries(int $notificationId): Response
    {
        $plugin = Plugin::getInstance();
        $notification = $plugin->notifications->getById($notificationId);

        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getParam('page', 1));
        $failedOnly = (bool)$request->getParam('failed');

        $criteria = ['notificationId' => $notificationId, 'failedOnly' => $failedOnly];
        $total = $plugin->deliveries->count($criteria);

        $rows = $plugin->deliveries->find($criteria, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Subscribers resolved in one pass rather than per row: a hundred rows would otherwise be a
        // hundred queries, and the screen people open when a send went wrong is the worst place for
        // that.
        $subscriberIds = array_values(array_unique(array_filter(array_column($rows, 'subscriberId'))));
        $subscribers = [];

        foreach ($plugin->subscribers->findAll(['ids' => $subscriberIds]) as $subscriber) {
            $subscribers[$subscriber->id] = $subscriber;
        }

        return $this->renderTemplate('schedulr/reports/_deliveries', [
            'title' => $notification->title,
            'notification' => $notification,
            'rows' => $rows,
            'subscribers' => $subscribers,
            'summary' => $plugin->deliveries->summary($notificationId),
            'failures' => $plugin->deliveries->failureReasons($notificationId),
            'funnel' => Edition::allowsClickTracking($plugin->isPro())
                ? $plugin->analytics->funnel($notificationId)
                : [],
            'variants' => $plugin->notifications->getVariantModels($notificationId),
            'occurrences' => $plugin->schedules->getRecent(20, $notificationId),
            'total' => $total,
            'page' => $page,
            'pageCount' => (int)ceil($total / self::PER_PAGE),
            'failedOnly' => $failedOnly,
        ]);
    }

    /**
     * The ledger as CSV, streamed.
     */
    public function actionExport(): Response
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsExport($plugin->isPro())) {
            throw new \yii\web\ForbiddenHttpException('Exporting the ledger needs Schedulr Pro.');
        }

        $notificationId = (int)Craft::$app->getRequest()->getRequiredParam('notificationId');

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->setDownloadHeaders('schedulr-deliveries-' . $notificationId . '.csv', 'text/csv');

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'subscriberId', 'channel', 'status', 'statusCode', 'error', 'sentAt']);

        foreach ($plugin->deliveries->each(['notificationId' => $notificationId]) as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['subscriberId'],
                $row['channel'],
                $row['status'],
                $row['statusCode'],
                $row['error'],
                $row['dateCreated'],
            ]);
        }

        rewind($handle);
        $response->content = (string)stream_get_contents($handle);
        fclose($handle);

        return $response;
    }
}
