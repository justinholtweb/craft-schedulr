<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One recipient, one channel, one send.
 *
 * The ledger outlives its subjects on purpose — every foreign key into it is `ON DELETE SET NULL`
 * — because "what went out last March, and to how many" has to stay answerable after the
 * notification has been deleted and the devices retired.
 *
 * The distinction between `failed` and `gone` is the one that matters and the reason this is a
 * status rather than a boolean:
 *
 * - **gone** (404/410) means the push service has retired the endpoint. The device is not coming
 *   back and retrying is pointless forever.
 * - **failed** (429/5xx, timeouts) means the push service was busy or broken. The device is fine.
 *
 * Treating the two the same either throws away live subscribers or pushes forever to browsers that
 * were uninstalled.
 */
class Delivery extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_GONE = 'gone';

    /** Reachable, but deliberately not sent — deduped, frequency-capped, or in quiet hours. */
    public const STATUS_SKIPPED = 'skipped';

    public ?int $id = null;
    public ?string $uid = null;
    public ?int $notificationId = null;
    public ?int $occurrenceId = null;
    public ?int $variantId = null;
    public ?int $subscriberId = null;

    public string $channel = '';
    public string $status = self::STATUS_QUEUED;
    public ?int $statusCode = null;
    public ?string $error = null;

    public ?DateTime $dateCreated = null;

    /** Hydrated from a whole row, so every selected column needs somewhere to land. */
    public ?DateTime $dateUpdated = null;

    public function datetimeAttributes(): array
    {
        return ['dateCreated', 'dateUpdated'];
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => Craft::t('schedulr', 'Queued'),
            self::STATUS_DELIVERED => Craft::t('schedulr', 'Delivered'),
            self::STATUS_FAILED => Craft::t('schedulr', 'Failed'),
            self::STATUS_GONE => Craft::t('schedulr', 'Device gone'),
            self::STATUS_SKIPPED => Craft::t('schedulr', 'Skipped'),
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_GONE => 'gray',
            self::STATUS_SKIPPED => 'gray',
            default => 'orange',
        };
    }
}
