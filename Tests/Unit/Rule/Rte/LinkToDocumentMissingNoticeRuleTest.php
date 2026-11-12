<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\LinkToDocumentMissingNoticeRule;
use Priebera\A11yQualityGate\Rule\Rte\LinkToDocumentRule;

/**
 * 2026-11-05 company acceptance: one empty PDF link produced three cards — "Link has no accessible name" (critical),
 * "file type not mentioned" and "verify the document". Without any link text there is nothing to mention the file type
 * in: the empty link is the finding. Once text is added, this rule checks it again.
 */
final class LinkToDocumentMissingNoticeRuleTest extends TestCase
{
    #[Test]
    public function anEmptyDocumentLinkIsLeftToTheEmptyLinkRule(): void
    {
        self::assertSame([], $this->rule()->check($this->ctx('<p><a href="/fileadmin/tariffs.pdf"></a></p>')));
    }

    #[Test]
    public function aNamedLinkWithoutTheFileTypeIsStillReported(): void
    {
        self::assertCount(1, $this->rule()->check($this->ctx('<p><a href="/fileadmin/tariffs.pdf">Tariffs 2026</a></p>')));
        self::assertCount(1, $this->rule()->check($this->ctx('<p><a href="/fileadmin/tariffs.pdf"><img src="i.png" alt="Tariffs"></a></p>')), 'an image alt is a name');
        self::assertSame([], $this->rule()->check($this->ctx('<p><a href="/fileadmin/tariffs.pdf">Tariffs 2026 (PDF)</a></p>')));
    }

    private function rule(): LinkToDocumentMissingNoticeRule
    {
        return new LinkToDocumentMissingNoticeRule(new LinkToDocumentRule());
    }

    private function ctx(string $content): CheckContext
    {
        return new CheckContext(siteIdentifier: 'main', pageUid: 1, sourceLangUid: 0, sourceTable: Tables::TT_CONTENT, sourceUid: 42, sourceField: 'bodytext', content: $content);
    }
}
