<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\Plugin;
use yii\console\ExitCode;

/**
 * `craft schedulr/notifications` — sending and inspecting from a shell.
 *
 * Exists so a site can send notifications from its own code and its own deploy scripts without going
 * near the CP: "notify everyone that the release shipped" belongs in the release script.
 */
class NotificationsController extends Controller
{
    public $defaultAction = 'list';

    /** Notification body. */
    public ?string $body = null;

    /** Where a tap lands. */
    public ?string $url = null;

    /** Comma-separated channels: push, email, onsite. */
    public string $channels = 'push';

    /** Audience handle. Omitted sends to everyone reachable. */
    public ?string $audience = null;

    /** Collapse key. Two notifications sharing one replace each other on the device. */
    public ?string $tag = null;

    /** Resolve and count the audience without sending. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'send' => ['body', 'url', 'channels', 'audience', 'tag', 'dryRun'],
            'resend' => ['dryRun'],
            default => [],
        });
    }

    /**
     * `craft schedulr/notifications/list`
     */
    public function actionList(int $limit = 25): int
    {
        /** @var Notification[] $notifications */
        $notifications = Notification::find()->status(null)->limit($limit)->all();

        if ($notifications === []) {
            $this->stdout("No notifications yet.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-6s %-34s %-11s %-18s %s\n", 'ID', 'Title', 'Status', 'Channels', 'Delivered'), Console::BOLD);

        foreach ($notifications as $notification) {
            $this->stdout(sprintf(
                "%-6s %-34s %-11s %-18s %s\n",
                $notification->id,
                mb_substr((string)$notification->title, 0, 32),
                $notification->state,
                implode(',', $notification->getChannels()),
                $notification->delivered . '/' . $notification->targeted,
            ));
        }

        return ExitCode::OK;
    }

    /**
     * `craft schedulr/notifications/send "Title"` — compose and send in one go.
     */
    public function actionSend(string $title): int
    {
        $plugin = Plugin::getInstance();

        $notification = new Notification();
        $notification->siteId = \Craft::$app->getSites()->getPrimarySite()->id;
        $notification->title = $title;
        $notification->body = $this->body;
        $notification->url = $this->url;
        $notification->tag = $this->tag;
        $notification->setChannels(explode(',', $this->channels));
        $notification->state = Notification::STATE_SCHEDULED;
        $notification->setSchedule(new Schedule(['mode' => Schedule::MODE_NOW]));

        if ($this->audience !== null) {
            $audience = $plugin->audiences->getByHandle($this->audience);

            if ($audience === null) {
                $this->stderr("No audience with the handle “{$this->audience}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $notification->audienceId = $audience->id;
        }

        if ($this->dryRun) {
            // Validated but never saved. A dry run that left a draft behind would fill the list with
            // one abandoned notification per rehearsal.
            if (!$notification->validate()) {
                $this->stderr("Invalid: " . implode('; ', $notification->getErrorSummary(true)) . "\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $count = count($plugin->sender->resolveAudience($notification));
            $this->stdout("Would send to {$count} recipient(s).\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$plugin->notifications->save($notification)) {
            $this->stderr("Couldn’t save: " . implode('; ', $notification->getErrorSummary(true)) . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $occurrence = $plugin->sender->sendNow($notification);

        $this->stdout(sprintf(
            "Notification #%d queued to %d recipient(s). Run the queue to deliver it.\n",
            $notification->id,
            $occurrence->targeted ?? 0,
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * `craft schedulr/notifications/resend <id>`
     */
    public function actionResend(int $id): int
    {
        $plugin = Plugin::getInstance();
        $notification = $plugin->notifications->getById($id);

        if ($notification === null) {
            $this->stderr("No notification #{$id}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $count = count($plugin->sender->resolveAudience($notification));
            $this->stdout("Would send to {$count} recipient(s).\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $plugin->notifications->resetCounters($notification->id);
        $occurrence = $plugin->sender->sendNow($notification);

        $this->stdout(sprintf("Queued to %d recipient(s).\n", $occurrence->targeted ?? 0), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * `craft schedulr/notifications/report <id>`
     */
    public function actionReport(int $id): int
    {
        $plugin = Plugin::getInstance();
        $notification = $plugin->notifications->getById($id);

        if ($notification === null) {
            $this->stderr("No notification #{$id}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout($notification->title . "\n", Console::BOLD);

        foreach ($plugin->analytics->funnel($id) as $key => $value) {
            $this->stdout(sprintf("%-14s %s\n", $key . ':', $value === null ? '—' : $value));
        }

        $failures = $plugin->deliveries->failureReasons($id);

        if ($failures !== []) {
            $this->stdout("\nFailures\n", Console::BOLD);

            foreach ($failures as $row) {
                $this->stdout(sprintf(
                    "  %-8s %-8s %-5s %-6s %s\n",
                    $row['channel'],
                    $row['status'],
                    $row['statusCode'] ?? '—',
                    $row['total'],
                    mb_substr((string)$row['example'], 0, 60),
                ));
            }
        }

        return ExitCode::OK;
    }
}
