<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\UpdateNotice\SemanticVersion;

final class SemanticVersionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function newerVersions(): array
    {
        return [
            'patch' => ['1.9.9', '1.9.8'],
            'two-digit patch beats one-digit patch' => ['1.9.10', '1.9.9'],
            'minor beats a higher patch' => ['1.10.0', '1.9.99'],
            'two-digit minor' => ['1.10.0', '1.9.10'],
            'major' => ['2.0.0', '1.99.99'],
        ];
    }

    #[Test]
    #[DataProvider('newerVersions')]
    public function partsCompareAsNumbers(string $newer, string $older): void
    {
        $newerVersion = SemanticVersion::tryParse($newer);
        $olderVersion = SemanticVersion::tryParse($older);
        self::assertNotNull($newerVersion);
        self::assertNotNull($olderVersion);

        self::assertTrue($newerVersion->isNewerThan($olderVersion), $newer . ' > ' . $older);
        self::assertFalse($olderVersion->isNewerThan($newerVersion), $older . ' < ' . $newer);
    }

    #[Test]
    public function anEqualVersionIsNotNewer(): void
    {
        $version = SemanticVersion::tryParse('1.9.8');
        $same = SemanticVersion::tryParse('1.9.8');
        self::assertNotNull($version);
        self::assertNotNull($same);

        self::assertFalse($version->isNewerThan($same));
        self::assertTrue($version->equals($same));
        self::assertSame('1.9.8', $version->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unstableOrMalformedVersions(): array
    {
        return [
            'empty' => [''],
            'branch' => ['dev-main'],
            'unknown' => ['unknown'],
            'v prefix' => ['v1.9.9'],
            'two parts' => ['1.9'],
            'four parts' => ['1.9.9.1'],
            'pre-release' => ['1.9.9-beta1'],
            'build metadata' => ['1.9.9+build.5'],
            'leading zero' => ['1.09.9'],
            'negative' => ['1.-9.9'],
            'too large' => ['1.99999.9'],
            'markup' => ['1.9.9<script>'],
        ];
    }

    #[Test]
    #[DataProvider('unstableOrMalformedVersions')]
    public function onlyStableReleaseVersionsParse(string $value): void
    {
        self::assertNull(SemanticVersion::tryParse($value));
    }
}
