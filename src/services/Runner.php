<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use DateTime;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\Plugin;
use Throwable;
use yii\mutex\Mutex;

/**
 * What actually notices that something is due.
 *
 * Craft has no scheduler, so this is the part every scheduling plugin has to invent and the part
 * that most often quietly does nothing. The design goal is narrow and specific: **never look
 * scheduled and be inert.**
 *
 * Two runners, and the difference is stated plainly in the CP rather than buried:
 *
 * - **Cron** — `php craft schedulr/run`, on a one-minute schedule. The only mode that sends on time.
 * - **Web** — hooked to the end of a request, WP-Cron shaped. It can only run when somebody visits,
 *   so a quiet site sends late. It exists because a great many Craft sites have no cron, and a
 *   plugin that simply fails on those is a plugin whose scheduling feature is decorative.
 *
 * In `auto` mode the web runner **stands down while cron is being seen**, so a site with cron pays
 * nothing for the fallback existing.
 */
class Runner extends Component
{
    private const MUTEX_NAME = 'schedulr:runner';

    /** How long after a cron tick the web fallback considers cron to be alive. */
    private const CRON_ALIVE_SECONDS = 300;

    private const CACHE_CRON = 'schedulr:lastRun:cron';
    private const CACHE_WEB = 'schedulr:lastRun:web';

    /** A year. The value is a heartbeat, not a computed result, so it must not expire on its own. */
    private const CACHE_TTL = 31536000;

    /**
     * One pass: reclaim what stalled, top up the horizon, then send what is due.
     *
     * The order matters. Reclaiming first means a worker killed on the previous pass gets its work
     * back on this one; expanding before claiming means a recurrence whose horizon ran out between
     * passes still fires on time rather than a day late.
     *
     * @return array{claimed: int, dispatched: int, recipients: int, expanded: int, reclaimed: int, swept: int, skipped: bool}
     */
    public function run(string $source = 'cron', int $limit = 25): array
    {
        $result = [
            'claimed' => 0,
            'dispatched' => 0,
            'recipients' => 0,
            'expanded' => 0,
            'reclaimed' => 0,
            'swept' => 0,
            'skipped' => false,
        ];

        $mutex = Craft::$app->getMutex();

        // A zero timeout, deliberately. If another runner holds the lock, this pass has nothing to
        // add — queueing up behind it would mean a burst of traffic starting twenty runners that
        // each wait their turn to find there is nothing left to do.
        if (!$mutex->acquire(self::MUTEX_NAME, 0)) {
            $result['skipped'] = true;

            return $result;
        }

        try {
            $this->recordRun($source);

            $plugin = Plugin::getInstance();

            $result['reclaimed'] = $plugin->schedules->reclaimStalled();
            $result['expanded'] = $plugin->schedules->expandAll();

            // Win-backs have no clock of their own — there is no moment at which "has not visited
            // for thirty days" becomes true, only a moment at which somebody asks. This is that
            // moment.
            $result['swept'] = $plugin->automations->sweep();

            $due = $plugin->schedules->claimDue($limit);
            $result['claimed'] = count($due);

            foreach ($due as $occurrence) {
                try {
                    $result['recipients'] += $plugin->sender->dispatch($occurrence);
                    $result['dispatched']++;
                } catch (Throwable $e) {
                    // One bad occurrence must not stop the pass: the next one in the list may be
                    // somebody's time-critical announcement.
                    $plugin->schedules->markOccurrence($occurrence->id, Occurrence::STATUS_FAILED, $e->getMessage());
                    Plugin::error('Occurrence #' . $occurrence->id . ' failed to dispatch: ' . $e->getMessage());
                }
            }
        } finally {
            $mutex->release(self::MUTEX_NAME);
        }

        return $result;
    }

    /**
     * The web fallback, hooked to the end of a request.
     *
     * Everything here is a reason not to run. It is called on every single front-end request, so the
     * cheap checks come first and the mutex — which touches the database — comes last, inside
     * `run()`.
     */
    public function runFromWeb(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->runnerMode !== Settings::RUNNER_AUTO) {
            return;
        }

        if ($this->isCronAlive()) {
            // Cron is doing the job. The fallback existing costs this site nothing.
            return;
        }

        $cache = Craft::$app->getCache();
        $last = (int)($cache->get(self::CACHE_WEB) ?: 0);

        if ($last > 0 && (time() - $last) < max(10, $settings->runnerMinInterval)) {
            return;
        }

        // Written *before* the pass, not after. A pass that dies halfway would otherwise never
        // record an attempt, and every subsequent request would start another one.
        $cache->set(self::CACHE_WEB, time(), self::CACHE_TTL);

        try {
            // A smaller limit than cron's. This is running inside somebody's page request, and
            // queueing work is cheap but not free.
            $this->run('web', 5);
        } catch (Throwable $e) {
            Plugin::error('The web runner failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------------- health

    public function recordRun(string $source): void
    {
        Craft::$app->getCache()->set(
            $source === 'cron' ? self::CACHE_CRON : self::CACHE_WEB,
            time(),
            self::CACHE_TTL,
        );
    }

    public function getLastRun(string $source = 'cron'): ?DateTime
    {
        $value = (int)(Craft::$app->getCache()->get($source === 'cron' ? self::CACHE_CRON : self::CACHE_WEB) ?: 0);

        return $value > 0 ? (new DateTime())->setTimestamp($value) : null;
    }

    public function isCronAlive(): bool
    {
        $last = $this->getLastRun('cron');

        return $last !== null && (time() - $last->getTimestamp()) < self::CRON_ALIVE_SECONDS;
    }

    /**
     * What the CP banner says, and why.
     *
     * Returns a state rather than a rendered string so the console command can report the same
     * facts. The states are deliberately three rather than two: "nothing is running this" and
     * "something is running this, late" need different sentences, because the fix is different.
     *
     * @return array{state: string, lastCron: DateTime|null, lastWeb: DateTime|null, pending: int, overdue: int}
     */
    public function health(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $schedules = Plugin::getInstance()->schedules;

        $pending = 0;
        $overdue = 0;

        try {
            $upcoming = $schedules->getUpcoming(200);
            $now = new DateTime();

            foreach ($upcoming as $occurrence) {
                $pending++;

                if ($occurrence->dueAt !== null && $occurrence->dueAt < $now) {
                    $overdue++;
                }
            }
        } catch (Throwable) {
            // Called from the CP nav and the settings screen, both of which are reachable while a
            // migration is part-applied. A missing table is not worth taking the CP down for.
        }

        $cronAlive = $this->isCronAlive();

        $state = match (true) {
            $settings->runnerMode === Settings::RUNNER_MANUAL => 'manual',
            $cronAlive => 'cron',
            $settings->runnerMode === Settings::RUNNER_CRON => 'stalled',
            default => 'web',
        };

        return [
            'state' => $state,
            'lastCron' => $this->getLastRun('cron'),
            'lastWeb' => $this->getLastRun('web'),
            'pending' => $pending,
            'overdue' => $overdue,
        ];
    }
}
