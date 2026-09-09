<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\RemotePageDetailController;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendFlashMessageService;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Core\Context\Context;

final class RemotePageDetailControllerTest extends TestCase
{
    private RemotePageDetailController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(RemotePageDetailController::class);
        $subject = $reflection->newInstanceWithoutConstructor();
        self::assertInstanceOf(RemotePageDetailController::class, $subject);

        $backendLanguageService = $this->createBackendLanguageService();
        $backendFlashMessageService = (new ReflectionClass(BackendFlashMessageService::class))
            ->newInstanceWithoutConstructor();
        self::assertInstanceOf(BackendFlashMessageService::class, $backendFlashMessageService);

        $backendContextProperty = new ReflectionProperty(
            AbstractBackendModuleController::class,
            'backendContextService'
        );
        $backendContextProperty->setValue(
            $subject,
            new BackendContextService(
                $backendLanguageService,
                new BackendUserService(),
                $backendFlashMessageService,
                new Context(),
            )
        );

        $metadataServiceProperty = new ReflectionProperty(
            RemotePageDetailController::class,
            'ruleMetadataPresentationService'
        );
        $metadataServiceProperty->setValue(
            $subject,
            new RuleMetadataPresentationService($backendLanguageService)
        );

        $this->subject = $subject;
    }


    private function createBackendLanguageService(): BackendLanguageService
    {
        $service = new BackendLanguageService();
        $catalogues = new ReflectionProperty(
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
    public function sameHelpUrlAcrossMultipleFindingsIsOnlyUsedAsPrimaryLink(): void
    {
        $helpUrl = 'https://deque.example/rules/example';

        $groups = $this->groupIssues([
            $this->issue($helpUrl),
            $this->issue($helpUrl),
        ]);

        self::assertCount(1, $groups);
        self::assertSame($helpUrl, $groups[0]['help_url']);
        self::assertNotContains($helpUrl, $this->documentationUrls($groups[0]));
    }

    #[Test]
    public function firstEmptyHelpUrlUsesLaterNonEmptyHelpUrlAsPrimaryLink(): void
    {
        $helpUrl = 'https://deque.example/rules/later';

        $groups = $this->groupIssues([
            $this->issue(''),
            $this->issue($helpUrl),
        ]);

        self::assertSame($helpUrl, $groups[0]['help_url']);
        self::assertNotContains($helpUrl, $this->documentationUrls($groups[0]));
    }

    #[Test]
    public function differentHelpUrlsKeepLaterUrlAsDocumentationLink(): void
    {
        $primaryHelpUrl = 'https://deque.example/rules/primary';
        $secondaryHelpUrl = 'https://deque.example/rules/secondary';

        $groups = $this->groupIssues([
            $this->issue($primaryHelpUrl),
            $this->issue($secondaryHelpUrl),
        ]);

        self::assertSame($primaryHelpUrl, $groups[0]['help_url']);
        self::assertNotContains($primaryHelpUrl, $this->documentationUrls($groups[0]));
        self::assertContains($secondaryHelpUrl, $this->documentationUrls($groups[0]));
    }

    #[Test]
    public function duplicateAdditionalDocumentationUrlsAreKeptOnce(): void
    {
        $documentationUrl = 'https://www.w3.org/WAI/WCAG22/Understanding/example.html';
        $documentation = [
            ['label' => 'WCAG', 'url' => $documentationUrl, 'type' => 'wcag'],
            ['label' => 'WCAG duplicate', 'url' => $documentationUrl, 'type' => 'wcag'],
        ];

        $groups = $this->groupIssues([
            $this->issue('https://deque.example/rules/example', $documentation),
            $this->issue('https://deque.example/rules/example', $documentation),
        ]);

        self::assertSame(
            [$documentationUrl],
            array_values(array_filter(
                $this->documentationUrls($groups[0]),
                static fn (string $url): bool => $url === $documentationUrl
            ))
        );
    }

    #[Test]
    public function documentationEntryWithEmptyUrlIsIgnored(): void
    {
        $filtered = $this->filterDocumentationLinks([
            ['label' => 'Empty', 'url' => '   ', 'type' => 'rule'],
        ], '');

        self::assertSame([], $filtered);
    }

    #[Test]
    public function nonArrayDocumentationPayloadAndEntriesAreIgnored(): void
    {
        $validUrl = 'https://www.w3.org/WAI/WCAG22/Understanding/example.html';

        self::assertSame([], $this->filterDocumentationLinks('invalid payload', ''));

        $filtered = $this->filterDocumentationLinks([
            'invalid',
            123,
            null,
            ['label' => 'Valid', 'url' => $validUrl, 'type' => 'wcag'],
        ], '');

        self::assertSame([$validUrl], array_column($filtered, 'url'));
    }

    #[Test]
    public function wcagCompactLabelAloneKeepsStandardsAndImpactVisible(): void
    {
        self::assertTrue($this->hasStandardsAndImpact([
            'wcagCompactLabel' => 'WCAG 1.4.3 · Level AA',
        ], []));
    }

    #[Test]
    public function completelyEmptyStandardsAndImpactIsHidden(): void
    {
        self::assertFalse($this->hasStandardsAndImpact([
            'affectedUsers' => [],
            'affectedUserItems' => [],
            'wcagReferences' => [],
            'wcagCompactLabel' => '   ',
            'techniques' => [],
            'standards' => [],
            'technicalTags' => [],
        ], []));
    }

    /**
     * @param array<int, array<string, mixed>> $documentation
     * @return array<string, mixed>
     */
    private function issue(string $helpUrl, array $documentation = []): array
    {
        return [
            'rule_id' => 'custom-remote-rule',
            'help' => 'Custom remote rule',
            'help_url' => $helpUrl,
            'nodes_count' => 1,
            'metadata' => [
                'plainLanguageTitle' => 'Custom remote rule',
                'documentation' => $documentation,
                'affectedUsers' => [],
                'wcagReferences' => [],
                'techniques' => [],
                'standards' => [],
                'tags' => [],
            ],
        ];
    }

    #[Test]
    public function heroFindingsCountEqualsSumOfRenderedRuleGroupCounts(): void
    {
        // The hero stat must not disagree with the per-rule counts printed beneath it.
        $issues = [
            ['uid' => 1, 'rule_id' => 'landmark-unique', 'nodes_count' => 3],
            ['uid' => 2, 'rule_id' => 'landmark-unique', 'nodes_count' => 2],
            ['uid' => 3, 'rule_id' => 'color-contrast'],
        ];

        $groupSum = array_sum(array_map(
            static fn (array $group): int => (int)$group['count'],
            $this->groupIssues($issues)
        ));

        $heroCount = (int)(new ReflectionMethod(RemotePageDetailController::class, 'countPageFindings'))
            ->invoke($this->subject, $issues, 0);

        self::assertSame(6, $groupSum);
        self::assertSame($groupSum, $heroCount);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function groupIssues(array $issues): array
    {
        $method = new ReflectionMethod(RemotePageDetailController::class, 'groupIssuesByRule');

        /** @var array<int, array<string, mixed>> $groups */
        $groups = $method->invoke($this->subject, $issues, []);

        return $groups;
    }

    /**
     * @param mixed $documentationLinks
     * @return array<int, array<string, mixed>>
     */
    private function filterDocumentationLinks(mixed $documentationLinks, string $helpUrl): array
    {
        $method = new ReflectionMethod(RemotePageDetailController::class, 'filterDocumentationLinks');

        /** @var array<int, array<string, mixed>> $filtered */
        $filtered = $method->invoke($this->subject, $documentationLinks, $helpUrl);

        return $filtered;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, array<string, mixed>> $documentationLinks
     */
    private function hasStandardsAndImpact(array $metadata, array $documentationLinks): bool
    {
        $method = new ReflectionMethod(RemotePageDetailController::class, 'hasStandardsAndImpact');

        return (bool)$method->invoke($this->subject, $metadata, $documentationLinks);
    }

    /** @param array<string, mixed> $group @return list<string> */
    private function documentationUrls(array $group): array
    {
        $links = is_array($group['documentationLinks'] ?? null)
            ? $group['documentationLinks']
            : [];

        return array_values(array_map(
            static fn (array $link): string => (string)($link['url'] ?? ''),
            $links
        ));
    }
}
