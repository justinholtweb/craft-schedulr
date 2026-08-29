<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\events\UserEvent;
use craft\helpers\Db;
use craft\services\Elements;
use craft\services\Users;
use craft\web\View;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Delivery;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Notifications raised by something happening.
 *
 * Three triggers, chosen because they are the three OneSignal's automatic notifications actually
 * cover: something was published, somebody joined, somebody stopped coming back.
 *
 * The important structural decision: a triggered notification is a **template**, and firing it
 * *duplicates* it into a concrete notification with the copy already rendered. It is tempting to
 * skip that and render at send time instead, but then the ledger has one notification with fifty
 * sends and no way to say which entry each was about — and "which article did we push on Tuesday"
 * is the first question anybody asks. One concrete notification per firing also means the counters,
 * the click rate and the A/B result all mean what they say.
 *
 * The template itself is never sent and never counted. It sits in the list as the rule.
 */
class Automations extends Component
{
    public const TRIGGER_ENTRY_PUBLISHED = 'entry.published';
    public const TRIGGER_USER_REGISTERED = 'user.registered';
    public const TRIGGER_INACTIVITY = 'inactivity';

    /**
     * @return array<string, string>
     */
    public static function triggerOptions(): array
    {
        return [
            self::TRIGGER_ENTRY_PUBLISHED => Craft::t('schedulr', 'An entry is published'),
            self::TRIGGER_USER_REGISTERED => Craft::t('schedulr', 'A user registers'),
            self::TRIGGER_INACTIVITY => Craft::t('schedulr', 'Someone has not visited for a while'),
        ];
    }

