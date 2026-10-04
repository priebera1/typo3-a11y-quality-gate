<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\UpdateNotice\LatestRelease;

/**
 * The release metadata comes from the network: everything is validated before a template sees it.
 */
final class LatestReleaseTest extends TestCase
{
    #[Test]
    public function aValidReleaseKeepsItsMetadata(): void
    {
        $release = LatestRelease::fromArray([
            'product' => 'accessibility-quality-gate',
            'version' => '1.9.10',
            'releasedAt' => '2026-11-02',
            'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog#v1910',
            'updateInstructionsUrl' => 'https://extensions.typo3.org/extension/a11y_quality_gate',
            'importance' => 'Security',
        ]);

        self::assertNotNull($release);
        self::assertSame([
            'version' => '1.9.10',
            'releasedAt' => '2026-11-02',
            'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog#v1910',
            'updateInstructionsUrl' => 'https://extensions.typo3.org/extension/a11y_quality_gate',
            'importance' => 'security',
        ], $release->toArray());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedReleases(): array
    {
        return [
            'not an object' => ['1.9.9'],
            'missing version' => [['releasedAt' => '2026-11-02']],
            'numeric version' => [['version' => 1.9]],
            'pre-release' => [['version' => '1.10.0-rc1']],
            'branch' => [['version' => 'dev-main']],
        ];
    }

    #[Test]
    #[DataProvider('rejectedReleases')]
    public function anAnswerWithoutAStableVersionIsRejected(mixed $release): void
    {
        self::assertNull(LatestRelease::fromArray($release));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'plain http' => ['http://typo3.priebera.sk/docs/changelog'],
            'javascript' => ['javascript:alert(document.cookie)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'foreign host' => ['https://evil.example/changelog'],
            'look-alike host' => ['https://typo3.priebera.sk.evil.example/'],
            'credentials' => ['https://user:secret@typo3.priebera.sk/docs'],
            'port' => ['https://typo3.priebera.sk:8443/docs'],
            'quote breaking out of an attribute' => ['https://typo3.priebera.sk/docs" onmouseover="alert(1)'],
            'markup' => ['https://typo3.priebera.sk/<script>'],
            'embedded control character' => ["https://typo3.priebera.sk/do\ncs"],
            'embedded space' => ['https://typo3.priebera.sk/do cs'],
            'too long' => ['https://typo3.priebera.sk/' . str_repeat('a', 600)],
            'not a string' => [['https://typo3.priebera.sk/docs']],
            'protocol-relative' => ['//typo3.priebera.sk/docs'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeLinks')]
    public function anUnsafeLinkIsReplacedByAqgsOwnPage(mixed $url): void
    {
        $release = LatestRelease::fromArray([
            'version' => '1.9.9',
            'releaseNotesUrl' => $url,
            'updateInstructionsUrl' => $url,
        ]);

        self::assertNotNull($release);
        self::assertSame(LatestRelease::DEFAULT_RELEASE_NOTES_URL, $release->releaseNotesUrl);
        self::assertSame(LatestRelease::DEFAULT_UPDATE_INSTRUCTIONS_URL, $release->updateInstructionsUrl);
    }

    #[Test]
    public function anInvalidDateOrImportanceIsDroppedNotTrusted(): void
    {
        $release = LatestRelease::fromArray([
            'version' => '1.9.9',
            'releasedAt' => '2026-02-30',
            'importance' => '<b>critical</b>',
        ]);

        self::assertNotNull($release);
        self::assertSame('', $release->releasedAt);
        self::assertSame('normal', $release->importance);
    }
}
