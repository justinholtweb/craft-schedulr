<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Automations;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The notification index and editor.
 *
 * The index is Craft's element index; only the editor is hand-built, because a notification is
 * composed of things Craft has no field type for — a channel set, a recurrence rule, an A/B split — and
 * a slideout with twenty custom inputs is worse than a page.
 */
class NotificationsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_NOTIFICATIONS);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('schedulr/notifications/_index', [
            'title' => Craft::t('schedulr', 'Notifications'),
            'elementType' => Notification::class,
            'health' => $plugin->runner->health(),
            'canSend' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SEND_NOTIFICATIONS),
        ]);
    }

    public function actionEdit(?int $notificationId = null, ?Notification $notification = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_NOTIFICATIONS);

        $plugin = Plugin::getInstance();
        $isPro = $plugin->isPro();

        if ($notification === null) {
            if ($notificationId !== null) {
                $notification = $plugin->notifications->getById($notificationId);

                if ($notification === null) {
                    throw new NotFoundHttpException('Notification not found.');
                }
            } else {
                $notification = new Notification();
                $notification->siteId = Craft::$app->getSites()->getCurrentSite()->id;
                $notification->iconUrl = $plugin->getSettings()->defaultIcon;
                $notification->badgeUrl = $plugin->getSettings()->defaultBadge;
            }
        }

        $schedule = $notification->getSchedule();

        return $this->renderTemplate('schedulr/notifications/_edit', [
            'notification' => $notification,
            'schedule' => $schedule,
            'variants' => $notification->getVariants(),
            'audiences' => $plugin->audiences->getAll(),
            'isPro' => $isPro,
            'canSend' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SEND_NOTIFICATIONS),
            'channelOptions' => Notification::channelOptions(),
            'modeOptions' => Schedule::modeOptions(),
            'frequencyOptions' => Schedule::frequencyOptions(),
            'timezoneModeOptions' => Schedule::timezoneModeOptions(),
            'dedupeOptions' => Settings::dedupeOptions(),
            'triggerOptions' => Automations::triggerOptions(),
            'weekdays' => Craft::$app->getLocale()->getWeekDayNames('abbreviated'),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
            'upcoming' => $notification->id !== null
                ? $plugin->schedules->getUpcoming(12, $notification->id)
                : [],
            'funnel' => $notification->id !== null && Edition::allowsClickTracking($isPro)
                ? $plugin->analytics->funnel($notification->id)
                : [],
            'title' => $notification->id !== null
                ? $notification->title
                : Craft::t('schedulr', 'New notification'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_NOTIFICATIONS);

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $isPro = $plugin->isPro();

        $notificationId = $request->getBodyParam('notificationId');
        $notification = $notificationId
            ? $plugin->notifications->getById((int)$notificationId)
            : new Notification();

        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            throw new ForbiddenHttpException();
        }

        // Taken before a single attribute is assigned: what matters is what the notification *was*.
        $wasArmed = $notification->id !== null && self::isArmed($notification);

        // Checked before the save rather than after it. Refusing a send once the save has gone
        // through leaves the author with a changed notification and an error, which reads as "nothing
        // happened" when something did.
        if ($request->getBodyParam('sendNow')) {
            $this->requirePermission(Plugin::PERMISSION_SEND_NOTIFICATIONS);
        }

        // The site being written to must be one this user may edit — and so must the one the
        // notification is being moved away from.
        if ($notification->siteId !== null) {
            $this->requireSiteAccess((int)$notification->siteId);
        }

        $notification->siteId = (int)$request->getBodyParam('siteId', $notification->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
        $this->requireSiteAccess($notification->siteId);

        $notification->title = (string)$request->getBodyParam('title', $notification->title);
        $notification->body = (string)$request->getBodyParam('body', $notification->body);
        $notification->url = (string)$request->getBodyParam('url', $notification->url);
        $notification->imageUrl = (string)$request->getBodyParam('imageUrl', $notification->imageUrl);
        $notification->iconUrl = (string)$request->getBodyParam('iconUrl', $notification->iconUrl);
        $notification->badgeUrl = (string)$request->getBodyParam('badgeUrl', $notification->badgeUrl);
        $notification->tag = (string)$request->getBodyParam('tag', $notification->tag);
        $notification->requireInteraction = (bool)$request->getBodyParam('requireInteraction');
        $notification->emailSubject = (string)$request->getBodyParam('emailSubject', $notification->emailSubject);
        $notification->emailBody = (string)$request->getBodyParam('emailBody', $notification->emailBody);
        $notification->setChannels((array)$request->getBodyParam('channels', ['push']));
        $notification->buttons = $this->buttonsFromRequest();
        $notification->topics = $this->listFromRequest('topics');

        // Every Pro-only field below is written **only when the edition allows changing it**. Lite's
        // editor does not post them, and reading "absent" as "cleared" would make one save on a lapsed
        // licence erase the segment, the automation and the dedupe policy a Pro licence set up — a
        // wall dressed as a downgrade. Left alone, they keep working wherever the services still
        // honour them, and come back intact on renewal.
        if (Edition::allowsSegments($isPro)) {
            $audienceId = $request->getBodyParam('audienceId');
            $notification->audienceId = $audienceId !== null && $audienceId !== ''
                ? (int)$audienceId
                : null;
        }

        if (Edition::allowsDedupePolicy($isPro)) {
            $notification->dedupePolicy = (string)$request->getBodyParam('dedupePolicy', 'none');
        }

        $trigger = (string)$request->getBodyParam('triggerType', '');
        $mode = (string)$request->getBodyParam('mode', Schedule::MODE_NOW);

        if (Edition::allowsAutomations($isPro)) {
            if ($mode === Schedule::MODE_TRIGGER && $trigger !== '') {
                $notification->triggerType = $trigger;
                $notification->triggerConfig = $this->triggerConfigFromRequest($trigger);
            } else {
                $notification->triggerType = null;
                $notification->triggerConfig = [];
            }
        }

        // A notification is only ever saved as a draft or as scheduled. `sending`, `sent` and `failed`
        // are outcomes and are never chosen by a human, which is what keeps the state honest.
        $wantsSchedule = (bool)$request->getBodyParam('enabled');
        $notification->state = $wantsSchedule ? Notification::STATE_SCHEDULED : Notification::STATE_DRAFT;

        // Scheduling *is* sending, later. Without this gate, someone with only "manage" could put a
        // notification on every lock screen by ticking "enabled" or choosing a trigger, and the send
        // permission would guard only the button labelled "Send now".
        $downgraded = self::enforceSendPermission($notification, $wasArmed, $user);

        $notification->setSchedule($this->scheduleFromRequest($notification, $isPro));

        $variants = Edition::allowsAbTesting($isPro) ? $this->variantsFromRequest() : [];

        foreach ($variants as $variant) {
            // Variants are saved after the element, so their URLs are checked here or not at all —
            // and a variant URL reaches the same `href` and redirect as the notification's own.
            if (!Notification::isSafeUrl((string)$variant['url']) || !Notification::isSafeUrl((string)$variant['imageUrl'])) {
                $notification->validate();
                $notification->addError('variants', Craft::t('schedulr', 'Variant links and images must be an http(s) URL or a path on this site.'));
                Craft::$app->getSession()->setError(Craft::t('schedulr', 'Couldn’t save the notification.'));

                return $this->asModelFailure($notification, modelName: 'notification', routeParams: [
                    'notification' => $notification,
                ]);
            }
        }

        if (!$plugin->notifications->save($notification)) {
            Craft::$app->getSession()->setError(Craft::t('schedulr', 'Couldn’t save the notification.'));

            return $this->asModelFailure($notification, modelName: 'notification', routeParams: [
                'notification' => $notification,
            ]);
        }

        if (Edition::allowsAbTesting($isPro)) {
            $plugin->notifications->saveVariants($notification->id, $variants);
        }

        // Sending is its own permission and its own button. Writing a notification and putting it on a
        // hundred thousand lock screens are different acts, and a save must never do the second. (The
        // permission itself was checked before the save.)
        if ($request->getBodyParam('sendNow')) {
            $plugin->notifications->resetCounters($notification->id);
            $occurrence = $plugin->sender->sendNow($notification);

            Craft::$app->getSession()->setNotice($occurrence !== null
                ? Craft::t('schedulr', 'Notification queued for sending.')
                : Craft::t('schedulr', 'Notification saved, but there was nobody to send it to.'));

            return $this->redirectToPostedUrl($notification);
        }

        Craft::$app->getSession()->setNotice($downgraded
            ? Craft::t('schedulr', 'Notification saved as a draft. Scheduling it needs permission to send notifications.')
            : Craft::t('schedulr', 'Notification saved.'));

        return $this->redirectToPostedUrl($notification);
    }

    /**
     * Whether a notification will go out without anybody pressing anything else: it is scheduled, it
     * is mid-send, or an automation is attached to it.
     */
    public static function isArmed(Notification $notification): bool
    {
        return in_array($notification->state, [Notification::STATE_SCHEDULED, Notification::STATE_SENDING], true)
            || ($notification->triggerType !== null && $notification->triggerType !== '');
    }

    /**
     * Holds a save by someone without the send permission to a draft.
     *
     * Two cases. A notification that is **already armed** cannot be edited at all without the send
     * permission — rewriting the copy of something already scheduled is sending your own words under
     * somebody else's approval — so that is a 403. Otherwise the save goes through, but as a disabled
     * draft with no trigger, whatever was posted: the author's words are kept and a sender can arm it.
     *
     * Public and static so it can be exercised without a request.
     *
     * @return bool Whether the requested state was downgraded.
     * @throws ForbiddenHttpException
     */
    public static function enforceSendPermission(Notification $notification, bool $wasArmed, User $user): bool
    {
        if ($user->can(Plugin::PERMISSION_SEND_NOTIFICATIONS)) {
            return false;
        }

        if ($wasArmed) {
            throw new ForbiddenHttpException('Editing a scheduled or automated notification needs permission to send notifications.');
        }

        $downgraded = self::isArmed($notification);

        // Not armed before, so there was no stored trigger to preserve: clearing it restores what was
        // there.
        $notification->state = Notification::STATE_DRAFT;
        $notification->triggerType = null;
        $notification->triggerConfig = [];

        return $downgraded;
    }

    /**
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    private function requireSiteAccess(int $siteId): void
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null) {
            throw new BadRequestHttpException('Invalid site.');
        }

        // Craft only grants `editSite:*` permissions on a multi-site install; on a single site every
        // CP user implicitly has it, and requiring it would lock everybody out.
        if (Craft::$app->getIsMultiSite()) {
            $this->requirePermission('editSite:' . $site->uid);
        }
    }

    /**
     * Sends an existing notification without going through the editor.
     */
    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SEND_NOTIFICATIONS);

        $plugin = Plugin::getInstance();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('notificationId');
        $notification = $plugin->notifications->getById($id);

        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        if ($notification->state === Notification::STATE_SENDING) {
            // Refused rather than queued behind it. A second send while the first is still draining
            // notifies everybody twice, and the person clicking has no way to see that the queue is
            // still working.
            return $this->asFailure(Craft::t('schedulr', 'This notification is still sending.'));
        }

        $plugin->notifications->resetCounters($notification->id);
        $occurrence = $plugin->sender->sendNow($notification);

        return $this->asSuccess(
            $occurrence !== null
                ? Craft::t('schedulr', 'Sending {count} notification(s).', ['count' => $occurrence->targeted])
                : Craft::t('schedulr', 'There was nobody to send to.'),
            ['occurrenceId' => $occurrence?->id],
        );
    }

    /**
     * A preview of who this notification would reach, without sending anything.
     *
     * The single most useful thing in the editor. "4,812 people" beside the send button is what turns
     * a send from a leap into a decision.
     */
    public function actionPreviewAudience(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $notification = new Notification();
        $notification->siteId = (int)$request->getBodyParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $notification->setChannels((array)$request->getBodyParam('channels', ['push']));
        $notification->topics = $this->listFromRequest('topics');

        $audienceId = $request->getBodyParam('audienceId');
        $notification->audienceId = $audienceId !== null && $audienceId !== '' ? (int)$audienceId : null;

        $ids = $plugin->sender->resolveAudience($notification);

        return $this->asJson([
            'count' => count($ids),
            'label' => Craft::t('schedulr', '{count, plural, =0{Nobody} =1{1 person} other{# people}}', [
                'count' => count($ids),
            ]),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE_NOTIFICATIONS);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('notificationId');
        $notification = Plugin::getInstance()->notifications->getById($id);

        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        if (!$notification->canDelete(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        Craft::$app->getElements()->deleteElement($notification);

        return $this->asSuccess(Craft::t('schedulr', 'Notification deleted.'), redirect: 'schedulr/notifications');
    }

    /** Stops a schedule without deleting the notification. */
    public function actionCancel(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SEND_NOTIFICATIONS);

        $plugin = Plugin::getInstance();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('notificationId');
        $notification = $plugin->notifications->getById($id);

        if ($notification === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        $cancelled = $plugin->schedules->cancelPending($id);
        $plugin->notifications->setState($notification, Notification::STATE_DRAFT);

        return $this->asSuccess(Craft::t('schedulr', '{count} scheduled send(s) cancelled.', ['count' => $cancelled]));
    }

    // ------------------------------------------------------------------------ request shaping

    private function scheduleFromRequest(Notification $notification, bool $isPro): Schedule
    {
        $request = Craft::$app->getRequest();
        $schedule = $notification->id !== null
            ? (Plugin::getInstance()->schedules->getForNotification($notification->id) ?? new Schedule())
            : new Schedule();

        $schedule->notificationId = $notification->id;
        $schedule->mode = (string)$request->getBodyParam('mode', Schedule::MODE_NOW);

        // Per-subscriber time zones are Pro. Lite's editor shows the field disabled, so it is not
        // posted, and the stored mode is kept rather than reset — see the Pro fields in `actionSave()`.
        if (Edition::allowsPerSubscriberTimezone($isPro) || $schedule->id === null) {
            $schedule->timezoneMode = (string)$request->getBodyParam('timezoneMode', Schedule::TZ_SITE);
        }

        $schedule->frequency = ($f = (string)$request->getBodyParam('frequency', '')) !== '' ? $f : null;
        $schedule->interval = max(1, (int)$request->getBodyParam('interval', 1));
        $schedule->timeOfDay = ($t = (string)$request->getBodyParam('timeOfDay', '')) !== '' ? $t : null;
        $schedule->maxOccurrences = ($m = $request->getBodyParam('maxOccurrences')) !== null && $m !== ''
            ? (int)$m
            : null;

        $schedule->byWeekday = array_map('intval', (array)$request->getBodyParam('byWeekday', []));
        $schedule->byMonthDay = array_map('intval', (array)$request->getBodyParam('byMonthDay', []));
        $schedule->exclusions = $this->listFromRequest('exclusions');

        // Craft's date/time fields post **arrays**, which no scalar cast accepts, so they go through
        // the helper rather than being read directly.
        foreach (['sendAt', 'startDate', 'endDate'] as $attribute) {
            $value = $request->getBodyParam($attribute);
            $schedule->$attribute = ($value === null || $value === '')
                ? null
                : (DateTimeHelper::toDateTime($value) ?: null);
        }

        return $schedule;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variantsFromRequest(): array
    {
        $posted = Craft::$app->getRequest()->getBodyParam('variants');

        if (!is_array($posted)) {
            return [];
        }

        $out = [];

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string)($row['title'] ?? ''));
            $body = trim((string)($row['body'] ?? ''));

            // A row where the author typed nothing is a row they added and abandoned, not an arm
            // testing "no copy at all".
            if ($title === '' && $body === '' && trim((string)($row['url'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'id' => ($id = $row['id'] ?? null) !== null && $id !== '' ? (int)$id : null,
                'label' => trim((string)($row['label'] ?? '')),
                'share' => max(1, (int)($row['share'] ?? 50)),
                'title' => $title !== '' ? $title : null,
                'body' => $body !== '' ? $body : null,
                'imageUrl' => trim((string)($row['imageUrl'] ?? '')) ?: null,
                'url' => trim((string)($row['url'] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private function buttonsFromRequest(): array
    {
        $posted = Craft::$app->getRequest()->getBodyParam('buttons');

        if (!is_array($posted)) {
            return [];
        }

        $out = [];

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string)($row['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $out[] = ['title' => $title, 'url' => trim((string)($row['url'] ?? ''))];
        }

        return array_slice($out, 0, 2);
    }

    /**
     * @return string[]
     */
    private function listFromRequest(string $param): array
    {
        $value = Craft::$app->getRequest()->getBodyParam($param);

        if (is_string($value)) {
            $value = preg_split('/[,\n]/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($v) => trim((string)$v),
            $value,
        ), static fn(string $v) => $v !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function triggerConfigFromRequest(string $trigger): array
    {
        $request = Craft::$app->getRequest();

        return match ($trigger) {
            Automations::TRIGGER_ENTRY_PUBLISHED => [
                'sectionIds' => array_map('intval', (array)$request->getBodyParam('triggerSectionIds', [])),
                'entryTypeIds' => array_map('intval', (array)$request->getBodyParam('triggerEntryTypeIds', [])),
                'everySave' => (bool)$request->getBodyParam('triggerEverySave'),
            ],
            Automations::TRIGGER_USER_REGISTERED => [
                'groupIds' => array_map('intval', (array)$request->getBodyParam('triggerGroupIds', [])),
            ],
            Automations::TRIGGER_INACTIVITY => [
                'days' => max(1, (int)$request->getBodyParam('triggerDays', 30)),
            ],
            default => [],
        };
    }
}
