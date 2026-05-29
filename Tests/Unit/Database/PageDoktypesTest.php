<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\PageDoktypes;

final class PageDoktypesTest extends TestCase
{
    #[Test]
    public function standardAndAdvancedPagesSupportRenderedChecks(): void
    {
        self::assertTrue(PageDoktypes::supportsRenderedCheck(1));
        self::assertTrue(PageDoktypes::supportsRenderedCheck(2));
    }

    #[Test]
    public function customRenderableDoktypesSupportRenderedChecksByDefault(): void
    {
        foreach ([10, 20, 30, 100, 1001] as $doktype) {
            self::assertTrue(PageDoktypes::supportsRenderedCheck($doktype), 'Custom renderable doktype ' . $doktype . ' must not be skipped by default.');
            self::assertFalse(PageDoktypes::isNonFrontendPageDoktype($doktype), 'Custom renderable doktype ' . $doktype . ' must not be classified as known non-frontend.');
        }
    }

    #[Test]
    public function nonFrontendDoktypesDoNotSupportRenderedChecks(): void
    {
        foreach ([3, 4, 6, 7, 199, 254] as $doktype) {
            self::assertFalse(PageDoktypes::supportsRenderedCheck($doktype), 'Doktype ' . $doktype . ' must be skipped for rendered checks.');
            self::assertTrue(PageDoktypes::isNonFrontendPageDoktype($doktype), 'Doktype ' . $doktype . ' must be classified as non-frontend.');
        }
    }
}
