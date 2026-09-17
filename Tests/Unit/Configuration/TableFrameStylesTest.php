<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Overview tables that fill their section card (content scan pages, frontend affected pages) share the card's
 * border and radius. A second frame inside the card doubled the edge and cut rounded notches beside the first
 * and last header cell; a table set inside padding (failed pages) keeps its own frame.
 */
final class TableFrameStylesTest extends TestCase
{
    private const EXTENSION = __DIR__ . '/../../../';

    #[Test]
    public function aTableThatFillsItsCardDrawsNoSecondFrame(): void
    {
        $css = (string)file_get_contents(self::EXTENSION . 'Resources/Public/Css/backend.css');

        self::assertStringContainsString(
            '.a11y-overview .aqg-section .aqg-table-wrap:not(.aqg-report-collapse__body .aqg-table-wrap){border:0;border-radius:0}',
            $css,
        );
        // The card keeps its rounded frame and clips the table that fills it.
        self::assertMatchesRegularExpression('/\.a11y-overview \.aqg-section,\.a11y-overview \.card\.aqg-section\{overflow:hidden\}/', $css);
        self::assertMatchesRegularExpression('/\.a11y-overview \.aqg-table-wrap\{[^}]*border:1px solid[^}]*border-radius:var\(--aqi-radius, 6px\)/', $css);
    }

    #[Test]
    public function theTablesThatFillACardSitInItsBodyAndTheFailedPagesTableInPadding(): void
    {
        $local = (string)file_get_contents(self::EXTENSION . 'Resources/Private/Partials/Overview/LocalPanel.html');
        $remote = (string)file_get_contents(self::EXTENSION . 'Resources/Private/Partials/Overview/RemotePanel.html');

        self::assertMatchesRegularExpression('/<section class="aqg-section[^"]*"[\s\S]*partial="Overview\/LocalTable"/', $local);
        self::assertSame(1, preg_match(
            '/<section class="aqg-section" id="a11y-remote-top-pages">(?:(?!<\/section>)[\s\S])*<div class="aqg-table-wrap">/',
            $remote,
        ), 'Frontend affected pages fill their section card.');
        self::assertSame(1, preg_match(
            '/<div class="aqg-report-collapse__body aqg-report-collapse__body--failed">(?:(?!<\/details>)[\s\S])*<div class="aqg-table-wrap">/',
            $remote,
        ), 'Failed pages sit inside the padded collapse body.');
    }
}
