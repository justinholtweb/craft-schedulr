<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\helpers\Data;

/**
 * A saved, reusable segment.
 *
 * Kept in the **database rather than project config**, and the reason is the author: a segment is
 * written by whoever runs the marketing, not by whoever runs the deployments. Putting it in project
 * config would mean "audience: lapsed readers" arrives in a pull request, cannot be created on
 * production, and is reverted by the next `project-config/apply`.
 *
 * The condition is a small declarative structure rather than Craft's condition builder, because
 * subscribers are not elements and every rule here needs to compile to SQL over one table. Two
 * consequences, both deliberate: the whole segment resolves in one query no matter how many rules
 * it has, and a segment can be *counted* cheaply, which is what makes the CP able to say "4,812
 * people" next to the name.
 */
class Audience extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public ?int $siteId = null;

    public string $name = '';
    public string $handle = '';
    public ?string $description = null;

    /** @var array<string, mixed>|string|null */
    public mixed $condition = null;

    public ?int $cachedCount = null;
    public ?DateTime $dateCounted = null;
    public ?DateTime $dateCreated = null;

    /** Hydrated from a whole row, so every selected column needs somewhere to land. */
    public ?DateTime $dateUpdated = null;

    public function datetimeAttributes(): array
    {
        return ['dateCounted', 'dateCreated', 'dateUpdated'];
    }

    /**
     * The rules, normalised.
     *
     * @return array{match: string, rules: array<int, array<string, mixed>>}
     */
    public function getCondition(): array
    {
        $value = Data::toArray($this->condition);

        if ($value === []) {
            return ['match' => 'all', 'rules' => []];
        }

        $rules = [];

        foreach ((array)($value['rules'] ?? []) as $rule) {
            if (is_array($rule) && ($rule['type'] ?? '') !== '') {
                $rules[] = $rule;
            }
        }

        return [
            'match' => ($value['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => $rules,
        ];
    }

    /**
     * @param array<string, mixed> $condition
     */
    public function setCondition(array|string|null $condition): void
    {
        $this->condition = is_string($condition) ? Data::toArray($condition) : $condition;
    }

    public function getRuleCount(): int
    {
        return count($this->getCondition()['rules']);
    }

    /** A count that is honest about being stale, rather than a number with no provenance. */
    public function getCountLabel(): string
    {
        if ($this->cachedCount === null) {
            return Craft::t('schedulr', 'Not counted yet');
        }

        $count = Craft::$app->getFormatter()->asDecimal($this->cachedCount, 0);

        if ($this->dateCounted === null) {
            return $count;
        }

        return Craft::t('schedulr', '{count} as of {when}', [
            'count' => $count,
            'when' => Craft::$app->getFormatter()->asRelativeTime($this->dateCounted),
        ]);
    }

    protected function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-zA-Z0-9_]*$/', 'skipOnEmpty' => true],
            [['siteId'], 'integer'],
            [['condition'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if (trim($this->handle) === '' && trim($this->name) !== '') {
            $this->handle = StringHelper::toHandle($this->name);
        }

        return parent::beforeValidate();
    }
}
