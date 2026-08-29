<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\models\Settings;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\services\Automations;
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

        $notification->siteId = (int)$request->getBodyParam('siteId', $notification->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
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

        $audienceId = $request->getBodyParam('audienceId');
        $notification->audienceId = $audienceId !== null && $audienceId !== ''
            ? (int)$audienceId
            : null;

        if (Edition::allowsDedupePolicy($isPro)) {
            $notification->dedupePolicy = (string)$request->getBodyParam('dedupePolicy', 'none');
        }

        $trigger = (string)$request->getBodyParam('triggerType', '');
        $mode = (string)$request->getBodyParam('mode', Schedule::MODE_NOW);

        if ($mode === Schedule::MODE_TRIGGER && Edition::allowsAutomations($isPro) && $trigger !== '') {
            $notification->triggerType = $trigger;
            $notification->triggerConfig = $this->triggerConfigFromRequest($trigger);
        } else {
            $notification->triggerType = null;
            $notification->triggerConfig = [];
        }

        // A notification is only ever saved as a draft or as scheduled. `sending`, `sent` and `failed`
        // are outcomes and are never chosen by a human, which is what keeps the state honest.
        $wantsSchedule = (bool)$request->getBodyParam('enabled');
        $notification->state = $wantsSchedule ? Notification::STATE_SCHEDULED : Notification::STATE_DRAFT;

        $notification->setSchedule($this->scheduleFromRequest($notification));

        if (!$plugin->notifications->save($notification)) {
            Craft::$app->getSession()->setError(Craft::t('schedulr', 'Couldn’t save the notification.'));

            return $this->asModelFailure($notification, modelName: 'notification', routeParams: [
                'notification' => $notification,
            ]);
        }

        if (Edition::allowsAbTesting($isPro)) {
            $plugin->notifications->saveVariants($notification->id, $this->variantsFromRequest());
        }

        // Sending is its own permission and its own button. Writing a notification and putting it on a
        // hundred thousand lock screens are different acts, and a save must never do the second.
        if ($request->getBodyParam('sendNow')) {
            $this->requirePermission(Plugin::PERMISSION_SEND_NOTIFICATIONS);

            $plugin->notifications->resetCounters($notification->id);
            $occurrence = $plugin->sender->sendNow($notification);

            Craft::$app->getSession()->setNotice($occurrence !== null
                ? Craft::t('schedulr', 'Notification queued for sending.')
                : Craft::t('schedulr', 'Notification saved, but there was nobody to send it to.'));

            return $this->redirectToPostedUrl($notification);
        }

        Craft::$app->getSession()->setNotice(Craft::t('schedulr', 'Notification saved.'));

        return $this->redirectToPostedUrl($notification);
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

    private function scheduleFromRequest(Notification $notification): Schedule
    {
        $request = Craft::$app->getRequest();
        $schedule = $notification->id !== null
            ? (Plugin::getInstance()->schedules->getForNotification($notification->id) ?? new Schedule())
            : new Schedule();

        $schedule->notificationId = $notification->id;
        $schedule->mode = (string)$request->getBodyParam('mode', Schedule::MODE_NOW);
        $schedule->timezoneMode = (string)$request->getBodyParam('timezoneMode', Schedule::TZ_SITE);
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
