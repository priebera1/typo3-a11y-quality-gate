<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Scan\RenderedRichTextDuplicates;

/**
 * Audit C6: on page 729, rendered.empty_heading (tt_content 937, `<h2></h2>`, body > div[5] > div > h2) and
 * rte.empty_heading (tt_content 937 bodytext, `<h2></h2>`, h2) were the same element, counted twice. A rendered finding
 * is only treated as that element's duplicate when the record, an equivalent rule, the snippet and the element path
 * all match; anything else (template markup, a rewritten src, another record) stays a finding of its own.
 */
final class RenderedRichTextDuplicatesTest extends TestCase
{
    #[Test]
    public function theSameElementFoundByBothScopesIsOneFinding(): void
    {
        $richText = [
            $this->richText('rte.empty_heading', 937, '<h2></h2>', 'h2'),
            $this->richText('rte.img_alt_missing', 937, '<img src="fileadmin/logo.png">', 'a[2] > img'),
            $this->richText('rte.duplicate_id', 937, '<p id="sekcia-1">Duplicate ID</p>', 'p'),
        ];
        $rendered = [
            $heading = $this->rendered('rendered.empty_heading', 937, '<h2></h2>', 'body > div[5] > div > h2'),
            $image = $this->rendered('rendered.img_missing_alt', 937, '<img src="fileadmin/logo.png">', 'body > div[5] > div > a[2] > img'),
            $templateDiv = $this->rendered('rendered.duplicate_id', 937, '<div id="sekcia-1">Content</div>', 'body > div[5] > div > div'),
            $contentP = $this->rendered('rendered.duplicate_id', 937, '<p id="sekcia-1">Duplicate ID</p>', 'body > div[5] > div > p'),
            $landmark = $this->rendered('rendered.main_landmark_missing', 0, '<body>', 'body'),
        ];

        [$kept, $duplicates] = RenderedRichTextDuplicates::split($rendered, $richText);

        self::assertSame([$templateDiv, $landmark], $kept, 'Template markup and page-level findings stay.');
        self::assertSame([$heading, $image, $contentP], $duplicates);
    }

    #[Test]
    public function aDifferentRecordSnippetOrPathIsNotADuplicate(): void
    {
        $richText = [$this->richText('rte.empty_link', 937, '<a href="/test"></a>', 'a[1]')];
        $rendered = [
            $otherRecord = $this->rendered('rendered.empty_link', 938, '<a href="/test"></a>', 'body > div > a[1]'),
            $rewritten = $this->rendered('rendered.empty_link', 937, '<a href="/en/test"></a>', 'body > div > a[1]'),
            $otherPosition = $this->rendered('rendered.empty_link', 937, '<a href="/test"></a>', 'body > div > a[2]'),
        ];

        [$kept, $duplicates] = RenderedRichTextDuplicates::split($rendered, $richText);

        self::assertSame([$otherRecord, $rewritten, $otherPosition], $kept);
        self::assertSame([], $duplicates);
    }

    #[Test]
    public function eachRichTextFindingPairsWithOneRenderedFindingOnly(): void
    {
        $richText = [$this->richText('rte.empty_heading', 937, '<h2></h2>', 'h2')];
        $rendered = [
            $first = $this->rendered('rendered.empty_heading', 937, '<h2></h2>', 'body > div > h2'),
            $second = $this->rendered('rendered.empty_heading', 937, '<h2></h2>', 'body > div > div > h2'),
        ];

        [$kept, $duplicates] = RenderedRichTextDuplicates::split($rendered, $richText);

        self::assertSame([$first], $duplicates);
        self::assertSame([$second], $kept);
    }

    #[Test]
    public function entitiesAndWhitespaceDoNotHideASharedSnippet(): void
    {
        $richText = [$this->richText('rte.empty_heading', 936, "<h2>&#xA0;</h2>", 'h2')];
        $rendered = [$this->rendered('rendered.empty_heading', 936, "<h2>\u{00A0}</h2>", 'body > div[3] > div > h2')];

        [, $duplicates] = RenderedRichTextDuplicates::split($rendered, $richText);

        self::assertCount(1, $duplicates);
    }

    /**
     * @return array{ruleId: string, table: string, uid: int, snippet: string, path: string}
     */
    private function richText(string $ruleId, int $uid, string $snippet, string $path): array
    {
        return ['ruleId' => $ruleId, 'table' => 'tt_content', 'uid' => $uid, 'snippet' => $snippet, 'path' => $path];
    }

    private function rendered(string $ruleId, int $uid, string $snippet, string $path): RuleViolation
    {
        return new RuleViolation(
            ruleId: $ruleId,
            severity: Severity::Critical,
            message: 'm',
            hint: 'h',
            contextSnippet: $snippet,
            contextPath: $path,
            sourceType: 'rendered',
            sourceTable: $uid > 0 ? 'tt_content' : '',
            sourceUid: $uid,
            sourceField: $uid > 0 ? '__rendered_html' : '',
        );
    }
}
