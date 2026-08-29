<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\models\Audience;
use justinholtweb\schedulr\models\Delivery;
use justinholtweb\schedulr\Plugin;

/**
 * Segments, and the SQL they compile to.
 *
 * Craft's condition builder is the obvious tool and the wrong one here, because subscribers are not
 * elements: there is no element query to attach rules to, and every rule in this class has to become
 * a `WHERE` clause over one table. Building it explicitly buys two things that matter more than
 * reusing the CP widget:
 *
 * - **A segment resolves in one query** however many rules it has, so a 100,000-row list segments in
 *   milliseconds instead of being loaded into PHP and filtered.
 * - **A segment can be *counted* cheaply**, which is what lets the CP put "4,812 people" next to the
 *   name. A segment whose size nobody knows is a segment nobody dares send to.
 *
 * Every rule is compiled here and nowhere else. Adding one means adding a case to `compileRule()`
 * and an entry to `ruleTypes()` — and if it cannot be expressed as SQL over the subscriber table,
 * it does not belong in a segment.
 */
class Audiences extends Component
{
    // -------------------------------------------------------------------------------- CRUD

    public function getById(?int $id): ?Audience
    {
        if ($id === null) {
            return null;
        }

        $row = (new Query())->from(Table::AUDIENCES)->where(['id' => $id])->one();

        return $row ? new Audience($row) : null;
    }

    public function getByHandle(string $handle, ?int $siteId = null): ?Audience
    {
        $query = (new Query())->from(Table::AUDIENCES)->where(['handle' => $handle]);

        if ($siteId !== null) {
            $query->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
        }

        $row = $query->one();

        return $row ? new Audience($row) : null;
    }

    /**
     * @return Audience[]
     */
    public function getAll(?int $siteId = null): array
    {
        $query = (new Query())->from(Table::AUDIENCES)->orderBy(['name' => SORT_ASC]);

        if ($siteId !== null) {
            $query->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
        }

        return array_map(static fn(array $row) => new Audience($row), $query->all());
    }

    public function count(): int
    {
        return (int)(new Query())->from(Table::AUDIENCES)->count();
    }

