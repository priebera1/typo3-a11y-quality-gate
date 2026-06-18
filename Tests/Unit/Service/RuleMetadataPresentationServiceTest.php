<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

final class RuleMetadataPresentationServiceTest extends TestCase
{
    private function createBackendLanguageService(): BackendLanguageService
    {
        $service = new BackendLanguageService();
        $catalogues = new \ReflectionProperty(
            BackendLanguageService::class,
            'explicitLanguageCatalogues'
        );
        $catalogues->setValue($service, [
            'en:locallang.xlf' => [
                'rule.metadata.documentation.rule' => 'Rule documentation',
                'rule.metadata.documentation.axe' => 'axe-core rule documentation',
            ],
            'de:locallang.xlf' => [
                'rule.metadata.documentation.rule' => 'Regeldokumentation',
                'rule.metadata.documentation.axe' => 'axe-core-Regeldokumentation',
            ],
        ]);

        return $service;
    }

    #[Test]
    public function documentationLabelsRespectRequestedLanguage(): void
    {
        $subject = new RuleMetadataPresentationService($this->createBackendLanguageService());
        $issue = [
            'rule_id' => 'color-contrast',
            'help_url' => 'https://dequeuniversity.com/rules/axe/4.11/color-contrast',
            'documentationLinks' => [
                ['url' => 'https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html'],
            ],
        ];

        $english = $subject->present($issue, 'en');
        $german = $subject->present($issue, 'de');

        self::assertSame('Rule documentation', $english['documentationLinks'][0]['label']);
        self::assertSame('axe-core rule documentation', $english['documentationLinks'][1]['label']);
        self::assertSame('Regeldokumentation', $german['documentationLinks'][0]['label']);
        self::assertSame('axe-core-Regeldokumentation', $german['documentationLinks'][1]['label']);
    }

    #[Test]
    public function legacyDequeDocumentationLabelIsLocalizedInGerman(): void
    {
        $subject = new RuleMetadataPresentationService($this->createBackendLanguageService());
        $issue = [
            'rule_id' => 'color-contrast',
            'documentationLinks' => [
                [
                    'label' => 'Deque axe rule documentation',
                    'url' => 'https://dequeuniversity.com/rules/axe/4.11/color-contrast',
                    'type' => 'deque',
                ],
            ],
        ];

        $german = $subject->present($issue, 'de');

        self::assertSame('axe-core-Regeldokumentation', $german['documentationLinks'][0]['label']);
    }

}
