<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\Plugin;

/**
 * Sends one batch of recipients.
 *
 * Batching exists so that a worker dying loses one batch rather than its place in a fifty-thousand
 * device list, and so that the queue can show progress on a send that takes twenty minutes.
 *
 * The job is deliberately **not** idempotent, and that is a decision rather than an oversight: a
 * retried batch re-sends to the people in it. The alternative — recording per-recipient intent before
 * sending so a retry could skip them — costs a second write per recipient on every send to protect
 * against a case where the honest failure mode (a few people see a notification twice) is far less
 * bad than the one it would introduce (a crash between the two writes means those people never get
 * it at all).
 */
class SendBatch extends BaseJob
{
    public ?int $occurrenceId = null;
    public ?int $notificationId = null;

    /** @var int[] */
    public array $subscriberIds = [];

    public int $batchNumber = 1;
    public int $batchCount = 1;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $notification = $plugin->notifications->getById($this->notificationId);

        if ($notification === null) {
            // The notification was deleted while the batch waited. Not an error worth failing the
            // job over — there is nothing to send and nothing to retry.
            Plugin::warning('Skipped a send batch: notification #' . $this->notificationId . ' no longer exists.');

            return;
        }

        $this->setProgress($queue, 0, Craft::t('schedulr', 'Sending {n} of {total}', [
            'n' => $this->batchNumber,
            'total' => $this->batchCount,
        ]));

        $counts = $plugin->sender->sendBatch($notification, $this->subscriberIds, $this->occurrenceId);

        $plugin->notifications->addOutcome($notification->id, $counts['delivered'], $counts['failed']);

        $complete = $plugin->schedules->completeBatch(
            $this->occurrenceId,
            count($this->subscriberIds),
            $counts['delivered'],
            $counts['failed'],
        );

        $this->setProgress($queue, 1);

        if (!$complete) {
            return;
        }

        // The last batch to finish closes the send. Which one that is depends on how the queue was
        // drained, which is why it is decided by the atomic counter rather than by batch number.
        $plugin->schedules->markOccurrence(
            $this->occurrenceId,
            $counts['delivered'] > 0 ? Occurrence::STATUS_SENT : Occurrence::STATUS_FAILED,
            $counts['delivered'] > 0 ? null : 'Nothing was delivered.',
        );

        $plugin->notifications->setState(
            $notification,
            $counts['delivered'] > 0 ? Notification::STATE_SENT : Notification::STATE_FAILED,
        );

        // Picked only once the whole send is in, or the winner is decided on whichever arm the first
        // batch happened to favour.
        if (count($notification->getVariants()) > 1) {
            $plugin->notifications->pickWinner($notification->id);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('schedulr', 'Sending notification batch {n}/{total}', [
            'n' => $this->batchNumber,
            'total' => $this->batchCount,
        ]);
    }
}
