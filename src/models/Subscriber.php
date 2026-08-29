<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use DateTimeZone;
use Throwable;

/**
 * A visitor identity — not a push endpoint.
 *
 * This is the model decision the rest of the plugin hangs off. The obvious alternative, "a
 * subscriber *is* a push subscription", cannot express the on-site or email channel at all, and it
 * throws the visitor away the moment they press *Block* — which is the majority of visitors on
 * every site that has ever measured it.
 *
 * So the endpoint columns are nullable and a subscriber passes through states:
 *
 * - **anonymous** — seen, identified by a localStorage ID. Reachable on-site.
 * - **push-subscribed** — granted permission. Reachable on-site and by push.
 * - **identified** — signed in, or gave an address. Also reachable by email.
 *
 * A subscriber can be in the last two at once, and usually is.
 */
class Subscriber extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public ?int $siteId = null;
    public ?int $userId = null;

    /**
     * The identity that survives a denied permission — a UUID the browser keeps in localStorage.
     *
     * Not a cookie. A `Set-Cookie` on an HTML response poisons every full-page cache in front of
     * the site, which serves one visitor's identity to everybody behind it.
     */
    public string $visitorId = '';

    public ?string $endpoint = null;
    public ?string $endpointHash = null;
    public ?string $p256dh = null;
    public ?string $auth = null;
    public string $contentEncoding = 'aes128gcm';

    public ?string $email = null;
    public bool $emailVerified = false;

    public ?string $language = null;
    public ?string $timezone = null;
    public ?string $platform = null;
    public ?string $userAgent = null;
    public ?string $country = null;

    public int $visits = 1;
    public ?DateTime $dateFirstSeen = null;
    public ?DateTime $dateLastSeen = null;
    public ?DateTime $dateSubscribed = null;
    public ?DateTime $dateLastNotified = null;
    public int $notifiedCount = 0;
    public ?DateTime $dateDeclined = null;

    public bool $unsubscribed = false;
    public int $failures = 0;

    public ?DateTime $dateCreated = null;

    /** @var string[] Tags, loaded on demand by the service. */
    public array $tags = [];

    /**
     * Craft's `Model::__construct()` already converts these from DB strings, assuming UTC.
     *
     * Naming them here is what makes that happen; a `DateTime`-typed property left out of this
     * list arrives as a string and fatals on the first date comparison.
     */
    public function datetimeAttributes(): array
    {
        return [
            'dateFirstSeen',
            'dateLastSeen',
            'dateSubscribed',
            'dateLastNotified',
            'dateDeclined',
            'dateCreated',
        ];
    }

    // ------------------------------------------------------------------------ reachability

    /**
     * Whether push can reach this subscriber.
     *
     * All three key parts, not just the endpoint: a row with an endpoint and no `auth` secret is
     * a row the encryptor will throw on, and the throw happens inside a queue job halfway through
     * a batch.
     */
    public function isPushable(): bool
    {
        return !$this->unsubscribed
            && $this->endpoint !== null && $this->endpoint !== ''
            && $this->p256dh !== null && $this->p256dh !== ''
            && $this->auth !== null && $this->auth !== '';
    }

    public function isEmailable(): bool
    {
        if ($this->unsubscribed) {
            return false;
        }

        if ($this->email !== null && $this->email !== '') {
            return true;
        }

        // A signed-in user's address counts without being copied onto the subscriber row, so
        // changing it in one place changes it everywhere.
        return $this->getUser()?->email !== null;
    }

    public function isOnSiteReachable(): bool
    {
        // Everyone who has ever loaded a page. The on-site channel is the one that needs nothing
        // from the visitor, which is exactly why it exists.
        return !$this->unsubscribed;
    }

    /** The address to actually send to, preferring the one given to Schedulr. */
    public function getEmailAddress(): ?string
    {
        if ($this->email !== null && $this->email !== '') {
            return $this->email;
        }

        return $this->getUser()?->email;
    }

    public function getUser(): ?User
    {
        if ($this->userId === null) {
            return null;
        }

        return Craft::$app->getUsers()->getUserById($this->userId);
    }

    // ---------------------------------------------------------------------------- time zone

    /**
     * The subscriber's own time zone, falling back to the site's.
     *
     * The fallback is the only defensible guess, and it is *made here* rather than at every call
     * site, so a per-subscriber-timezone send cannot accidentally treat unknown as UTC and post
     * "good morning" at 3am to everybody it has never met.
     */
    public function getTimeZone(): DateTimeZone
    {
        if ($this->timezone !== null && $this->timezone !== '') {
            try {
                return new DateTimeZone($this->timezone);
            } catch (Throwable) {
                // A browser reporting a zone PHP's database has never heard of. Falls through.
            }
        }

        return new DateTimeZone(Craft::$app->getTimeZone());
    }

    /** Days since this browser was last seen. Null when it has never been seen, which cannot happen. */
    public function getDaysSinceLastSeen(): ?int
    {
        if ($this->dateLastSeen === null) {
            return null;
        }

        return (int)$this->dateLastSeen->diff(new DateTime())->days;
    }

    public function getStateLabel(): string
    {
        if ($this->unsubscribed) {
            return Craft::t('schedulr', 'Unsubscribed');
        }

        if ($this->isPushable()) {
            return Craft::t('schedulr', 'Subscribed');
        }

        if ($this->dateDeclined !== null) {
            return Craft::t('schedulr', 'Declined');
        }

        return Craft::t('schedulr', 'Anonymous');
    }

    protected function defineRules(): array
    {
        return [
            [['visitorId'], 'required'],
            [['visitorId'], 'string', 'max' => 36],
            [['email'], 'email', 'skipOnEmpty' => true],
            [['endpoint'], 'url', 'defaultScheme' => null, 'skipOnEmpty' => true],
            [['timezone'], 'string', 'max' => 64],
            [['language'], 'string', 'max' => 12],
            [['country'], 'string', 'max' => 2],
            [['visits', 'notifiedCount', 'failures'], 'integer', 'min' => 0],
        ];
    }
}