    /**
     * Hooks the element events. Called from `Plugin::init()` inside `onInit`.
     */
    public function register(): void
    {
        if (!Edition::allowsAutomations(Plugin::getInstance()->isPro())) {
            return;
        }

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;

            if ($element instanceof Entry) {
                $this->onEntrySaved($element);
            }
        });

        // A user *activating* is registration, and the event lives on the `Users` **service**, not on
        // the `User` element — `User::EVENT_AFTER_SAVE_ELEMENT` fires for every profile edit and every
        // login-count bump, and welcoming somebody once a week is worse than not welcoming them at all.
        Event::on(Users::class, Users::EVENT_AFTER_ACTIVATE_USER, function(UserEvent $event) {
            $this->fireForUser($event->user);
        });
    }

    // ------------------------------------------------------------------------------ entries

    private function onEntrySaved(Entry $entry): void
    {
        // Drafts, revisions and propagating saves are all the same entry being written again. Firing
        // on any of them means a notification per autosave.
        if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating || $entry->resaving) {
            return;
        }

        if ($entry->getStatus() !== Entry::STATUS_LIVE) {
            return;
        }

        foreach ($this->templatesFor(self::TRIGGER_ENTRY_PUBLISHED) as $template) {
            $config = $template->getTriggerConfig();

            if (!$this->entryMatches($entry, $config)) {
                continue;
            }

            // Sent once per entry per template, unless the template says otherwise. Without this,
            // every subsequent edit of a published entry re-notifies everybody — and an editor
            // fixing a typo has no idea they are about to push to fifty thousand phones.
            if (!($config['everySave'] ?? false) && $this->alreadyFired($template->id, $entry->id)) {
                continue;
            }

            $this->fire($template, $entry);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entryMatches(Entry $entry, array $config): bool
    {
        $sectionIds = array_map('intval', (array)($config['sectionIds'] ?? []));

        if ($sectionIds !== [] && !in_array((int)$entry->sectionId, $sectionIds, true)) {
            return false;
        }

        $typeIds = array_map('intval', (array)($config['entryTypeIds'] ?? []));

        if ($typeIds !== [] && !in_array((int)$entry->getType()->id, $typeIds, true)) {
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------------- users

    private function fireForUser(User $user): void
    {
        foreach ($this->templatesFor(self::TRIGGER_USER_REGISTERED) as $template) {
            $config = $template->getTriggerConfig();
            $groupIds = array_map('intval', (array)($config['groupIds'] ?? []));

            if ($groupIds !== []) {
                $userGroupIds = array_map(static fn($g) => (int)$g->id, $user->getGroups());

                if (array_intersect($groupIds, $userGroupIds) === []) {
                    continue;
                }
            }

            // Addressed to one person, not broadcast: a welcome notification sent to the whole list
            // every time somebody registers is the most memorable way to lose a subscriber base.
            $this->fire($template, $user, restrictToUserId: (int)$user->id);
        }
    }

    // --------------------------------------------------------------------------- inactivity

    /**
     * The win-back sweep. Called by the runner once per pass; cheap when nothing matches.
     *
     * @return int How many notifications were raised.
     */
    public function sweep(): int
    {
        if (!Edition::allowsAutomations(Plugin::getInstance()->isPro())) {
            return 0;
        }

        $fired = 0;

        foreach ($this->templatesFor(self::TRIGGER_INACTIVITY) as $template) {
            // A run from a previous pass that has not finished draining yet. Skipped rather than
            // deduped, because until its batches run there are no delivery rows to dedupe *against* —
            // and without this the sweep re-sends to everybody once a minute for as long as the queue
            // is behind.
            if ($this->hasRunInFlight($template->id)) {
                continue;
            }

            $config = $template->getTriggerConfig();
            $days = max(1, (int)($config['days'] ?? 30));

            $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

            $candidates = (new Query())
                ->select(['id'])
                ->from(Table::SUBSCRIBERS)
                ->where(['unsubscribed' => false])
                ->andWhere(['not', ['dateLastSeen' => null]])
                ->andWhere(['<', 'dateLastSeen', $cutoff])
                // Sent once, ever. Keyed on the **template**, joined through the concrete notifications
                // it raised — the template itself never appears in the ledger, so a guard on its own id
                // matches nothing and the win-back arrives every day for a month.
                ->andWhere(['not', ['id' => (new Query())
                    ->select(['d.subscriberId'])
                    ->from(['d' => Table::DELIVERIES])
                    ->innerJoin(['n' => Table::NOTIFICATIONS], '[[n.id]] = [[d.notificationId]]')
                    ->where(['n.templateId' => $template->id])
                    ->andWhere(['not', ['d.subscriberId' => null]]),
                ]])
                ->limit(1000)
                ->column();

            if ($candidates === []) {
                continue;
            }

            $concrete = $this->materialise($template, null);

            if ($concrete === null) {
                continue;
            }

            $this->sendToIds($concrete, array_map('intval', $candidates));
            $fired++;
        }

        return $fired;
    }

    // ------------------------------------------------------------------------------- firing

    /**
     * Turns a template into a concrete notification and sends it.
     */
    public function fire(Notification $template, ?ElementInterface $source = null, ?int $restrictToUserId = null): ?Notification
    {
        try {
            $concrete = $this->materialise($template, $source);

            if ($concrete === null) {
                return null;
            }

            if ($restrictToUserId !== null) {
                $ids = array_map('intval', (new Query())
                    ->select(['id'])
                    ->from(Table::SUBSCRIBERS)
                    ->where(['userId' => $restrictToUserId, 'unsubscribed' => false])
                    ->column());

                $this->sendToIds($concrete, $ids);

                return $concrete;
            }

            Plugin::getInstance()->sender->sendNow($concrete);

            return $concrete;
        } catch (Throwable $e) {
            // An automation must never take down the save that triggered it. An editor publishing an
            // article should not see a 500 because a notification template has a bad Twig tag.
            Plugin::error('Automation on notification #' . $template->id . ' failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Copies the template, rendering its Twig against the source element.
     */
    private function materialise(Notification $template, ?ElementInterface $source): ?Notification
    {
        $concrete = new Notification();
        $concrete->siteId = $template->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $concrete->audienceId = $template->audienceId;
        $concrete->setChannels($template->getChannels());
        $concrete->dedupePolicy = $template->dedupePolicy;

        $concrete->title = $this->render((string)$template->title, $source);
        $concrete->body = $this->render((string)$template->body, $source);
        $concrete->emailSubject = $this->render((string)$template->emailSubject, $source);
        $concrete->emailBody = $this->render((string)$template->emailBody, $source);
        $concrete->url = $this->resolveUrl($template, $source);
        $concrete->iconUrl = $template->iconUrl;
        $concrete->badgeUrl = $template->badgeUrl;
        $concrete->imageUrl = $template->imageUrl;
        $concrete->tag = $template->tag;
        $concrete->requireInteraction = $template->requireInteraction;
        $concrete->buttons = $template->getButtons();
        $concrete->topics = $template->getTopics();
        $concrete->sourceElementId = $source?->id;
        $concrete->templateId = $template->id;

        // The concrete copy has no trigger of its own. Leaving the trigger on it would make the
        // notification a template as well, and every firing would breed.
        $concrete->triggerType = null;
        $concrete->state = Notification::STATE_SCHEDULED;

        $schedule = new Schedule(['mode' => Schedule::MODE_NOW]);
        $concrete->setSchedule($schedule);

        if ($concrete->title === '') {
            Plugin::warning('Automation on notification #' . $template->id . ' produced an empty title; nothing sent.');

            return null;
        }

        if (!Plugin::getInstance()->notifications->save($concrete)) {
            Plugin::error('Could not save the notification raised by template #' . $template->id . '.');

            return null;
        }

        return $concrete;
    }

    /**
     * Renders one field's Twig against the source element.
     *
     * Note that `renderObjectTemplate()` calls its subject **`object`**, not `element` — so the
     * element is passed in as an extra variable too, because `{{ entry.title }}` and
     * `{{ element.title }}` are what everybody actually types.
     */
    private function render(string $template, ?ElementInterface $source): string
    {
        $template = trim($template);

        if ($template === '' || $source === null || !str_contains($template, '{')) {
            return $template;
        }

        try {
            return trim(Craft::$app->getView()->renderObjectTemplate($template, $source, [
                'element' => $source,
                'entry' => $source instanceof Entry ? $source : null,
                'user' => $source instanceof User ? $source : null,
            ]));
        } catch (Throwable $e) {
            Plugin::warning('Could not render an automation field: ' . $e->getMessage());

            // The unrendered template is worse than nothing — nobody wants `{{ entry.title }}` on
            // their lock screen — so a failed render produces an empty string, which `materialise()`
            // treats as a reason not to send at all when it is the title.
            return '';
        }
    }

    private function resolveUrl(Notification $template, ?ElementInterface $source): string
    {
        $url = trim((string)$template->url);

        if ($url !== '') {
            return $this->render($url, $source);
        }

        // The overwhelmingly common intent: a notification about an entry links to the entry.
        return (string)($source?->getUrl() ?? '');
    }

    /**
     * @param int[] $subscriberIds
     */
    private function sendToIds(Notification $notification, array $subscriberIds): void
    {
        if ($subscriberIds === []) {
            Plugin::getInstance()->notifications->setState($notification, Notification::STATE_SENT);

            return;
        }

        $plugin = Plugin::getInstance();
        $occurrence = $plugin->schedules->createImmediateOccurrence($notification);

        $plugin->notifications->addTargeted($notification->id, count($subscriberIds));
        $plugin->schedules->setOccurrenceCounts($occurrence?->id, targeted: count($subscriberIds));
        $plugin->notifications->setState($notification, Notification::STATE_SENDING);

        $batchSize = max(1, $plugin->getSettings()->batchSize);
        $batches = array_chunk($subscriberIds, $batchSize);

        foreach ($batches as $index => $batch) {
            \craft\helpers\Queue::push(new \justinholtweb\schedulr\queue\jobs\SendBatch([
                'occurrenceId' => $occurrence?->id,
                'notificationId' => $notification->id,
                'subscriberIds' => $batch,
                'batchNumber' => $index + 1,
                'batchCount' => count($batches),
            ]));
        }
    }

    // ----------------------------------------------------------------------------- lookups

    /**
     * @return Notification[]
     */
    private function templatesFor(string $trigger): array
    {
        try {
            /** @var Notification[] */
            return Notification::find()->triggerType($trigger)->status(null)->all();
        } catch (Throwable) {
            // Reachable during install, before the tables exist.
            return [];
        }
    }

    /**
     * Whether a notification this template raised is still going out.
     */
    private function hasRunInFlight(int $templateId): bool
    {
        return (new Query())
            ->from(Table::NOTIFICATIONS)
            ->where(['templateId' => $templateId])
            ->andWhere(['status' => [Notification::STATE_SCHEDULED, Notification::STATE_SENDING]])
            ->exists();
    }

    private function alreadyFired(int $templateId, ?int $sourceElementId): bool
    {
        if ($sourceElementId === null) {
            return false;
        }

        // Looks for a *concrete* notification this template raised for this source, not for a
        // delivery: a firing that matched nobody still counts as having happened, or a section with
        // no subscribers re-fires on every save forever. Keyed on the template as well as the
        // source, so two templates watching one section both get their turn.
        return (new Query())
            ->from(Table::NOTIFICATIONS)
            ->where(['sourceElementId' => $sourceElementId, 'templateId' => $templateId])
            ->exists();
    }
}
