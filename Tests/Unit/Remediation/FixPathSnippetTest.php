<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;

/**
 * No "Fix with AI" that can only be refused (AQG 3.0.0 coverage gate): a link or heading whose text cannot be replaced
 * without dropping inner markup, and a template placeholder, take the manual path, decided from the stored snippet as
 * prepare decides it from the stored record.
 */
final class FixPathSnippetTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:string,2:bool}> */
    public static function snippets(): iterable
    {
        yield 'icon inside the link' => ['rte.empty_link', '<a href="https://shop.example.test/"><span class="icon-cart"></span></a>', true];
        yield 'formatted link text' => ['rte.non_descriptive_link', '<a href="/x"><strong>here</strong></a>', true];
        yield 'plain link text' => ['rte.non_descriptive_link', '<a href="https://www.priebera.sk">Click here</a>', false];
        yield 'empty link' => ['rte.empty_link', '<a href="/r1"></a>', false];
        yield 'empty heading' => ['rte.empty_heading', '<h3></h3>', false];
        yield 'heading with an icon' => ['rte.empty_heading', '<h2><i class="icon"></i></h2>', true];
        yield 'template placeholder' => ['rte.iframe_missing_title', '<iframe src="{video.url}"></iframe>', true];
        yield 'attribute change keeps inner markup' => ['rte.button_label_missing', '<button><svg aria-hidden="true"></svg></button>', false];
        yield 'no snippet decides nothing' => ['rte.empty_link', '', false];
        yield 'rule without AI fix' => ['rte.table_missing_header', '<table><tr><td><b>x</b></td></tr></table>', false];
    }

    #[Test]
    #[DataProvider('snippets')]
    public function decidesLikePrepare(string $rule, string $snippet, bool $manual): void
    {
        self::assertSame($manual, RemediationPageSummaryService::snippetIsManualOnly($rule, $snippet));
    }
}
