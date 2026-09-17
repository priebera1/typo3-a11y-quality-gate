<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Controller\RemotePageDetailController;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Utility\RuleAnchorUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

/**
 * "View affected pages" starts from one rule: the page list is filtered to that rule, and a page opened
 * from the filtered list shows that rule expanded. The rule id travels in the URL, so it is validated.
 */
final class RemoteRuleContextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ruleIds(): iterable
    {
        yield 'axe rule' => ['color-contrast', 'color-contrast'];
        yield 'prefixed rule' => ['rte.empty_link', 'rte.empty_link'];
        yield 'surrounding space' => ['  link-name ', 'link-name'];
        yield 'markup' => ['<script>alert(1)</script>', ''];
        yield 'space inside' => ['link name', ''];
        yield 'quote' => ['link-name"', ''];
        yield 'too long' => [str_repeat('a', 191), ''];
        yield 'empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('ruleIds')]
    public function onlyPlainRuleIdentifiersAreAccepted(string $value, string $expected): void
    {
        $service = new RequestParameterService($this->createMock(LanguageUidResolver::class));
        $request = (new ServerRequest('https://example.org/typo3/module/web/a11y'))->withQueryParams(['remoteRule' => $value]);

        self::assertSame($expected, $service->getRuleId($request, 'remoteRule'));
    }

    #[Test]
    public function affectedPagesLinkFiltersTheFrontendPageListByRule(): void
    {
        $url = $this->ruleFilterUrl('color-contrast', ['id' => 5, 'remotePage' => 3, 'remoteRule' => 'link-name', 'remoteQuery' => 'about']);

        self::assertStringEndsWith('#a11y-remote-top-pages', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('color-contrast', $query['remoteRule']);
        self::assertSame('remote', $query['aqgSource'], 'The frontend tab opens with the filtered list.');
        self::assertSame('main', $query['site']);
        self::assertSame('about', $query['remoteQuery']);
        self::assertArrayNotHasKey('remotePage', $query, 'A filtered list starts on its first page.');
    }

    #[Test]
    public function clearingTheFilterKeepsTheRestOfTheContext(): void
    {
        $url = $this->ruleFilterUrl('', ['id' => 5, 'remoteRule' => 'color-contrast']);

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('remoteRule', $query);
        self::assertSame('5', (string)$query['id']);
        self::assertSame('remote', $query['aqgSource']);
    }

    #[Test]
    public function filterLabelUsesTheRuleTitleWhenTheScanKnowsIt(): void
    {
        $subject = (new \ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(OverviewController::class, 'resolveRuleFilterLabel');
        $fixes = [['ruleId' => 'color-contrast', 'displayTitle' => 'Text needs more contrast']];

        self::assertSame('Text needs more contrast', $method->invoke($subject, 'COLOR-CONTRAST', $fixes));
        self::assertSame('link-name', $method->invoke($subject, 'link-name', $fixes));
    }

    #[Test]
    public function pageDetailOpensTheRuleTheVisitorCameFrom(): void
    {
        $groups = $this->groupIssues(
            [
                ['uid' => 1, 'rule_id' => 'color-contrast', 'impact' => 'serious', 'nodes_count' => 3],
                ['uid' => 2, 'rule_id' => 'link-name', 'impact' => 'serious', 'nodes_count' => 1],
                ['uid' => 3, 'rule_id' => 'image-alt', 'impact' => 'critical', 'nodes_count' => 2],
            ],
            'LINK-NAME'
        );

        $byRule = array_column($groups, null, 'rule_id');
        self::assertTrue($byRule['link-name']['isRequestedRule']);
        self::assertTrue($byRule['link-name']['isDefaultOpen']);
        self::assertSame(RuleAnchorUtility::anchorId('link-name'), $byRule['link-name']['anchorId']);
        self::assertFalse($byRule['image-alt']['isRequestedRule']);
        self::assertFalse($byRule['image-alt']['isDefaultOpen']);
        // The first rule keeps its role as the page's primary recommendation.
        self::assertTrue($groups[0]['isDefaultOpen']);
        self::assertSame('color-contrast', $groups[0]['rule_id']);
    }

    #[Test]
    public function withoutARequestedRuleOnlyThePrimaryRuleIsOpen(): void
    {
        $groups = $this->groupIssues([
            ['uid' => 1, 'rule_id' => 'color-contrast', 'nodes_count' => 3],
            ['uid' => 2, 'rule_id' => 'link-name', 'nodes_count' => 1],
        ], '');

        self::assertSame([true, false], array_column($groups, 'isDefaultOpen'));
        self::assertSame([false, false], array_column($groups, 'isRequestedRule'));
    }

    #[Test]
    public function anchorIdsAreStableAndSafe(): void
    {
        self::assertSame(RuleAnchorUtility::anchorId('Color-Contrast '), RuleAnchorUtility::anchorId('color-contrast'));
        self::assertMatchesRegularExpression('/^aqg-rule-[a-f0-9]{12}$/', RuleAnchorUtility::anchorId('rte.empty_link'));
        self::assertNotSame(RuleAnchorUtility::anchorId('link-name'), RuleAnchorUtility::anchorId('image-alt'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, bool}>
     */
    public static function histories(): iterable
    {
        $latest = ['jobId' => 'job-new', 'finishedAt' => 2000, 'finishedAtFormatted' => '16.09.2026 10:00', 'viewReportUrl' => '/typo3/report/new'];
        yield 'viewing an earlier scan' => [['items' => [$latest]], 'job-old', 1000, true];
        yield 'viewing the latest scan' => [['items' => [$latest]], 'job-new', 2000, false];
        yield 'newest item is older than the viewed scan' => [['items' => [$latest]], 'job-other', 3000, false];
        yield 'latest scan has no report in TYPO3' => [['items' => [['viewReportUrl' => ''] + $latest]], 'job-old', 1000, false];
        yield 'no history' => [['items' => []], 'job-old', 1000, false];
    }

    /**
     * @param array<string, mixed> $history
     */
    #[Test]
    #[DataProvider('histories')]
    public function pageDetailPointsToANewerScanOfTheSameUrl(array $history, string $viewedJobId, int $viewedFinishedAt, bool $expectNotice): void
    {
        $subject = (new \ReflectionClass(RemotePageDetailController::class))->newInstanceWithoutConstructor();
        $newer = (new \ReflectionMethod(RemotePageDetailController::class, 'resolveNewerScan'))
            ->invoke($subject, $history, $viewedJobId, $viewedFinishedAt);

        if ($expectNotice) {
            self::assertSame(['finishedAtFormatted' => '16.09.2026 10:00', 'url' => '/typo3/report/new'], $newer);
        } else {
            self::assertSame([], $newer);
        }
    }

    /**
     * @param array<string, mixed> $returnParameters
     */
    private function ruleFilterUrl(string $ruleId, array $returnParameters): string
    {
        $parameters = $this->createMock(RequestParameterService::class);
        $parameters->method('getA11yModuleReturnParameters')->willReturn($returnParameters);
        $parameters->method('getLanguageUid')->willReturn(0);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route, array $query = []): Uri => new Uri('/typo3/module/web/a11y?' . http_build_query(['route' => $route] + $query))
        );

        $subject = (new \ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($parameters, $uriBuilder): void {
            $this->requestParameterService = $parameters;
            $this->uriBuilder = $uriBuilder;
        }, $subject, AbstractBackendModuleController::class)();

        return (string)(new \ReflectionMethod(OverviewController::class, 'buildRemoteRuleFilterUrl'))
            ->invoke($subject, new ServerRequest('https://example.org/typo3/'), 'main', $ruleId);
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private function groupIssues(array $issues, string $requestedRuleId): array
    {
        $context = $this->createMock(BackendContextService::class);
        $context->method('translate')->willReturn('');
        $context->method('getCurrentLanguageCode')->willReturn('en');

        $presentation = $this->createMock(RuleMetadataPresentationService::class);
        $presentation->method('present')->willReturn([]);

        $subject = (new \ReflectionClass(RemotePageDetailController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($context): void {
            $this->backendContextService = $context;
        }, $subject, AbstractBackendModuleController::class)();
        \Closure::bind(function () use ($presentation): void {
            $this->ruleMetadataPresentationService = $presentation;
        }, $subject, RemotePageDetailController::class)();

        return (new \ReflectionMethod(RemotePageDetailController::class, 'groupIssuesByRule'))
            ->invoke($subject, $issues, [], $requestedRuleId);
    }
}
