<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\schedulr\Plugin;
use yii\console\ExitCode;

/**
 * `craft schedulr/run` — the scheduler.
 *
 * Meant for a one-minute cron:
 *
 *     * * * * * cd /path/to/site && php craft schedulr/run --quiet
 *
 * Running it more often is harmless: a mutex means a second pass finds the lock held and returns
 * immediately rather than queueing behind the first.
 */
class RunController extends Controller
{
    public $defaultAction = 'index';

    /** Occurrences to dispatch in one pass. */
    public int $limit = 25;

    /** Print nothing unless something happened. For cron, so a quiet minute sends no mail. */
    public bool $quiet = false;

    /** Show what is due without sending anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'index' => ['limit', 'quiet', 'dryRun'],
            default => [],
        });
    }

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->dryRun) {
            return $this->dryRun();
        }

        $result = $plugin->runner->run('cron', $this->limit);

        if ($result['skipped']) {
            if (!$this->quiet) {
                $this->stdout("Another run is already in progress.\n", Console::FG_YELLOW);
            }

            return ExitCode::OK;
        }

        $didSomething = $result['claimed'] > 0 || $result['expanded'] > 0 || $result['reclaimed'] > 0 || $result['swept'] > 0;

        // Nothing on a quiet minute. A cron job that prints a line every sixty seconds is a cron job
        // whose output nobody reads, which is where the one important line goes to die.
        if ($this->quiet && !$didSomething) {
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Dispatched %d of %d due send(s) to %d recipient(s).\n",
            $result['dispatched'],
            $result['claimed'],
            $result['recipients'],
        ), Console::FG_GREEN);

        if ($result['expanded'] > 0) {
            $this->stdout("Materialised {$result['expanded']} future occurrence(s).\n");
        }

        if ($result['reclaimed'] > 0) {
            $this->stdout("Reclaimed {$result['reclaimed']} stalled occurrence(s).\n", Console::FG_YELLOW);
        }

        if ($result['swept'] > 0) {
            $this->stdout("Raised {$result['swept']} win-back notification(s).\n");
        }

        return ExitCode::OK;
    }

    /**
     * `craft schedulr/run/health` — what the CP banner says, at a shell.
     */
    public function actionHealth(): int
    {
        $health = Plugin::getInstance()->runner->health();

        $this->stdout("Runner state: ", Console::FG_GREY);
        $this->stdout($health['state'] . "\n", match ($health['state']) {
            'cron' => Console::FG_GREEN,
            'web' => Console::FG_YELLOW,
            default => Console::FG_RED,
        });

        foreach (['lastCron' => 'Last cron run', 'lastWeb' => 'Last web run'] as $key => $label) {
            $this->stdout(sprintf(
                "%-16s %s\n",
                $label . ':',
                $health[$key]?->format('Y-m-d H:i:s') ?? 'never',
            ));
        }

        $this->stdout(sprintf("%-16s %d\n", 'Pending sends:', $health['pending']));
        $this->stdout(sprintf("%-16s %d\n", 'Overdue:', $health['overdue']), $health['overdue'] > 0 ? Console::FG_RED : null);

        return $health['state'] === 'stalled' ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function dryRun(): int
    {
        $plugin = Plugin::getInstance();
        $upcoming = $plugin->schedules->getUpcoming($this->limit);

        if ($upcoming === []) {
            $this->stdout("Nothing scheduled.\n");

            return ExitCode::OK;
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        foreach ($upcoming as $occurrence) {
            $notification = $plugin->notifications->getById($occurrence->notificationId);
            $due = $occurrence->dueAt !== null && $occurrence->dueAt <= $now;

            $this->stdout(sprintf(
                "%-20s %-12s %-28s %s\n",
                $occurrence->dueAt?->format('Y-m-d H:i') . ' UTC',
                $occurrence->timezone ?? 'site',
                mb_substr((string)($notification->title ?? '(deleted)'), 0, 26),
                $due ? 'DUE NOW' : $occurrence->getStatusLabel(),
            ), $due ? Console::FG_GREEN : null);
        }

        return ExitCode::OK;
    }
}
