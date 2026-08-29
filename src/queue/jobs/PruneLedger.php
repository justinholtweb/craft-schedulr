<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\schedulr\Plugin;

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

        $this->setProgress($queue, 0, Craft::t('schedulr', 'Pruning deliveries'));
        $deliveries = $plugin->deliveries->prune($this->ledgerDays);

        $this->setProgress($queue, 0.4, Craft::t('schedulr', 'Pruning events'));
        $events = $plugin->analytics->prune($this->ledgerDays);

        $this->setProgress($queue, 0.8, Craft::t('schedulr', 'Pruning subscribers'));
        $subscribers = $plugin->subscribers->prune($this->subscriberDays);

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

    protected function defaultDescription(): ?string
    {
        return Craft::t('schedulr', 'Pruning Schedulr’s ledger');
    }
}
