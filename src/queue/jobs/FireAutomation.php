<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\schedulr\Plugin;

/**
 * One automation firing, taken out of the request that triggered it.
 *
 * The save hook runs inside an editor's save. Rendering the template, resolving an audience that may be
 * fifty thousand people and queueing its batches is work for a worker, not for the gap between pressing
 * Save and the page coming back — and an automation that fails must never be able to fail the save.
 *
 * Carries only IDs. The template and the source element are re-read when the job runs, because the
 * queue may have waited: the template may have been switched off, the entry fired for by another job,
 * or either deleted.
 */
class FireAutomation extends BaseJob
{
    public ?int $templateId = null;
    public ?int $sourceId = null;

    /** @var class-string<\craft\base\ElementInterface>|null */
    public ?string $sourceType = null;

    public ?int $sourceSiteId = null;

    /** Set for a firing addressed to one user — a welcome — rather than to the template's audience. */
    public ?int $restrictToUserId = null;

    /** Whether the once-per-source guard applies. Off for a template set to fire on every save. */
    public bool $once = true;

    public function execute($queue): void
    {
        if ($this->templateId === null || $this->sourceId === null || $this->sourceType === null) {
            return;
        }

        Plugin::getInstance()->automations->fireQueued(
            $this->templateId,
            $this->sourceId,
            $this->sourceType,
            $this->sourceSiteId,
            $this->restrictToUserId,
            $this->once,
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('schedulr', 'Running a notification automation');
    }
}
