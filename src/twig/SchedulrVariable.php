<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\twig;

use craft\helpers\Template;
use justinholtweb\schedulr\elements\db\NotificationQuery;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Audience;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;
use Twig\Markup;

/**
 * `craft.schedulr` — what a template may ask.
 *
 * Read-only, and deliberately so. A template that could send a notification is a template that sends
 * one on every page load the first time somebody caches it.
 *
 * The one write-adjacent thing here is `runtime()`, for sites that turn injection off and want the
 * script exactly where they choose. Called twice, it emits twice — which is a real bug and not worth
 * guarding against here, because the guard would silently do nothing on the second call and be harder
 * to diagnose than two prompts.
 */
class SchedulrVariable
{
    /** The subscriber for the current browser, if it has ever been seen. */
    public function subscriber(?string $visitorId = null): ?Subscriber
    {
        if ($visitorId === null) {
            return null;
        }

        return Plugin::getInstance()->subscribers->getByVisitorId(
            $visitorId,
            \Craft::$app->getSites()->getCurrentSite()->id,
        );
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function notifications(array $criteria = []): NotificationQuery
    {
        /** @var NotificationQuery $query */
        $query = Notification::find();
        \Craft::configure($query, $criteria);

        return $query;
    }

    /**
     * @return Audience[]
     */
    public function audiences(): array
    {
        return Plugin::getInstance()->audiences->getAll(\Craft::$app->getSites()->getCurrentSite()->id);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return Plugin::getInstance()->subscribers->stats(\Craft::$app->getSites()->getCurrentSite()->id);
    }

    public function subscriberCount(): int
    {
        return Plugin::getInstance()->subscribers->stats(
            \Craft::$app->getSites()->getCurrentSite()->id,
        )['pushable'];
    }

    /** The VAPID public key, for a site subscribing from its own JavaScript. */
    public function publicKey(): string
    {
        return Plugin::getInstance()->keys->getPublicKey();
    }

    /**
     * The runtime's script and styles, for a site that has turned automatic injection off.
     */
    public function runtime(): Markup
    {
        return Template::raw((new \justinholtweb\schedulr\web\Injector())->markup());
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
