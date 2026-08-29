<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One materialised send.
 *
 * This is the runner's unit of work and the reason scheduling in Schedulr is cheap: a recurring
 * rule is expanded into rows ahead of time, so the runner's query is `WHERE status = 'pending' AND
 * dueAt <= now` against one index, no matter how many notifications or how complicated their rules.
 *
 * `timezone` doubles as the audience slice. A per-subscriber-timezone send at "09:00 local" for a
 * list spanning thirty zones is thirty of these rows, each with its own UTC `dueAt` and each
 * targeting only the subscribers in that zone — which is what makes "09:00 local" mean anything at
 * all rather than "09:00 somewhere".
 */
class Occurrence extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Due, but there was nobody to send to. Distinct from sent, and distinct from failed. */
    public const STATUS_SKIPPED = 'skipped';

    public ?int $id = null;
    public ?string $uid = null;
    public ?int $notificationId = null;
    public ?int $scheduleId = null;

    /** Always UTC. Materialising is only worth anything if this column is comparable. */
    public ?DateTime $dueAt = null;

    public ?string $timezone = null;

    public string $status = self::STATUS_PENDING;

    public ?DateTime $claimedAt = null;
    public ?string $claimToken = null;

    public int $attempts = 0;
    public ?string $lastError = null;

    public int $targeted = 0;

    /** Recipients accounted for, however they turned out. `targeted` minus this is what is left. */
    public int $processed = 0;

    public int $delivered = 0;
    public int $failed = 0;

    public ?DateTime $dateSent = null;
    public ?DateTime $dateCreated = null;

    /** Hydrated from a whole row, so every selected column needs somewhere to land. */
    public ?DateTime $dateUpdated = null;

    public function datetimeAttributes(): array
    {
        return ['dueAt', 'claimedAt', 'dateSent', 'dateCreated', 'dateUpdated'];
    }

    public function isDue(?DateTime $now = null): bool
    {
        if ($this->status !== self::STATUS_PENDING || $this->dueAt === null) {
            return false;
        }

        return $this->dueAt <= ($now ?? new DateTime('now', new \DateTimeZone('UTC')));
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => Craft::t('schedulr', 'Pending'),
            self::STATUS_CLAIMED => Craft::t('schedulr', 'Claimed'),
            self::STATUS_SENDING => Craft::t('schedulr', 'Sending'),
            self::STATUS_SENT => Craft::t('schedulr', 'Sent'),
            self::STATUS_FAILED => Craft::t('schedulr', 'Failed'),
            self::STATUS_CANCELLED => Craft::t('schedulr', 'Cancelled'),
            self::STATUS_SKIPPED => Craft::t('schedulr', 'Nobody to send to'),
            default => $this->status,
        };
    }
}