    public function save(Audience $audience, bool $runValidation = true): bool
    {
        if ($runValidation && !$audience->validate()) {
            return false;
        }

        $data = [
            'siteId' => $audience->siteId,
            'name' => $audience->name,
            'handle' => $audience->handle,
            'description' => $audience->description,
            // An array, not a pre-encoded string — see helpers\Data.
            'condition' => $audience->getCondition(),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ];

        $db = Craft::$app->getDb();

        if ($audience->id !== null) {
            $db->createCommand()->update(Table::AUDIENCES, $data, ['id' => $audience->id])->execute();
        } else {
            $db->createCommand()->insert(Table::AUDIENCES, $data + [
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $audience->id = (int)$db->getLastInsertID();
        }

        // Counted on save so the index never has to. A segment saved and then never sent to still
        // shows a real number, which is how an author finds out their rules match nobody *before*
        // scheduling a campaign to them.
        $this->recount($audience);

        return true;
    }

    public function delete(int $id): bool
    {
        // Notifications pointing at it have `audienceId` set to null by the foreign key, which means
        // they fall back to "everyone" rather than silently sending to nobody. That is the safer of
        // the two wrong answers, and the CP flags any notification whose audience vanished.
        return Craft::$app->getDb()->createCommand()->delete(Table::AUDIENCES, ['id' => $id])->execute() > 0;
    }

    // ---------------------------------------------------------------------------- resolving

    /**
     * How many subscribers this segment currently matches.
     */
    public function resolveCount(Audience $audience): int
    {
        $query = (new Query())->select(['id'])->from(Table::SUBSCRIBERS)->where(['unsubscribed' => false]);

        if ($audience->siteId !== null) {
            $query->andWhere(['siteId' => $audience->siteId]);
        }

        $this->applyCondition($query, $audience);

        // `count()` comes back as a string from PDO on some drivers, and a string compared against an
        // int limit is a comparison nobody meant to write.
        return (int)$query->count();
    }

    public function recount(Audience $audience): int
    {
        $count = $this->resolveCount($audience);

        Db::update(Table::AUDIENCES, [
            'cachedCount' => $count,
            'dateCounted' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $audience->id]);

        $audience->cachedCount = $count;
        $audience->dateCounted = new DateTime();

        return $count;
    }

    /**
     * @return int[]
     */
    public function resolveIds(Audience $audience, ?int $siteId = null): array
    {
        $query = (new Query())->select(['id'])->from(Table::SUBSCRIBERS)->where(['unsubscribed' => false]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $this->applyCondition($query, $audience);

        return array_map('intval', $query->column());
    }

    /**
     * Adds a segment's rules to a subscriber query.
     *
     * The entry point `Sender` uses, so a segment narrows an audience that has already been narrowed
     * by channel reachability rather than being resolved separately and intersected in PHP.
     */
    public function applyCondition(Query $query, Audience $audience): void
    {
        $condition = $audience->getCondition();

        if ($condition['rules'] === []) {
            return;
        }

        $clauses = [$condition['match'] === 'any' ? 'or' : 'and'];

        foreach ($condition['rules'] as $rule) {
            $compiled = $this->compileRule($rule);

            if ($compiled !== null) {
                $clauses[] = $compiled;
            }
        }

        if (count($clauses) === 1) {
            return;
        }

        $query->andWhere($clauses);
    }

    // --------------------------------------------------------------------------- compiling

    /**
     * One rule, as a Yii condition array.
     *
     * Returns null for anything unrecognised. **Null means the rule is dropped, not that the segment
     * matches nobody** — a rule left behind by an uninstalled feature should widen the audience back
     * to what it was, not silently stop a campaign from going out with no error anywhere.
     *
     * @param array<string, mixed> $rule
     */
    private function compileRule(array $rule): ?array
    {
        $type = (string)($rule['type'] ?? '');
        $operator = (string)($rule['operator'] ?? 'eq');
        $value = $rule['value'] ?? null;

        return match ($type) {
            'site' => $this->inList('siteId', $value, $operator, true),
            'language' => $this->inList('language', $value, $operator),
            'timezone' => $this->inList('timezone', $value, $operator),
            'platform' => $this->inList('platform', $value, $operator),
            'country' => $this->inList('country', $value, $operator),

            'visits' => $this->numeric('visits', $value, $operator),
            'notifiedCount' => $this->numeric('notifiedCount', $value, $operator),

            // "Seen in the last N days" is phrased as a *duration* rather than a date, because a
            // segment saved with an absolute date silently stops matching anybody a month later.
            'lastSeenDays' => $this->relativeDate('dateLastSeen', $value, $operator),
            'firstSeenDays' => $this->relativeDate('dateFirstSeen', $value, $operator),
            'subscribedDays' => $this->relativeDate('dateSubscribed', $value, $operator),
            'notifiedDays' => $this->relativeDate('dateLastNotified', $value, $operator),

            'pushable' => $this->boolish('endpointHash', (bool)$value),
            'emailable' => $this->boolish('email', (bool)$value),
            'declined' => $this->boolish('dateDeclined', (bool)$value),
            'registered' => $this->boolish('userId', (bool)$value),

            'tag' => $this->tag($value, $operator),
            'userGroup' => $this->userGroup($value, $operator),

            // The one rule that is not about the subscriber: "has, or has not, been sent this".
            'received' => $this->received($value, $operator),

            default => null,
        };
    }

    private function inList(string $column, mixed $value, string $operator, bool $numeric = false): ?array
    {
        $values = array_values(array_filter(array_map(
            $numeric ? 'intval' : 'strval',
            is_array($value) ? $value : [$value],
        ), static fn($v) => $v !== '' && $v !== 0));

        if ($values === []) {
            return null;
        }

        return $operator === 'ne'
            ? ['not', [$column => $values]]
            : [$column => $values];
    }

    private function numeric(string $column, mixed $value, string $operator): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }

        return match ($operator) {
            'gte' => ['>=', $column, (int)$value],
            'lte' => ['<=', $column, (int)$value],
            'gt' => ['>', $column, (int)$value],
            'lt' => ['<', $column, (int)$value],
            'ne' => ['not', [$column => (int)$value]],
            default => [$column => (int)$value],
        };
    }

    /**
     * A date column against "N days ago".
     *
     * `within` is the common case and the one worth being careful about: it has to exclude rows whose
     * date is null, or "seen in the last 7 days" quietly includes every subscriber that has never
     * been seen at all — which on a fresh install is all of them.
     */
    private function relativeDate(string $column, mixed $value, string $operator): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify('-' . (int)$value . ' days'));

