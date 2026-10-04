<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\tests\unit;

use justinholtweb\schedulr\services\Automations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two pieces of an automation that stand between a marketer's template and somebody's lock screen:
 * the no-sandbox fallback renderer, and the URL scheme check. Both are pure, so both are tested here.
 */
final class AutomationRenderingTest extends TestCase
{
    private function entry(): object
    {
        $author = new class() {
            public string $fullName = 'Ada Lovelace';
            public string $password = '$2y$13$notarealhash';
        };

        return new class($author) {
            public string $title = 'Hello';
            public ?string $slug = 'hello';

            public function __construct(public object $author)
            {
            }
        };
    }

    public function testPlainReferencesAreSubstituted(): void
    {
        $entry = $this->entry();

        self::assertSame(
            'New: Hello by Ada Lovelace (hello)',
            Automations::renderPlain('New: {{ entry.title }} by {{ entry.author.fullName }} ({slug})', $entry, ['entry' => $entry]),
        );
    }

    public function testTagsAreRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Automations::renderPlain('{% set x = 1 %}{{ entry.title }}', $this->entry(), ['entry' => $this->entry()]);
    }

    public function testFiltersAndFunctionsAreRefused(): void
    {
        // Anything that is not a bare reference survives substitution and is then refused whole —
        // never half-rendered.
        $this->expectException(RuntimeException::class);

        Automations::renderPlain('{{ craft.app.config.general.securityKey|upper }}', $this->entry(), ['entry' => $this->entry()]);
    }

    public function testCredentialsAreRefusedEvenAsPlainReferences(): void
    {
        $this->expectException(RuntimeException::class);

        Automations::renderPlain('{{ entry.author.password }}', $this->entry(), ['entry' => $this->entry()]);
    }

    public function testAnUnknownPropertyIsARefusalNotAnEmptyString(): void
    {
        $this->expectException(RuntimeException::class);

        Automations::renderPlain('{{ entry.aFieldThatDoesNotExist }}', $this->entry(), ['entry' => $this->entry()]);
    }

    public function testHttpAndRelativeUrlsPass(): void
    {
        self::assertSame('https://example.com/a?b=c', Automations::safeUrl('https://example.com/a?b=c'));
        self::assertSame('http://example.com/', Automations::safeUrl(' http://example.com/ '));
        self::assertSame('/news/hello', Automations::safeUrl('/news/hello'));
        self::assertSame('news/hello', Automations::safeUrl('news/hello'));
        self::assertSame('', Automations::safeUrl(''));
    }

    public function testOtherSchemesAreDropped(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            "java\tscript:alert(1)",
            " \x01javascript:alert(1)",
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox',
            'intent://scan/#Intent;scheme=zxing;end',
            'file:///etc/passwd',
            '\\\\evil.example/',
        ] as $url) {
            self::assertSame('', Automations::safeUrl($url), var_export($url, true) . ' should be dropped');
        }
    }
}
