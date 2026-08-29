<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\channels;

use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Subscriber;

/**
 * One way of reaching a person.
 *
 * The interface is small because the whole design depends on the three channels being
 * interchangeable at the point of sending: a Notification is composed once, and `Sender` walks the
 * enabled channels without knowing what any of them do. Anything channel-specific — VAPID, a
 * mailer, an inbox row — lives behind `send()`.
 *
 * `canReach()` is separate from `send()` on purpose. The audience for a notification is resolved
 * *per channel* before any sending starts, because the three channels do not share a recipient
 * list: someone who denied push is still reachable on-site, and someone with no address cannot be
 * emailed. Asking `send()` to discover that would mean a ledger full of failures that were never
 * attempts.
 */
interface ChannelInterface
{
    /** Matches the handles on `Notification::channelOptions()`. */
    public static function handle(): string;

    /** Whether this channel could reach this person at all, before anything is attempted. */
    public function canReach(Subscriber $subscriber): bool;

    /**
     * @param array<string, string> $overrides Variant fields, when A/B testing.
     */
    public function send(
        Notification $notification,
        Subscriber $subscriber,
        array $overrides = [],
        ?int $variantId = null,
    ): SendResult;
}
