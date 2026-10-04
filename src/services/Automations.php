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
use craft\helpers\Queue;
use craft\services\Elements;
use craft\services\Users;
use craft\web\View;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Occurrence;
use justinholtweb\schedulr\models\Schedule;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\queue\jobs\FireAutomation;
use justinholtweb\schedulr\queue\jobs\SendBatch;
use Throwable;
use Twig\Extension\SandboxExtension;
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
     * Templates already looked up, per trigger, with when they were read.
     *
     * @var array<string, array{at: int, templates: Notification[]}>
     */
    private array $templateCache = [];

    /**
     * How long a template lookup is trusted for. Short, because a queue worker is a long-lived process
     * and a template edited in the CP must start (or stop) firing without anybody restarting it.
     */
    private const TEMPLATE_CACHE_SECONDS = 60;

    /**
     * Hooks the element events. Called from `Plugin::init()` inside `onInit`.
     *
     * Registered whatever the edition. Lite cannot *create* an automation — the editor sees to that —
     * but an automation saved while the site was Pro keeps firing after a licence lapses: a downgrade,
     * not a wall. A welcome notification that silently stops on renewal day looks exactly like a bug.
     */
    public function register(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;

            if ($element instanceof Entry) {
                $this->onEntrySaved($element);
            } elseif ($element instanceof Notification) {
                // A template was edited in this process. The cache would otherwise keep firing the old
                // version of it — or keep not firing a new one — for up to a minute.
                $this->templateCache = [];
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            if ($event->element instanceof Notification) {
                $this->templateCache = [];
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
            $once = !($config['everySave'] ?? false);

            if ($once && $this->alreadyFired($template->id, $entry->id)) {
                continue;
            }

            // Queued, not fired here. This is the editor's save request: rendering, resolving an
            // audience of fifty thousand and queueing its batches all belong in a worker, not between
            // the editor pressing Save and the page coming back.
            $this->queueFiring($template, $entry, null, $once);
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
            $this->queueFiring($template, $user, (int)$user->id, false);
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
        // No edition check: saved automations keep running after a downgrade (see `register()`).
        $fired = $this->sweepScheduledEntries();

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

    /**
     * Entries that went live on their own, with nobody pressing Save.
     *
     * A future-dated entry becomes live when its post date passes, and nothing in Craft fires an event
     * for that — so the save hook alone misses every scheduled article, which on a publication is most
     * of them. The runner's sweep is the moment somebody asks.
     *
     * Only entries whose post date is **after their last save** are considered: those, and only those,
     * went live without a save. Anything saved while live was the save hook's to fire, and leaving it
     * out here is what stops the sweep racing the hook's queued job before that job has run and
     * written the notification `alreadyFired()` looks for. Bounded below by the template's own
     * creation, so a new automation does not announce last week's articles, and by a week, so the
     * query stays small. Everything that passes is then guarded by the same template-keyed
     * `alreadyFired()` as the hook, and fired inline — inline so that guard is true by the next pass.
     */
    private function sweepScheduledEntries(): int
    {
        $templates = $this->templatesFor(self::TRIGGER_ENTRY_PUBLISHED);

        if ($templates === []) {
            return 0;
        }

        $now = new DateTime();
        $fired = 0;

        foreach ($templates as $template) {
            $since = (new DateTime())->modify('-7 days');

            if ($template->dateCreated !== null && $template->dateCreated > $since) {
                $since = $template->dateCreated;
            }

            try {
                /** @var Entry[] $entries */
                $entries = Entry::find()
                    ->site('*')
                    ->unique()
                    ->status(Entry::STATUS_LIVE)
                    // ATOM, with its offset, so Craft cannot read either bound in the wrong zone.
                    ->postDate(['and', '> ' . $since->format(DATE_ATOM), '<= ' . $now->format(DATE_ATOM)])
                    ->andWhere('[[entries.postDate]] > [[elements.dateUpdated]]')
                    ->limit(200)
                    ->all();
            } catch (Throwable $e) {
                Plugin::warning('Could not look for scheduled entries: ' . $e->getMessage());

                continue;
            }

            $config = $template->getTriggerConfig();

            foreach ($entries as $entry) {
                if (!$this->entryMatches($entry, $config) || $this->alreadyFired($template->id, $entry->id)) {
                    continue;
                }

                if ($this->fire($template, $entry) !== null) {
                    $fired++;
                }
            }
        }

        return $fired;
    }

    // ------------------------------------------------------------------------------- firing

    /**
     * Hands a firing to the queue.
     */
    private function queueFiring(Notification $template, ElementInterface $source, ?int $restrictToUserId, bool $once): void
    {
        try {
            Queue::push(new FireAutomation([
                'templateId' => $template->id,
                'sourceId' => $source->id,
                'sourceType' => $source::class,
                'sourceSiteId' => $source->siteId,
                'restrictToUserId' => $restrictToUserId,
                'once' => $once,
            ]));
        } catch (Throwable $e) {
            // An automation must never take down the save that triggered it.
            Plugin::error('Could not queue the automation on notification #' . $template->id . ': ' . $e->getMessage());
        }
    }

    /**
     * The queued half of a firing. Called by `FireAutomation`.
     *
     * Everything is re-read, because the queue may have waited: the template may have been switched
     * off or deleted, and the entry may have been fired for by another job in the meantime — which is
     * why the once-per-entry guard is checked again here rather than trusted from the request.
     */
    public function fireQueued(
        int $templateId,
        int $sourceId,
        string $sourceType,
        ?int $sourceSiteId,
        ?int $restrictToUserId,
        bool $once,
    ): ?Notification {
        $template = Plugin::getInstance()->notifications->getById($templateId);

        if ($template === null || !$this->isLive($template)) {
            return null;
        }

        if ($once && $this->alreadyFired($templateId, $sourceId)) {
            return null;
        }

        $source = Craft::$app->getElements()->getElementById($sourceId, $sourceType, $sourceSiteId);

        if ($source === null) {
            return null;
        }

        return $this->fire($template, $source, $restrictToUserId);
    }


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
            return trim($this->renderSafely($template, $source, [
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

    /**
     * Renders marketer-authored Twig **in a sandbox**.
     *
     * A notification template is written by whoever may edit notifications, which is not the same
     * person as whoever may edit the site's templates. Rendered unsandboxed, `{{ craft.app.config
     * .general.securityKey }}` in a title puts the site's security key on fifty thousand lock screens
     * — or in a preview only its author sees — and `craft.app` reaches everything else from there.
     *
     * Craft ≥ 5.9 registers Twig's `SandboxExtension` with its own security policy, and offers
     * `renderSandboxedObjectTemplate()`. That method is not used directly because it only sandboxes
     * when the site has opted into `enableTwigSandbox`, which is off by default — so a site that has
     * not would still be wide open. The extension is switched on here explicitly instead, around the
     * same `renderObjectTemplate()` call, which is all that method does when the setting is on.
     *
     * On a Craft without the extension, there is no safe Twig to render, so `renderPlain()` substitutes
     * simple references itself and refuses anything else.
     *
     * @param array<string, mixed> $variables
     */
    private function renderSafely(string $template, ElementInterface $source, array $variables): string
    {
        $view = Craft::$app->getView();
        $twig = $view->getTwig(View::TEMPLATE_MODE_SITE);

        if (!$twig->hasExtension(SandboxExtension::class)) {
            return self::renderPlain($template, $source, $variables);
        }

        /** @var SandboxExtension $sandbox */
        $sandbox = $twig->getExtension(SandboxExtension::class);

        if ($sandbox->isSandboxed()) {
            return $view->renderObjectTemplate($template, $source, $variables, View::TEMPLATE_MODE_SITE);
        }

        $sandbox->enableSandbox();

        try {
            return $view->renderObjectTemplate($template, $source, $variables, View::TEMPLATE_MODE_SITE);
        } finally {
            $sandbox->disableSandbox();
        }
    }

    /**
     * Substitutes `{{ entry.title }}` and `{title}` references without Twig, for a Craft with no sandbox.
     *
     * Deliberately tiny: a dotted path of at most four names, starting at `element`, `entry`, `user`,
     * `object` or the element itself, printing only a scalar or a stringable value. No filters, no
     * functions, no tags — anything else is refused rather than half-rendered, because the point of the
     * fallback is that there is nothing in it to escape from. Names that smell of credentials are
     * refused outright, so `user.password` cannot print a hash either.
     *
     * Public and static so it can be tested without an application.
     *
     * @param array<string, mixed> $variables
     * @throws \RuntimeException when the template uses anything beyond plain references.
     */
    public static function renderPlain(string $template, mixed $source, array $variables = []): string
    {
        if (preg_match('/\{[%#]/', $template)) {
            throw new \RuntimeException('Twig tags need Craft’s Twig sandbox, which this Craft version does not have.');
        }

        $path = '([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,3})';

        $resolve = static function(array $match) use ($source, $variables): string {
            return self::resolvePlain($match[1], $source, $variables);
        };

        $out = (string)preg_replace_callback('/\{\{\s*' . $path . '\s*\}\}/', $resolve, $template);
        // Craft's object-template shorthand: `{title}` means `{{ object.title }}`.
        $out = (string)preg_replace_callback('/(?<!\{)\{' . $path . '\}(?!\})/', $resolve, $out);

        if (str_contains($out, '{{') || str_contains($out, '}}')) {
            throw new \RuntimeException('Only plain references such as {{ entry.title }} can be rendered without Craft’s Twig sandbox.');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function resolvePlain(string $path, mixed $source, array $variables): string
    {
        $names = explode('.', $path);

        if (array_key_exists($names[0], $variables) || $names[0] === 'object') {
            $value = $names[0] === 'object' ? $source : $variables[$names[0]];
            array_shift($names);
        } else {
            $value = $source;
        }

        foreach ($names as $name) {
            if (preg_match('/password|secret|token|key|hash|salt|config|^app$/i', $name)) {
                throw new \RuntimeException("“{$name}” cannot be printed in a notification.");
            }

            if (is_array($value)) {
                $value = $value[$name] ?? null;
            } elseif (is_object($value)) {
                // `isset()` first, so a Yii getter is reached through `__isset()`/`__get()` and an
                // unknown name is a refusal rather than an exception from deep inside a model.
                if (!isset($value->$name)) {
                    throw new \RuntimeException("“{$path}” is not something this element has.");
                }

                $value = $value->$name;
            } else {
                throw new \RuntimeException("“{$path}” is not something this element has.");
            }
        }

        if ($value === null || is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof \Stringable) {
            return (string)$value;
        }

        throw new \RuntimeException("“{$path}” is not printable.");
    }

    private function resolveUrl(Notification $template, ?ElementInterface $source): string
    {
        $url = trim((string)$template->url);

        $url = $url !== ''
            ? $this->render($url, $source)
            // The overwhelmingly common intent: a notification about an entry links to the entry.
            : (string)($source?->getUrl() ?? '');

        $safe = self::safeUrl($url);

        if ($safe === '' && trim($url) !== '') {
            Plugin::warning('Automation on notification #' . $template->id . ' produced a URL that is not http(s) or relative; it was dropped.');
        }

        return $safe;
    }

    /**
     * A rendered URL, or nothing if it is not one a notification may open.
     *
     * The URL is rendered from Twig against an entry an editor wrote, so its scheme is not something to
     * trust: `javascript:` in a push's `data.url` is handed to `clients.openWindow()`, and `data:` or a
     * custom scheme is a phishing page or an app launch from a notification that looks like the site's.
     * Only http(s) and relative URLs pass. Control characters and whitespace are stripped first, because
     * browsers do, and `java\tscript:` is the oldest trick in the list.
     */
    public static function safeUrl(string $url): string
    {
        $url = trim((string)preg_replace('/[\x00-\x20\x7F]+/', '', $url));

        if ($url === '') {
            return '';
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return '';
        }

        // `\\host` and `/\host` are read by browsers as `//host`. A relative URL is fine; a
        // protocol-relative one to another host is a redirect off the site, which is still http(s) and
        // still allowed — but only when it is spelled the way it will be read.
        if (str_starts_with($url, '\\') || str_starts_with($url, '/\\')) {
            return '';
        }

        return $url;
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

        // `sending` before the first batch is queued, exactly as `Sender::dispatch()` does. Left
        // `claimed`, the runner's stall recovery would return this occurrence to pending once the
        // queue fell half an hour behind — and then dispatch it to the notification's *whole*
        // audience rather than to the one person this firing was for.
        $plugin->schedules->markOccurrence($occurrence?->id, Occurrence::STATUS_SENDING);
        $plugin->notifications->addTargeted($notification->id, count($subscriberIds));
        $plugin->schedules->setOccurrenceCounts($occurrence?->id, targeted: count($subscriberIds));
        $plugin->notifications->setState($notification, Notification::STATE_SENDING);

        $batchSize = max(1, $plugin->getSettings()->batchSize);
        $batches = array_chunk($subscriberIds, $batchSize);

        foreach ($batches as $index => $batch) {
            Queue::push(new SendBatch([
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
     * The live templates watching a trigger, memoised briefly.
     *
     * Memoised because the save hook runs for every entry save, and a bulk import or a multi-site
     * propagation is thousands of them in one process. Briefly, because that process may be a queue
     * worker that lives for days.
     *
     * @return Notification[]
     */
    private function templatesFor(string $trigger): array
    {
        $cached = $this->templateCache[$trigger] ?? null;

        if ($cached !== null && (time() - $cached['at']) < self::TEMPLATE_CACHE_SECONDS) {
            return $cached['templates'];
        }

        try {
            // `status(null)` because a template's status is its schedule state, and the state a live
            // template sits in is not one of Craft's enabled/disabled pair. It also lets through a
            // template whose "Schedule is live" is off — a draft — and a disabled element, which is
            // why `isLive()` filters both out: a rule nobody has switched on must not fire. Craft's
            // own drafts and revisions are excluded by the element query's defaults.
            /** @var Notification[] $all */
            $all = Notification::find()->triggerType($trigger)->status(null)->all();
        } catch (Throwable) {
            // Reachable during install, before the tables exist.
            return [];
        }

        $templates = array_values(array_filter($all, fn(Notification $template) => $this->isLive($template)));
        $this->templateCache[$trigger] = ['at' => time(), 'templates' => $templates];

        return $templates;
    }

    /**
     * Whether a template is switched on.
     */
    private function isLive(Notification $template): bool
    {
        return $template->enabled
            && $template->state !== Notification::STATE_DRAFT
            && $template->triggerType !== null
            && !$template->getIsDraft()
            && !$template->getIsRevision()
            && $template->dateDeleted === null;
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
