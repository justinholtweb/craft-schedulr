<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\schedulr\Plugin;
use yii\console\ExitCode;

/**
 * `craft schedulr/subscribers` — the list, from a shell.
 */
class SubscribersController extends Controller
{
    public $defaultAction = 'stats';

    /** Days a subscriber may go unseen before `prune` forgets them. */
    public int $days = 0;

    /** Show what `prune` would delete without deleting it. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'prune' => ['days', 'dryRun'],
            default => [],
        });
    }

    public function actionStats(): int
    {
        $plugin = Plugin::getInstance();

        foreach ($plugin->subscribers->stats() as $key => $value) {
            $this->stdout(sprintf("%-14s %s\n", $key . ':', $value));
        }

        $timezones = $plugin->subscribers->timezones();

        if ($timezones !== []) {
            $this->stdout("\nTime zones\n", Console::BOLD);

            // The number that decides how expensive a per-subscriber-timezone send is: each of these
            // is one occurrence row and one audience slice.
            foreach ($timezones as $zone => $count) {
                $this->stdout(sprintf("  %-34s %s\n", $zone, $count));
            }

            $this->stdout(sprintf("\n%d zone(s) — a “09:00 local” send is %d occurrences.\n", count($timezones), count($timezones)));
        }

        return ExitCode::OK;
    }

    /**
     * `craft schedulr/subscribers/adopt-pwa`
     */
    public function actionAdoptPwa(): int
    {
        $count = Plugin::getInstance()->interop->adoptFromPwa();

        $this->stdout("Adopted {$count} subscriber(s) from PWA.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionPrune(): int
    {
        $days = $this->days > 0
            ? $this->days
            : Plugin::getInstance()->getSettings()->subscriberRetentionDays;

        if ($days <= 0) {
            $this->stdout("No retention period set. Pass --days=N to prune anyway.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $count = Plugin::getInstance()->subscribers->count([]);
            $this->stdout("Retention is {$days} day(s); {$count} subscriber(s) on the list in total.\n");
            $this->stdout("Re-run without --dry-run to prune.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$this->confirm("Forget every subscriber not seen for {$days} day(s)?")) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->subscribers->prune($days);
        $this->stdout("Forgot {$count} subscriber(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
