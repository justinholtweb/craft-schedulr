<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

use craft\base\Model;
use DateTime;

/**
 * One arm of an A/B test.
 *
 * A notification with a single variant is a notification, so variants are not a separate concept
 * an author has to opt into: the edit screen shows one, and adding a second is what turns it into
 * a test.
 *
 * `share` is a percentage of the audience. The set is normalised so it totals 100 and **the
 * remainder goes to the last variant**, because splitting 10,000 people three ways leaves one
 * person over, and dropping them is the kind of bug that shows up as a delivery count that is
 * always one short and never explains itself.
 */
class Variant extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public ?int $notificationId = null;

    public string $label = 'A';
    public int $share = 100;

    public ?string $title = null;
    public ?string $body = null;
    public ?string $imageUrl = null;
    public ?string $url = null;

    public int $targeted = 0;
    public int $delivered = 0;
    public int $failed = 0;
    public int $clicked = 0;
    public bool $isWinner = false;

    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;

    /** Hydrated from a whole row, so every selected column needs somewhere to land. */
    public ?DateTime $dateUpdated = null;

    public function datetimeAttributes(): array
    {
        return ['dateCreated', 'dateUpdated'];
    }

    /**
     * The fields this variant overrides on the notification.
     *
     * Only the ones that are actually set: a variant testing only the title must inherit the body,
     * or every A/B test silently blanks half the copy.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $out = [];

        foreach (['title' => $this->title, 'body' => $this->body, 'imageUrl' => $this->imageUrl, 'url' => $this->url] as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function getClickRate(): ?float
    {
        if ($this->delivered <= 0) {
            return null;
        }

        return round(($this->clicked / $this->delivered) * 100, 2);
    }

    protected function defineRules(): array
    {
        return [
            [['label'], 'required'],
            [['label'], 'string', 'max' => 32],
            [['share'], 'integer', 'min' => 1, 'max' => 100],
            [['title'], 'string', 'max' => 120],
            [['body'], 'string', 'max' => 400],
        ];
    }
}
