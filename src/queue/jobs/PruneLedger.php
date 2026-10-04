<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\queue\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Deliveries;

/**
 * Housekeeping, hooked to Craft's garbage collection.
 *
 * Queued rather than inline. Pruning a year of delivery rows inside somebody's page load is not a
 * trade worth making, and garbage collection runs during a request like any other.
 *
 * Note that the two retentions are independent and mean different things: the ledger is a record of
 * what the *site* did, and the subscriber list is a record of *people*. A site may reasonably want to
 * keep one for a year and the other for a month, or forget nobody and keep nothing.
 */
class PruneLedger extends BaseJob
{
    public int $ledgerDays = 90;
    public int $subscriberDays = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        // Every table is pruned in chunks of ids rather than with one unbounded DELETE — see
        // `Deliveries::deleteInChunks()` for why a single statement over a year of rows is a nightly
        // job that silently deletes nothing.
        $this->setProgress($queue, 0, Craft::t('schedulr', 'Pruning deliveries'));
        $deliveries = $plugin->deliveries->prune($this->ledgerDays);

        $this->setProgress($queue, 0.4, Craft::t('schedulr', 'Pruning events'));
        $events = $this->ledgerDays > 0
            ? Deliveries::deleteInChunks(Table::EVENTS, ['<', 'dateCreated', $this->cutoff($this->ledgerDays)])
            : 0;

        $this->setProgress($queue, 0.8, Craft::t('schedulr', 'Pruning subscribers'));
        // The same condition as `Subscribers::prune()`: not seen for the retention period. Deleting a
        // subscriber leaves their ledger rows behind with a null subscriber, which is the point.
        $subscribers = $this->subscriberDays > 0
            ? Deliveries::deleteInChunks(Table::SUBSCRIBERS, ['<', 'dateLastSeen', $this->cutoff($this->subscriberDays)])
            : 0;

        $this->setProgress($queue, 1);

        if ($deliveries + $events + $subscribers > 0) {
            Plugin::info(sprintf(
                'Pruned %d delivery row(s), %d event(s) and %d subscriber(s).',
                $deliveries,
                $events,
                $subscribers,
            ));
        }
    }

    private function cutoff(int $days): string
    {
        return (string)Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('schedulr', 'Pruning Schedulr’s ledger');
    }
}
