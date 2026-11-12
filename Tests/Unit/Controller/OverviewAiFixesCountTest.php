<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow\InMemoryRemediationItemRepository;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

/**
 * The Overview sub-navigation "AI fixes (n)" (Atlas 08·A) counts the open items of the site from the rows the activity
 * line already reads — the queue's "open" (not done), only what this person may see, and only when AI is ready.
 */
final class OverviewAiFixesCountTest extends TestCase
{
    private const TEMPLATE_SUBNAV = __DIR__ . '/../../../Resources/Private/Partials/Remediation/SubNav.html';
    private const TEMPLATE_INDEX = __DIR__ . '/../../../Resources/Private/Templates/Overview/Index.html';

    #[Test]
    public function theCountIsTheOpenItemsThisPersonMaySee(): void
    {
        $result = $this->activity(aiState: 'ready');

        // pending_review + preparing + saved are open; verified and rejected are done; the hidden item is not counted.
        self::assertSame(3, $result['openCount']);
        self::assertIsArray($result['activity']);
    }

    #[Test]
    public function withoutReadyAiTheCountIsHidden(): void
    {
        self::assertSame(0, $this->activity(aiState: 'not_configured')['openCount']);
    }

    #[Test]
    public function withoutTheLicenceThereIsNeitherActivityNorCount(): void
    {
        self::assertSame(['activity' => null, 'openCount' => 0], $this->activity(aiState: 'ready', licensed: false));
    }

    #[Test]
    public function theOverviewPassesTheCountAndTheSubNavigationShowsItOnlyAboveZero(): void
    {
        self::assertStringContainsString('aiFixesCount: aiFixesCount', (string)file_get_contents(self::TEMPLATE_INDEX));
        $subNav = (string)file_get_contents(self::TEMPLATE_SUBNAV);
        self::assertStringContainsString('<f:if condition="{aiFixesCount} > 0"> <span class="aqg-subnav__count" data-aqg-subnav-count="overview">{aiFixesCount}</span></f:if>', $subNav);
    }

    /** @return array{activity: array<string,mixed>|null, openCount: int} */
    private function activity(string $aiState, bool $licensed = true): array
    {
        $items = new InMemoryRemediationItemRepository();
        foreach ([
            ['status' => 'pending_review', 'saved_at' => 0],
            ['status' => 'preparing', 'saved_at' => 0],
            ['status' => 'saved', 'saved_at' => time()],
            ['status' => 'verified', 'saved_at' => time()],
            ['status' => 'rejected', 'saved_at' => 0],
            // On a page this person cannot open.
            ['status' => 'pending_review', 'saved_at' => 0, 'page_uid' => 99],
        ] as $row) {
            $items->insert($row + ['site_identifier' => 'main', 'page_uid' => 3, 'workspace_id' => 0, 'created_by' => 7]);
        }
        $access = $this->createMock(RemediationAccessService::class);
        $access->method('hasLicence')->willReturn($licensed);
        $access->method('canReadSiteIdentifier')->willReturn(true);
        $access->method('canReadPage')->willReturnCallback(static fn (int $pageUid): bool => $pageUid !== 99);
        $access->method('currentWorkspace')->willReturn(0);
        $access->method('mayUseBulk')->willReturn(true);
        $access->method('canReadTargetRecord')->willReturn(true);
        $access->method('userUid')->willReturn(7);
        $access->method('aiState')->willReturn($aiState);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/a11y/remediation'));

        $controller = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        foreach (['remediationItems' => $items, 'remediationAccess' => $access, 'uriBuilder' => $uriBuilder] as $property => $value) {
            (new \ReflectionProperty($controller, $property))->setValue($controller, $value);
        }

        return (new ReflectionMethod(OverviewController::class, 'remediationActivity'))->invoke($controller, 'main', 0);
    }
}
