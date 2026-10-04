<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\schedulr\channels\PushChannel;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\Plugin;
use yii\queue\RetryableJobInterface;

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
class SendBatch extends BaseJob implements RetryableJobInterface
{
    /** Seconds each push request may take: the Guzzle timeout `PushChannel` sends with. */
    public const REQUEST_TIMEOUT = PushChannel::REQUEST_TIMEOUT;

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

        $totals = $plugin->schedules->completeBatch(
            $this->occurrenceId,
            count($this->subscriberIds),
            $counts['delivered'],
            $counts['failed'],
        );

        $this->setProgress($queue, 1);

        if ($totals === null) {
            return;
        }

        // The last batch to finish closes the send. Which one that is depends on how the queue was
        // drained, which is why it is decided by the database rather than by batch number — and the
        // outcome is the *occurrence's* accumulated totals, never this batch's. A send that reached
        // everybody but the forty people in its final batch is a sent notification, not a failed one.
        // `completeBatch()` has already written the occurrence's own status from the same totals.
        $plugin->notifications->setState(
            $notification,
            $totals['delivered'] > 0 ? Notification::STATE_SENT : Notification::STATE_FAILED,
        );

        // Picked only once the whole send is in, or the winner is decided on whichever arm the first
        // batch happened to favour.
        if (count($notification->getVariants()) > 1) {
            $plugin->notifications->pickWinner($notification->id);
        }
    }

    /**
     * Seconds the queue gives this job before presuming its worker dead.
     *
     * Craft's default is 300, and a batch of 1,000 against a push service having a slow afternoon can
     * legitimately take ten times that: every request is allowed `PushChannel`'s full ten-second
     * timeout. A TTR shorter than the batch's worst case is not a timeout, it is a **re-send** — the
     * queue releases the job while it is still running, another worker picks it up, and everyone in the
     * batch is notified twice (this job is not idempotent; see above).
     *
     * So: the per-request timeout for every recipient, a fifth again for the email and on-site writes
     * and the ledger, and a minute of headroom — never less than Craft's own default.
     */
    public function getTtr(): int
    {
        return max(300, (int)ceil(count($this->subscriberIds) * self::REQUEST_TIMEOUT * 1.2) + 60);
    }

    /**
     * Exactly the retry policy the queue would apply without this interface.
     *
     * Implementing `RetryableJobInterface` is only for `getTtr()`; it must not change how often a
     * failed batch is retried, because every retry is a re-send to the people in it.
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < (int)Craft::$app->getQueue()->attempts;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('schedulr', 'Sending notification batch {n}/{total}', [
            'n' => $this->batchNumber,
            'total' => $this->batchCount,
        ]);
    }
}
