<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Rule\CheckContext;

class CheckContextTest extends TestCase
{
    #[Test]
    public function sourceLabelContainsTableUidAndField(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: 'main',
            pageUid: 5,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: '<p>test</p>',
        );

        $label = $ctx->sourceLabel();

        self::assertStringContainsString(Tables::TT_CONTENT, $label);
        self::assertStringContainsString('42', $label);
        self::assertStringContainsString('bodytext', $label);
    }

    #[Test]
    public function contextPathDefaultsToEmptyString(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 1,
            sourceField: 'bodytext',
            content: '',
        );

        self::assertSame('', $ctx->contextPath);
    }

    #[Test]
    public function contextPathCanBeSet(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 1,
            sourceField: 'bodytext',
            content: '',
            contextPath: 'Page:1 > tt_content:1 > bodytext',
        );

        self::assertSame('Page:1 > tt_content:1 > bodytext', $ctx->contextPath);
    }

    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: 'main',
            pageUid: 7,
            sourceLangUid: 1,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 99,
            sourceField: 'bodytext',
            content: '<p>Hello</p>',
            contextPath: 'Page:7 > tt_content:99 > bodytext',
        );

        self::assertSame('main', $ctx->siteIdentifier);
        self::assertSame(7, $ctx->pageUid);
        self::assertSame(1, $ctx->sourceLangUid);
        self::assertSame(Tables::TT_CONTENT, $ctx->sourceTable);
        self::assertSame(99, $ctx->sourceUid);
        self::assertSame('bodytext', $ctx->sourceField);
        self::assertSame('<p>Hello</p>', $ctx->content);
        self::assertSame('Page:7 > tt_content:99 > bodytext', $ctx->contextPath);
    }
}
