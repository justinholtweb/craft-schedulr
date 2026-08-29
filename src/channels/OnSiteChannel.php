<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\channels;

use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Subscriber;

/**
 * On-site.
 *
 * The channel that needs nothing from the visitor — no permission, no address, no app — which is
 * exactly why it exists. It reaches the large majority of people who will never grant push.
 *
 * It works differently from the other two and the difference is honest rather than incidental:
 * **sending is not delivering.** Push and email hand a message to somebody else's server and find
 * out within seconds. On-site can only put a row in the ledger and wait; delivery happens whenever
 * the visitor next loads a page, which may be in ten minutes or never. So `send()` returns
 * `queued`, and the inbox endpoint is what promotes a row to `delivered` — at the moment the
 * notification is actually put in front of a person.
 *
 * That is also why this class has almost nothing in it. There is no transport to talk to. The
 * ledger row *is* the outbox, which means no second table, no reconciliation between them, and no
 * possibility of a notification that is in the outbox but not in the record of what was sent.
 *
 * It is deliberately not Blaster's job either: Blaster's bars are site-wide announcements decided
 * per request and stored on nobody. These are addressed to one person and recorded.
 */
class OnSiteChannel implements ChannelInterface
{
    public static function handle(): string
    {
        return Notification::CHANNEL_ONSITE;
    }

    public function canReach(Subscriber $subscriber): bool
    {
        return $subscriber->isOnSiteReachable();
    }

    public function send(
        Notification $notification,
        Subscriber $subscriber,
        array $overrides = [],
        ?int $variantId = null,
    ): SendResult {
        if (!$this->canReach($subscriber)) {
            return SendResult::skipped('Unsubscribed.');
        }

        return SendResult::queued();
    }
}
