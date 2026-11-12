<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RemediationChangeLogServiceTest extends TestCase
{
    #[Test]
    public function thePeriodIsTheLastSevenOrThirtyDaysOrThisCalendarYear(): void
    {
        $now = (int)mktime(12, 0, 0, 10, 27, 2026);

        self::assertSame($now - 7 * 86400, RemediationChangeLogService::since('7', $now));
        self::assertSame($now - 30 * 86400, RemediationChangeLogService::since('30', $now));
        self::assertSame((int)mktime(0, 0, 0, 1, 1, 2026), RemediationChangeLogService::since('year', $now));
        self::assertSame($now - 30 * 86400, RemediationChangeLogService::since('3650', $now), 'An unknown period is the default, never an unbounded log.');
    }

    #[Test]
    public function noReadableSiteOrNoVisibleItemMeansAnEmptyLogWithoutAQuery(): void
    {
        $items = new InMemoryRemediationItemRepository();
        $items->insert(['site_identifier' => 'main', 'page_uid' => 3, 'workspace_id' => 0, 'created_by' => 7, 'origin' => 'bulk', 'status' => 'pending_review']);
        $access = $this->createMock(RemediationAccessService::class);
        $access->method('canSee')->willReturn(false);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('getQueryBuilderForTable');
        $service = new RemediationChangeLogService($items, $access, $pool);

        self::assertSame(0, $service->list([], [])['total']);
        self::assertSame([], $service->list(['main'], [])['entries']);
    }

    #[Test]
    public function aValueHashIsShortenedForTheTable(): void
    {
        self::assertSame('e3b0…b855', RemediationItemPresenter::shortHash('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));
        self::assertSame('', RemediationItemPresenter::shortHash(''));
    }
}
