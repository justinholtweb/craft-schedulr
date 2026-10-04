<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\db\ElementQueryInterface;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\db\NotificationQuery;
use justinholtweb\schedulr\helpers\Data;
use justinholtweb\schedulr\models\Audience;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\Plugin;

/**
 * One notification, composed once and delivered over whichever channels are enabled.
 *
 * An element rather than a plain record, for one reason: the CP screen a notification list needs —
 * statuses, search, sorting, pagination, bulk actions, exporters — *is* Craft's element index, and
 * rebuilding it by hand would be the largest and least interesting file in the plugin.
 *
 * Subscribers are deliberately **not** elements. They run to five and six figures on the sites that
 * buy this, and every one of them would land in the `elements` table for no benefit.
 */
class Notification extends Element
{
    public const STATE_DRAFT = 'draft';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_SENDING = 'sending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';

    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_ONSITE = 'onsite';

    public ?int $audienceId = null;

    /** @var string Comma-joined channel handles. A string, not JSON, so the query can LIKE it. */
    public string $channels = self::CHANNEL_PUSH;

    public string $dedupePolicy = 'none';

    public ?string $body = null;
    public ?string $imageUrl = null;
    public ?string $iconUrl = null;
    public ?string $badgeUrl = null;
    public ?string $url = null;
    public ?string $tag = null;
    public bool $requireInteraction = false;

    /** @var array<int, array{title: string, url: string}>|string|null */
    public mixed $buttons = null;

    /** @var string[]|string|null */
    public mixed $topics = null;

    public ?string $emailSubject = null;
    public ?string $emailBody = null;

    public string $state = self::STATE_DRAFT;

    public ?string $triggerType = null;

    /** @var array<string, mixed>|string|null */
    public mixed $triggerConfig = null;

    public ?int $sourceElementId = null;

    /** The template that raised this notification, when an automation did. */
    public ?int $templateId = null;

    public int $targeted = 0;
    public int $delivered = 0;
    public int $failed = 0;
    public int $clicked = 0;

    public ?DateTime $dateLastSent = null;
    public ?int $createdBy = null;

    private ?Schedule $_schedule = null;
    private ?Audience $_audience = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $_variants = null;

    /**
     * Craft's element query selects `status` from the sub-table, but `status` on an Element is
     * Craft's own concept and read-only. Renaming the property to `state` and mapping it here is
     * the alternative to fighting the base class — and every column an element query selects needs
     * somewhere to land, or loading the element from the database throws
     * `UnknownPropertyException` from inside `createElement()`, nowhere near the cause.
     */
    public function setStatus(string $value): void
    {
        $this->state = $value;
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateLastSent']);
    }

    // -------------------------------------------------------------------------- identity

    public static function displayName(): string
    {
        return Craft::t('schedulr', 'Notification');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('schedulr', 'Notifications');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('schedulr', 'notification');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('schedulr', 'notifications');
    }

    public static function refHandle(): ?string
    {
        return 'notification';
    }

