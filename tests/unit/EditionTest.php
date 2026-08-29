<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\tests\unit;

use justinholtweb\schedulr\models\Edition;
use PHPUnit\Framework\TestCase;

/**
 * The edition boundary, in one file.
 *
 * `Edition` is pure and static precisely so this test can exist: the answer to "what exactly does Pro
 * buy" is checkable rather than scattered across twenty `isPro()` calls that each have to be read.
 */
final class EditionTest extends TestCase
{
    /**
     * The line: Lite composes, schedules and sends. Pro decides who, when *for them*, and what happened.
     */
    public function testLiteGetsTheWholeSendingPath(): void
    {
        // Not gated in Lite, and this test exists to keep them that way. A plugin called Schedulr whose
        // scheduling is behind a paywall would be a bait and switch, and capping subscribers would be
        // charging for the number that grows with a site's success.
        self::assertNull(Edition::maxAudiences(true));
        self::assertSame(0, Edition::maxAudiences(false));
        self::assertSame(1, Edition::maxVariants(false));
        self::assertNull(Edition::maxVariants(true));
    }

    public function testProOnlyFeatures(): void
    {
        foreach ([
            'allowsSegments',
            'allowsPerSubscriberTimezone',
            'allowsAutomations',
            'allowsAbTesting',
            'allowsClickTracking',
            'allowsFrequencyCaps',
            'allowsDedupePolicy',
            'allowsExport',
        ] as $method) {
            self::assertTrue(Edition::$method(true), "$method should be allowed in Pro");
            self::assertFalse(Edition::$method(false), "$method should be refused in Lite");
        }
    }

    public function testLiteKeepsAWorkingPrompt(): void
    {
        // Two styles, not zero. Lite has a complete and honest opt-in experience; Pro sells conversion
        // tuning, not the ability to ask at all.
        self::assertSame(['native', 'bell'], Edition::promptStyles(false));
        self::assertContains('slide', Edition::promptStyles(true));
        self::assertContains('custom', Edition::promptStyles(true));
    }

    public function testPromptStyleAllowance(): void
    {
        self::assertTrue(Edition::promptStyleAllowed('bell', false));
        self::assertFalse(Edition::promptStyleAllowed('slide', false));
        self::assertTrue(Edition::promptStyleAllowed('slide', true));
    }

    public function testLimitReachedTreatsNullAsUnlimited(): void
    {
        self::assertFalse(Edition::limitReached(null, 10_000));
        self::assertTrue(Edition::limitReached(1, 1));
        self::assertFalse(Edition::limitReached(2, 1));

        // Zero means "none at all", which is what Lite's audience allowance is — and `>=` is what makes
        // that work rather than allowing one through.
        self::assertTrue(Edition::limitReached(0, 0));
    }
}
