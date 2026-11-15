<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\RemoteScanPageOverlap;

final class RemoteScanPageOverlapTest extends TestCase
{
    #[Test]
    public function theProductionBaselineIsASubsetOfTheLaterScan(): void
    {
        $earlier = $this->pages(4);
        $later = $this->pages(19);

        $overlap = RemoteScanPageOverlap::of($earlier, $later);

        self::assertFalse($overlap->isSamePages());
        self::assertSame(4, $overlap->comparedPages());
        self::assertSame(0, $overlap->onlyInEarlier);
        self::assertSame(15, $overlap->onlyInLater);
    }

    #[Test]
    public function theSamePagesAreTheSamePagesInAnyOrder(): void
    {
        $overlap = RemoteScanPageOverlap::of(array_reverse($this->pages(5), true), $this->pages(5));

        self::assertTrue($overlap->isSamePages());
        self::assertSame(5, $overlap->comparedPages());
    }

    #[Test]
    public function disjointScansShareNoPage(): void
    {
        $overlap = RemoteScanPageOverlap::of(['https://a.test/x' => true], ['https://a.test/y' => true]);

        self::assertSame([], $overlap->common);
        self::assertSame(1, $overlap->onlyInEarlier);
        self::assertSame(1, $overlap->onlyInLater);
    }

    /**
     * @return array<string, true>
     */
    private function pages(int $count): array
    {
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $pages['https://a.test/page-' . $i] = true;
        }

        return $pages;
    }
}
