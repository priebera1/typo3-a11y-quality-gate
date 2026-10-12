<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Regression (audit C10): TYPO3 14 TCA labels use translation domains (`frontend.db.tt_content:bodytext`), which the
 * Scan fields list showed raw because only `LLL:` references were translated.
 */
final class BackendLanguageServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function translationDomainLabelsAreTranslated(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static fn (string $label): string => match ($label) {
            'frontend.db.tt_content:bodytext' => 'Text',
            'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:bodytext_formlabel' => 'Text',
            default => $label,
        });
        $GLOBALS['LANG'] = $languageService;

        $service = new BackendLanguageService();

        self::assertSame('Text', $service->translateRawLabel('frontend.db.tt_content:bodytext'));
        self::assertSame('Text', $service->translateRawLabel('LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:bodytext_formlabel'));
        self::assertSame('Bodytext', $service->translateRawLabel('Bodytext'));
    }

    #[Test]
    public function anUnresolvedReferenceIsReportedAsEmpty(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturn('');
        $GLOBALS['LANG'] = $languageService;

        self::assertSame('', (new BackendLanguageService())->translateRawLabel('frontend.db.tt_content:unknown'));
    }
}
