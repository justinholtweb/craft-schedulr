<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the caps follow: **Lite composes, schedules and sends. Pro decides who, when *for
 * them*, and tells you what happened.** All three channels are in Lite, and so is recurrence —
 * a plugin called Schedulr whose scheduling is behind a paywall is a bait and switch. What Pro
 * buys is the targeting and measurement half: segments, per-subscriber time zones, automations,
 * A/B, click analytics, caps.
 *
 * There is deliberately **no limit on subscribers or on notifications sent**. Charging for the
 * number that grows with a site's success is the wrong shape of pricing for this tool.
 *
 * ## A downgrade, not a wall
 *
 * Every gate here answers "may this site **create or change** this configuration?" — it is read by
 * the editors and controllers. None of them is read at send time. Configuration saved while the site
 * was Pro keeps doing exactly what it did after a licence lapses, because the alternative turns a
 * billing event into a behaviour change nobody asked for, and the worst of those — a segmented send
 * quietly becoming a send to everybody — is not recoverable once it has gone out. Per feature:
 *
 * | Feature | Lite cannot… | …but a saved one keeps |
 * |---|---|---|
 * | Segments | create a segment or point a notification at one | filtering every send to its audience |
 * | Per-subscriber time zones | switch a schedule to them | fanning out one occurrence per zone, on re-save too |
 * | Automations | create or re-target a trigger | firing — save hook, win-back sweep, scheduled entries |
 * | A/B testing | add a variant | splitting the audience across its arms and picking a winner |
 * | Dedupe policy | choose a policy (new notifications keep the default) | being applied between channels |
 * | Frequency caps | change the caps in settings | excluding over-cap subscribers |
 * | Prompt styles | choose a Pro style | prompting — in the nearest Lite style, the bell (`web\Injector`) |
 * | Export | export | — (nothing to keep; it is an action, not configuration) |
 *
 * **Click tracking is the one exception, and it is deliberate.** It is not saved configuration but
 * something done *to each new send* — the redirect is written into the payload at send time — so on
 * Lite new sends carry the destination URL directly and are not counted. Every send already tracked
 * keeps its redirect working and its clicks counting, because those URLs are already on people's
 * devices; the funnel built on them is what Lite stops showing.
 */
class Edition
{
    /**
     * Saved audience segments Lite may hold.
     *
     * Zero, not one. Lite targets "everyone on this site" and "everyone carrying this topic",
     * which are both expressed on the notification itself; a *saved, reusable, condition-built*
     * audience is the thing Pro sells.
     */
    public const LITE_MAX_AUDIENCES = 0;

    /** Variants per notification in Lite. One variant is "the notification". */
    public const LITE_MAX_VARIANTS = 1;

    /**
     * Opt-in prompt styles Lite may use.
     *
     * Lite gets the native permission dialog and one soft prompt, which is a complete and honest
     * opt-in experience. Slide-downs, custom markup and re-ask rules are conversion tuning.
     */
    public const LITE_PROMPT_STYLES = ['native', 'bell'];

    /** Condition-built, saved, reusable audiences. */
    public static function allowsSegments(bool $isPro): bool
    {
        return $isPro;
    }

    /** Delivering "09:00" at 09:00 wherever the subscriber is. */
    public static function allowsPerSubscriberTimezone(bool $isPro): bool
    {
        return $isPro;
    }

    /** Notifications raised by something happening — an entry published, a user registered. */
    public static function allowsAutomations(bool $isPro): bool
    {
        return $isPro;
    }

    /** Splitting an audience across variants and picking a winner. */
    public static function allowsAbTesting(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * The click redirect, the display/dismiss events, and the funnel built on them.
     *
     * Lite still gets delivered/failed counts — a send whose outcome is unknown is not a send
     * anyone will make twice.
     */
    public static function allowsClickTracking(bool $isPro): bool
    {
        return $isPro;
    }

    /** "No more than 3 a week", and "never between 22:00 and 07:00". */
    public static function allowsFrequencyCaps(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Choosing a per-channel dedupe policy. A new Lite notification keeps the default — every enabled
     * channel to everyone reachable on it — but a policy already saved is applied whatever the edition.
     */
    public static function allowsDedupePolicy(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsExport(bool $isPro): bool
    {
        return $isPro;
    }

    public static function maxAudiences(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_AUDIENCES;
    }

    public static function maxVariants(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_VARIANTS;
    }

    /**
     * @return string[]
     */
    public static function promptStyles(bool $isPro): array
    {
        return $isPro
            ? ['native', 'bell', 'slide', 'custom']
            : self::LITE_PROMPT_STYLES;
    }

    /**
     * Whether this edition will honour a prompt style that is already configured.
     *
     * A **downgrade**, not a refusal. A site whose licence lapsed keeps its slide-down prompt
     * working; what Lite stops is choosing a new one. Nothing here ever silently changes what a
     * visitor sees, because a prompt that vanishes on renewal day looks exactly like a bug.
     */
    public static function promptStyleAllowed(string $style, bool $isPro): bool
    {
        return in_array($style, self::promptStyles($isPro), true);
    }

    /**
     * The limit a count has already reached, if any.
     */
    public static function limitReached(?int $max, int $existing): bool
    {
        return $max !== null && $existing >= $max;
    }
}