        return match ($operator) {
            'before' => ['and', ['not', [$column => null]], ['<', $column, $cutoff]],
            default => ['and', ['not', [$column => null]], ['>=', $column, $cutoff]],
        };
    }

    /**
     * "Has a value" / "has no value", for the columns whose presence *is* the fact.
     *
     * `isset()` semantics rather than a boolean column: `endpointHash IS NOT NULL` is what "is
     * subscribed to push" means, and storing a separate flag alongside it would give two sources of
     * truth that drift the first time a device is retired.
     */
    private function boolish(string $column, bool $wanted): array
    {
        return $wanted ? ['not', [$column => null]] : [$column => null];
    }

    private function tag(mixed $value, string $operator): ?array
    {
        $tags = array_values(array_filter(array_map('strval', is_array($value) ? $value : [$value])));

        if ($tags === []) {
            return null;
        }

        $subquery = (new Query())
            ->select(['subscriberId'])
            ->from(Table::SUBSCRIBER_TAGS)
            ->where(['tag' => $tags]);

        return $operator === 'ne'
            ? ['not', ['id' => $subquery]]
            : ['id' => $subquery];
    }

    private function userGroup(mixed $value, string $operator): ?array
    {
        $groupIds = array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value])));

        if ($groupIds === []) {
            return null;
        }

        $subquery = (new Query())
            ->select(['userId'])
            ->from(CraftTable::USERGROUPS_USERS)
            ->where(['groupId' => $groupIds]);

        return $operator === 'ne'
            ? ['not', ['userId' => $subquery]]
            : ['userId' => $subquery];
    }

    /**
     * Whether this person has already been sent a particular notification.
     *
     * The rule that makes a follow-up campaign possible — "everyone who got the announcement" — and
     * the one that makes a win-back possible: "everyone who did *not*".
     */
    private function received(mixed $value, string $operator): ?array
    {
        $ids = array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value])));

        if ($ids === []) {
            return null;
        }

        $subquery = (new Query())
            ->select(['subscriberId'])
            ->from(Table::DELIVERIES)
            ->where(['notificationId' => $ids])
            ->andWhere(['status' => [Delivery::STATUS_DELIVERED, Delivery::STATUS_QUEUED]])
            ->andWhere(['not', ['subscriberId' => null]]);

        return $operator === 'ne'
            ? ['not', ['id' => $subquery]]
            : ['id' => $subquery];
    }

    // ------------------------------------------------------------------------ CP metadata

    /**
     * The rules the CP offers, and the shape of each.
     *
     * Single source of truth for the editor: the same array drives the rule picker, the operator
     * list and the value input, so a rule cannot exist in the compiler and be un-pickable, or be
     * pickable and compile to nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function ruleTypes(): array
    {
        return [
            'pushable' => [
                'label' => Craft::t('schedulr', 'Subscribed to push'),
                'group' => Craft::t('schedulr', 'Reachability'),
                'input' => 'boolean',
                'operators' => ['eq'],
            ],
            'emailable' => [
                'label' => Craft::t('schedulr', 'Has an email address'),
                'group' => Craft::t('schedulr', 'Reachability'),
                'input' => 'boolean',
                'operators' => ['eq'],
            ],
            'declined' => [
                'label' => Craft::t('schedulr', 'Declined push'),
                'group' => Craft::t('schedulr', 'Reachability'),
                'input' => 'boolean',
                'operators' => ['eq'],
            ],
            'registered' => [
                'label' => Craft::t('schedulr', 'Is a signed-in user'),
                'group' => Craft::t('schedulr', 'Reachability'),
                'input' => 'boolean',
                'operators' => ['eq'],
            ],

            'visits' => [
                'label' => Craft::t('schedulr', 'Number of visits'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'number',
                'operators' => ['gte', 'lte', 'eq'],
            ],
            'lastSeenDays' => [
                'label' => Craft::t('schedulr', 'Last visit'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'days',
                'operators' => ['within', 'before'],
            ],
            'firstSeenDays' => [
                'label' => Craft::t('schedulr', 'First visit'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'days',
                'operators' => ['within', 'before'],
            ],
            'subscribedDays' => [
                'label' => Craft::t('schedulr', 'Subscribed'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'days',
                'operators' => ['within', 'before'],
            ],
            'notifiedCount' => [
                'label' => Craft::t('schedulr', 'Notifications received'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'number',
                'operators' => ['gte', 'lte', 'eq'],
            ],
            'notifiedDays' => [
                'label' => Craft::t('schedulr', 'Last notified'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'days',
                'operators' => ['within', 'before'],
            ],
            'received' => [
                'label' => Craft::t('schedulr', 'Received a notification'),
                'group' => Craft::t('schedulr', 'Behaviour'),
                'input' => 'notifications',
                'operators' => ['eq', 'ne'],
            ],

            'language' => [
                'label' => Craft::t('schedulr', 'Browser language'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'text',
                'operators' => ['eq', 'ne'],
            ],
            'timezone' => [
                'label' => Craft::t('schedulr', 'Time zone'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'timezones',
                'operators' => ['eq', 'ne'],
            ],
            'platform' => [
                'label' => Craft::t('schedulr', 'Platform'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'platforms',
                'operators' => ['eq', 'ne'],
            ],
            'site' => [
                'label' => Craft::t('schedulr', 'Site'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'sites',
                'operators' => ['eq', 'ne'],
            ],
            'userGroup' => [
                'label' => Craft::t('schedulr', 'User group'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'userGroups',
                'operators' => ['eq', 'ne'],
            ],
            'tag' => [
                'label' => Craft::t('schedulr', 'Tag'),
                'group' => Craft::t('schedulr', 'Who and where'),
                'input' => 'tags',
                'operators' => ['eq', 'ne'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function operatorLabels(): array
    {
        return [
            'eq' => Craft::t('schedulr', 'is'),
            'ne' => Craft::t('schedulr', 'is not'),
            'gte' => Craft::t('schedulr', 'is at least'),
            'lte' => Craft::t('schedulr', 'is at most'),
            'gt' => Craft::t('schedulr', 'is more than'),
            'lt' => Craft::t('schedulr', 'is less than'),
            'within' => Craft::t('schedulr', 'was within the last'),
            'before' => Craft::t('schedulr', 'was more than'),
        ];
    }
}