    public static function hasTitles(): bool
    {
        // The title *is* the push title. Giving it a separate field would let the two drift, and
        // the one that shows on a lock screen is the one nobody proof-read.
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * Narrowed in the docblock rather than the signature, because the parent declares
     * `ElementQueryInterface` and PHP will not let a child return a more specific type here. Without
     * this, static analysis cannot see `->triggerType()` or any other custom query param.
     *
     * @return NotificationQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new NotificationQuery(static::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATE_DRAFT => ['label' => Craft::t('schedulr', 'Draft'), 'color' => Color::Gray],
            self::STATE_SCHEDULED => ['label' => Craft::t('schedulr', 'Scheduled'), 'color' => Color::Blue],
            self::STATE_SENDING => ['label' => Craft::t('schedulr', 'Sending'), 'color' => Color::Orange],
            self::STATE_SENT => ['label' => Craft::t('schedulr', 'Sent'), 'color' => Color::Green],
            self::STATE_FAILED => ['label' => Craft::t('schedulr', 'Failed'), 'color' => Color::Red],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->state;
    }

    // --------------------------------------------------------------------------- channels

    /**
     * @return string[]
     */
    public function getChannels(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->channels))));
    }

    /**
     * @param string[]|string $value
     */
    public function setChannels(array|string $value): void
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $allowed = [self::CHANNEL_PUSH, self::CHANNEL_EMAIL, self::CHANNEL_ONSITE];
        $clean = array_values(array_intersect($allowed, array_map('trim', $value)));

        // Never empty. A notification with no channel is a notification that cannot be sent and
        // whose send button silently does nothing, which is worse than an obvious default.
        $this->channels = implode(',', $clean !== [] ? $clean : [self::CHANNEL_PUSH]);
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, $this->getChannels(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function channelOptions(): array
    {
        return [
            self::CHANNEL_PUSH => Craft::t('schedulr', 'Web push'),
            self::CHANNEL_EMAIL => Craft::t('schedulr', 'Email'),
            self::CHANNEL_ONSITE => Craft::t('schedulr', 'On-site'),
        ];
    }

    // ---------------------------------------------------------------------------- related

    public function getSchedule(): Schedule
    {
        if ($this->_schedule !== null) {
            return $this->_schedule;
        }

        if ($this->id !== null) {
            $existing = Plugin::getInstance()->schedules->getForNotification($this->id);

            if ($existing !== null) {
                return $this->_schedule = $existing;
            }
        }

        return $this->_schedule = new Schedule(['notificationId' => $this->id]);
    }

    public function setSchedule(Schedule $schedule): void
    {
        $this->_schedule = $schedule;
    }

    public function getAudience(): ?Audience
    {
        if ($this->_audience !== null) {
            return $this->_audience;
        }

        if ($this->audienceId === null) {
            return null;
        }

        return $this->_audience = Plugin::getInstance()->audiences->getById($this->audienceId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getVariants(): array
    {
        if ($this->_variants !== null) {
            return $this->_variants;
        }

        if ($this->id === null) {
            return $this->_variants = [];
        }

        return $this->_variants = Plugin::getInstance()->notifications->getVariants($this->id);
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    public function getButtons(): array
    {
        $value = Data::toArray($this->buttons);
        $out = [];

        // Two, hard. Every browser that supports action buttons shows at most two, and the third
        // one an author writes is invisible with no warning anywhere.
        foreach (array_slice($value, 0, 2) as $button) {
            if (!is_array($button)) {
                continue;
            }

            $title = trim((string)($button['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $out[] = ['title' => $title, 'url' => trim((string)($button['url'] ?? ''))];
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return Data::toStringList($this->topics);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTriggerConfig(): array
    {
        return Data::toArray($this->triggerConfig);
    }

    // ------------------------------------------------------------------------------- CP

    // Each asks `parent::canX()` first, which is what fires `Element::EVENT_AUTHORIZE_*` and lets a
    // site or another plugin grant access Schedulr's own permissions would not. Skipping it makes
    // those events silently inert for this element type.

    public function canView(\craft\elements\User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }

        return $user->can(Plugin::PERMISSION_VIEW_NOTIFICATIONS);
    }

    public function canSave(\craft\elements\User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }

        return $user->can(Plugin::PERMISSION_MANAGE_NOTIFICATIONS);
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }

        // A notification that has gone out is a record of something that happened. Deleting it is
        // allowed — a site is entitled to tidy up — but it is its own permission, because the
        // ledger it anchors is the only answer to "what did we send in March".
        return $user->can(Plugin::PERMISSION_DELETE_NOTIFICATIONS);
    }

    public function canDuplicate(\craft\elements\User $user): bool
    {
        if (parent::canDuplicate($user)) {
            return true;
        }

        return $user->can(Plugin::PERMISSION_MANAGE_NOTIFICATIONS);
    }

    public function getCpEditUrl(): ?string
    {
        return $this->id !== null ? UrlHelper::cpUrl('schedulr/notifications/' . $this->id) : null;
    }

    protected function cpEditUrl(): ?string
    {
        return $this->getCpEditUrl();
    }

    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('schedulr', 'All notifications'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            ['heading' => Craft::t('schedulr', 'Status')],
        ];

        foreach (self::statuses() as $handle => $status) {
            $sources[] = [
                'key' => 'status:' . $handle,
                'label' => $status['label'],
                'criteria' => ['status' => $handle],
            ];
        }

        $sources[] = ['heading' => Craft::t('schedulr', 'Channel')];

        foreach (self::channelOptions() as $handle => $label) {
            $sources[] = [
                'key' => 'channel:' . $handle,
                'label' => $label,
                'criteria' => ['channel' => $handle],
            ];
        }

        return $sources;
    }

    protected static function defineActions(?string $source = null): array
    {
        $actions = [];

        if (Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE_NOTIFICATIONS)) {
            $actions[] = Delete::class;
        }

        return $actions;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'state' => ['label' => Craft::t('schedulr', 'Status')],
            'channelList' => ['label' => Craft::t('schedulr', 'Channels')],
            'audience' => ['label' => Craft::t('schedulr', 'Audience')],
            'nextSend' => ['label' => Craft::t('schedulr', 'Next send')],
            'dateLastSent' => ['label' => Craft::t('schedulr', 'Last sent')],
            'targeted' => ['label' => Craft::t('schedulr', 'Targeted')],
            'delivered' => ['label' => Craft::t('schedulr', 'Delivered')],
            'clickRate' => ['label' => Craft::t('schedulr', 'Click rate')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['state', 'channelList', 'audience', 'nextSend', 'dateLastSent', 'delivered', 'clickRate'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            [
                'label' => Craft::t('schedulr', 'Last sent'),
                'orderBy' => 'schedulr_notifications.dateLastSent',
                'attribute' => 'dateLastSent',
            ],
            [
                'label' => Craft::t('schedulr', 'Delivered'),
                'orderBy' => 'schedulr_notifications.delivered',
                'attribute' => 'delivered',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'schedulr_notifications.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'state' => $this->statusHtml(),
            'channelList' => Html::encode(implode(', ', array_map(
                fn(string $c) => (string)(self::channelOptions()[$c] ?? $c),
                $this->getChannels(),
            ))),
            'audience' => Html::encode($this->getAudience()->name ?? Craft::t('schedulr', 'Everyone')),
            'nextSend' => $this->nextSendHtml(),
            'clickRate' => $this->clickRateHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    private function statusHtml(): string
    {
        $status = self::statuses()[$this->state] ?? ['label' => $this->state, 'color' => Color::Gray];

        return Cp::statusLabelHtml([
            'color' => $status['color'],
            'label' => $status['label'],
        ]);
    }

    private function nextSendHtml(): string
    {
        if ($this->id === null) {
            return '';
        }

        $next = Plugin::getInstance()->schedules->getNextOccurrence($this->id);

        if ($next === null) {
            return '<span class="light">—</span>';
        }

        return Html::tag('span', Craft::$app->getFormatter()->asDatetime($next->dueAt, 'short'), [
            'title' => $next->timezone ?? Craft::$app->getTimeZone(),
        ]);
    }

    /**
     * Click rate, shown only when there is a denominator.
     *
     * "0%" against zero deliveries reads as a campaign that failed rather than one that has not
     * been sent, and it is the number people screenshot.
     */
    private function clickRateHtml(): string
    {
        if ($this->delivered <= 0) {
            return '<span class="light">—</span>';
        }

        $rate = round(($this->clicked / $this->delivered) * 100, 1);

        return Html::encode($rate . '%');
    }

    // ------------------------------------------------------------------------ persistence

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $data = [
                'siteId' => $this->siteId,
                'audienceId' => $this->audienceId,
                'channels' => $this->channels,
                'dedupePolicy' => $this->dedupePolicy,
                'body' => $this->body,
                'imageUrl' => $this->imageUrl,
                'iconUrl' => $this->iconUrl,
                'badgeUrl' => $this->badgeUrl,
                'url' => $this->url,
                'tag' => $this->tag,
                'requireInteraction' => $this->requireInteraction,
                // Passed as arrays, **not** pre-encoded. Yii's query builder already encodes an array
                // for a `json` column; handing it a string stores the JSON of a JSON string, and every
                // getter guarded with `is_array()` then silently returns nothing.
                'buttons' => $this->getButtons(),
                'topics' => $this->getTopics(),
                'emailSubject' => $this->emailSubject,
                'emailBody' => $this->emailBody,
                'status' => $this->state,
                'triggerType' => $this->triggerType,
                'triggerConfig' => $this->getTriggerConfig(),
                'sourceElementId' => $this->sourceElementId,
                'templateId' => $this->templateId,
                'targeted' => $this->targeted,
                'delivered' => $this->delivered,
                'failed' => $this->failed,
                'clicked' => $this->clicked,
                'dateLastSent' => Db::prepareDateForDb($this->dateLastSent),
                'createdBy' => $this->createdBy ?? Craft::$app->getUser()->getId(),
            ];

            if ($isNew) {
                $data['id'] = $this->id;
                Db::insert(Table::NOTIFICATIONS, $data);
            } else {
                Db::update(Table::NOTIFICATIONS, $data, ['id' => $this->id]);
            }
        }

        parent::afterSave($isNew);
    }

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['title'], 'required'],
            // 120 characters, because that is roughly where Chrome on Android truncates a title on
            // the lock screen. Longer is not an error, but it is a title nobody will read the end
            // of, so it is refused rather than silently cut.
            [['title'], 'string', 'max' => 120],
            [['body'], 'string', 'max' => 400],
            [['tag'], 'string', 'max' => 120],
            [['emailSubject'], 'string', 'max' => 255],
            [['channels'], 'string'],
            [['dedupePolicy'], 'in', 'range' => ['none', 'fallback', 'first']],
            [['state'], 'in', 'range' => array_keys(self::statuses())],
            [['audienceId', 'sourceElementId', 'templateId'], 'integer'],
            [['requireInteraction'], 'boolean'],
            [['url', 'imageUrl', 'iconUrl', 'badgeUrl'], 'string', 'max' => 1000],
            [['url', 'imageUrl', 'iconUrl', 'badgeUrl'], 'validateSafeUrl'],
            // Not skipped when empty — an empty list is valid, and the rule has to run to look inside a
            // non-empty one.
            [['buttons'], 'validateButtons', 'skipOnEmpty' => false],
        ]);
    }

    /**
     * Whether a URL is safe to put in an `href`, a `src`, or a redirect: http(s), or a path with no
     * scheme at all.
     *
     * Every URL on a notification ends up in at least one of those — the on-site toast's link, the
     * worker's `openWindow()`, the email's button, and `/schedulr/go`'s redirect — and a `javascript:`
     * one in any of them runs script on the site's own origin for whoever clicks. So the rule is an
     * allowlist of two schemes, not a blocklist of the dangerous ones: `JaVaScRiPt:`, `java\tscript:`
     * and `data:` are all the same attack spelled differently.
     *
     * Relative paths (`/news`, `news/today`) pass, because "relative paths resolve against the site"
     * is a documented behaviour of the icon settings.
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return true;
        }

        // Browsers strip tabs and newlines out of a URL before parsing its scheme, so `java\nscript:`
        // is `javascript:` to them. Refusing control characters outright closes that whole family.
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return false;
        }

        if (preg_match('~^https?://[^/?#\\\\]~i', $url)) {
            return true;
        }

        // No scheme at all: nothing before the first `/`, `?` or `#` may contain a colon.
        return !preg_match('~^[^/?#]*:~', $url);
    }

    /** @internal Yii inline validator. */
    public function validateSafeUrl(string $attribute): void
    {
        if (!self::isSafeUrl((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('schedulr', 'Use an http(s) URL or a path on this site.'));
        }
    }

    /** @internal Yii inline validator. */
    public function validateButtons(string $attribute): void
    {
        foreach ($this->getButtons() as $button) {
            if (!self::isSafeUrl($button['url'])) {
                $this->addError($attribute, Craft::t('schedulr', 'Button links must be an http(s) URL or a path on this site.'));

                return;
            }
        }
    }

    /**
     * The push payload as the worker's `push` handler receives it.
     *
     * Kept small on purpose. A push payload has a hard ceiling of about 4KB *after* encryption, and
     * the parts that vary — a long title, a URL with tracking parameters — are exactly the parts
     * that push it over on the one message that mattered.
     *
     * @param array<string, mixed> $overrides Variant fields, when A/B testing.
     * @return array<string, mixed>
     */
    public function toPayload(array $overrides = [], ?int $subscriberId = null, ?int $variantId = null): array
    {
        $title = trim((string)($overrides['title'] ?? $this->title));
        $body = (string)($overrides['body'] ?? $this->body ?? '');
        $image = (string)($overrides['imageUrl'] ?? $this->imageUrl ?? '');
        $url = (string)($overrides['url'] ?? $this->url ?? '');

        $payload = ['title' => $title];

        if ($body !== '') {
            $payload['body'] = $body;
        }

        // The tracked URL, not the destination. Click tracking rides on the URL rather than on a
        // worker-reported event, which is what makes it keep working when PWA's worker — a worker
        // that has never heard of Schedulr — is the one handling the notification.
        $payload['url'] = Plugin::getInstance()->analytics->trackedUrl($url, $this->id, $variantId, $subscriberId);

        foreach ([
            'icon' => $this->iconUrl,
            'badge' => $this->badgeUrl,
            'image' => $image,
            'tag' => $this->tag,
        ] as $key => $value) {
            if (trim((string)$value) !== '') {
                $payload[$key] = $value;
            }
        }

        if ($this->requireInteraction) {
            $payload['requireInteraction'] = true;
        }

        $buttons = $this->getButtons();

        if ($buttons !== []) {
            $analytics = Plugin::getInstance()->analytics;

            // Each button goes through the tracked redirect with its own index, which is how
            // `/schedulr/go` knows to look up that button's destination — and how a button click is
            // counted at all. A button with no URL of its own falls back to the notification's.
            $payload['actions'] = array_map(fn(array $b, int $i) => [
                'action' => 'a' . $i,
                'title' => $b['title'],
                'url' => $b['url'] !== ''
                    ? $analytics->trackedUrl($b['url'], $this->id, $variantId, $subscriberId, 'push', $i)
                    : $payload['url'],
            ], $buttons, array_keys($buttons));
        }

        if ($this->id !== null) {
            $payload['n'] = $this->id;
        }

        if ($variantId !== null) {
            $payload['v'] = $variantId;
        }

        if ($subscriberId !== null) {
            $payload['s'] = $subscriberId;

            // What lets the worker's display and dismiss reports be attributed without letting
            // anybody else write events against this subscriber. See `Analytics::eventSignature()`.
            if ($this->id !== null) {
                $payload['k'] = Plugin::getInstance()->analytics->eventSignature($this->id, $variantId, $subscriberId);
            }
        }

        return $payload;
    }
}
