<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;

/**
 * @method Notification[] all($db = null)
 * @method Notification|null one($db = null)
 * @method Notification|null nth(int $n, $db = null)
 */
class NotificationQuery extends ElementQuery
{
    public mixed $channel = null;
    public mixed $audienceId = null;
    public mixed $triggerType = null;
    public mixed $tag = null;
    public mixed $sentBefore = null;
    public mixed $sentAfter = null;

    /**
     * Craft's own `status` handling covers the five states, so this is not a status filter — it
     * narrows to notifications a schedule could still act on.
     */
    public ?bool $pending = null;

    protected array $defaultOrderBy = ['schedulr_notifications.dateCreated' => SORT_DESC];

    public function channel(mixed $value): static
    {
        $this->channel = $value;

        return $this;
    }

    public function audienceId(mixed $value): static
    {
        $this->audienceId = $value;

        return $this;
    }

    public function triggerType(mixed $value): static
    {
        $this->triggerType = $value;

        return $this;
    }

    public function tag(mixed $value): static
    {
        $this->tag = $value;

        return $this;
    }

    public function pending(?bool $value = true): static
    {
        $this->pending = $value;

        return $this;
    }

    public function sentAfter(mixed $value): static
    {
        $this->sentAfter = $value;

        return $this;
    }

    public function sentBefore(mixed $value): static
    {
        $this->sentBefore = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('schedulr_notifications');

        $this->query->select([
            'schedulr_notifications.siteId',
            'schedulr_notifications.audienceId',
            'schedulr_notifications.channels',
            'schedulr_notifications.dedupePolicy',
            'schedulr_notifications.body',
            'schedulr_notifications.imageUrl',
            'schedulr_notifications.iconUrl',
            'schedulr_notifications.badgeUrl',
            'schedulr_notifications.url',
            'schedulr_notifications.tag',
            'schedulr_notifications.requireInteraction',
            'schedulr_notifications.buttons',
            'schedulr_notifications.topics',
            'schedulr_notifications.emailSubject',
            'schedulr_notifications.emailBody',
            'schedulr_notifications.status',
            'schedulr_notifications.triggerType',
            'schedulr_notifications.triggerConfig',
            'schedulr_notifications.sourceElementId',
            'schedulr_notifications.templateId',
            'schedulr_notifications.targeted',
            'schedulr_notifications.delivered',
            'schedulr_notifications.failed',
            'schedulr_notifications.clicked',
            'schedulr_notifications.dateLastSent',
            'schedulr_notifications.createdBy',
        ]);

        if ($this->channel !== null) {
            // `channels` is a comma-joined string rather than JSON, precisely so this filter is a
            // LIKE that works identically on MySQL and Postgres.
            $this->subQuery->andWhere(['like', 'schedulr_notifications.channels', (string)$this->channel]);
        }

        if ($this->audienceId !== null) {
            $this->subQuery->andWhere(Db::parseParam('schedulr_notifications.audienceId', $this->audienceId));
        }

        if ($this->triggerType !== null) {
            $this->subQuery->andWhere(Db::parseParam('schedulr_notifications.triggerType', $this->triggerType));
        }

        if ($this->tag !== null) {
            $this->subQuery->andWhere(Db::parseParam('schedulr_notifications.tag', $this->tag));
        }

        if ($this->pending === true) {
            $this->subQuery->andWhere(['schedulr_notifications.status' => [
                Notification::STATE_DRAFT,
                Notification::STATE_SCHEDULED,
            ]]);
        } elseif ($this->pending === false) {
            $this->subQuery->andWhere(['not', ['schedulr_notifications.status' => [
                Notification::STATE_DRAFT,
                Notification::STATE_SCHEDULED,
            ]]]);
        }

        if ($this->sentAfter !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('schedulr_notifications.dateLastSent', $this->sentAfter, '>='));
        }

        if ($this->sentBefore !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('schedulr_notifications.dateLastSent', $this->sentBefore, '<'));
        }

        return true;
    }

    /**
     * Sorting on the *sub*query, not the outer one.
     *
     * An ElementQuery applies its limit and its ordering to the subquery; adding an `orderBy` to
     * the outer query orders a page that has already been chosen, which reorders each page
     * correctly and pages through the wrong rows.
     */
    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Notification::STATE_DRAFT,
            Notification::STATE_SCHEDULED,
            Notification::STATE_SENDING,
            Notification::STATE_SENT,
            Notification::STATE_FAILED => ['schedulr_notifications.status' => $status],
            default => parent::statusCondition($status),
        };
    }
}
