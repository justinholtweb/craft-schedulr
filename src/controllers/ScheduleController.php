<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\Plugin;
use yii\web\Response;

/**
 * The schedule board.
 *
 * The screen that exists because occurrences are materialised. A plugin that evaluated recurrence rules
 * at send time could only ever *claim* that a notification would go out on Tuesday; this one lists the
 * rows and shows the times.
 *
 * It leads with the runner's health rather than with the list, because a beautiful list of sends that
 * nothing will ever act on is the exact failure the whole design is arranged to avoid.
 */
class ScheduleController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_NOTIFICATIONS);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $upcoming = $plugin->schedules->getUpcoming(100);
        $recent = $plugin->schedules->getRecent(50);

        // Resolved here rather than in the template, so the template does one lookup per row instead of
        // several and cannot accidentally trigger an N+1 inside a loop nobody profiled.
        $ids = array_values(array_unique(array_filter(array_merge(
            array_map(static fn($o) => $o->notificationId, $upcoming),
            array_map(static fn($o) => $o->notificationId, $recent),
        ))));

        $titles = [];

        foreach ($ids as $id) {
            $titles[$id] = $plugin->notifications->getById($id)?->title
                ?? Craft::t('schedulr', '(deleted)');
        }

        return $this->renderTemplate('schedulr/schedule/_index', [
            'title' => Craft::t('schedulr', 'Schedule'),
            'health' => $plugin->runner->health(),
            'upcoming' => $upcoming,
            'recent' => $recent,
            'titles' => $titles,
            'canSend' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SEND_NOTIFICATIONS),
        ]);
    }

    /**
     * Runs a pass by hand.
     *
     * Present because the first thing anybody does when a scheduled send has not arrived is look for a
     * button that makes it happen, and the second thing is to run the console command — which they
     * cannot do from a browser.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SEND_NOTIFICATIONS);

        $result = Plugin::getInstance()->runner->run('manual');

        if ($result['skipped']) {
            return $this->asSuccess(Craft::t('schedulr', 'Another run was already in progress.'));
        }

        return $this->asSuccess(Craft::t('schedulr', '{claimed} due send(s) dispatched to {recipients} recipient(s).', [
            'claimed' => $result['claimed'],
            'recipients' => $result['recipients'],
        ]), $result);
    }
}
