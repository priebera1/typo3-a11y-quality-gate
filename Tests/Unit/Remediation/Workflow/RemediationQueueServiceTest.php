<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationQueueService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;

final class RemediationQueueServiceTest extends TestCase
{
    #[Test]
    public function rowsAndCountsCoverOnlyItemsThePersonMaySee(): void
    {
        $items = new InMemoryRemediationItemRepository();
        $visible = $items->insert($this->row(['page_uid' => 3, 'status' => RemediationStatus::PENDING_REVIEW]));
        $items->insert($this->row(['page_uid' => 99, 'status' => RemediationStatus::PENDING_REVIEW]));
        $items->insert($this->row(['page_uid' => 3, 'status' => RemediationStatus::VERIFIED]));
        $items->insert($this->row(['page_uid' => 3, 'status' => RemediationStatus::PENDING_REVIEW, 'site_identifier' => 'other']));

        // Page 99 is outside this person's web mounts.
        $access = $this->access([99]);

        $result = (new RemediationQueueService($items, $access))->list(['main'], ['tab' => 'open']);

        self::assertSame([$visible], array_map(static fn (array $item): int => (int)$item['uid'], $result['items']));
        self::assertSame(['open' => 1, 'mine' => 1, 'done' => 1], $result['counts'], 'Counts never include unreadable pages or other sites.');
    }

    #[Test]
    public function doneHoldsVerifiedRejectedAndNotApplicableOnly(): void
    {
        $items = new InMemoryRemediationItemRepository();
        foreach ([RemediationStatus::VERIFIED, RemediationStatus::REJECTED, RemediationStatus::NOT_APPLICABLE, RemediationStatus::NOT_VERIFIED, RemediationStatus::SAVED] as $status) {
            $items->insert($this->row(['status' => $status]));
        }
        $access = $this->access();

        $done = (new RemediationQueueService($items, $access))->list(['main'], ['tab' => 'done']);

        self::assertEqualsCanonicalizing([RemediationStatus::VERIFIED, RemediationStatus::REJECTED, RemediationStatus::NOT_APPLICABLE], array_column($done['items'], 'status'));
        self::assertSame(2, $done['counts']['open'], 'Saved and not verified stay open: they are not finished.');
    }

    #[Test]
    public function searchMatchesOnlyVisibleRowsCaseInsensitivelyAndKeepsTheTabCounts(): void
    {
        $items = new InMemoryRemediationItemRepository();
        $water = $items->insert($this->row(['page_uid' => 3, 'proposed_value' => 'Bar chart of WATER prices']));
        $items->insert($this->row(['page_uid' => 3, 'proposed_value' => 'Portrait of the mayor']));
        $items->insert($this->row(['page_uid' => 99, 'proposed_value' => 'Water tower']));
        $access = $this->access([99]);
        $searched = [];
        $text = static function (array $item) use (&$searched): string {
            $searched[] = (int)$item['uid'];
            return (string)$item['proposed_value'];
        };

        $result = (new RemediationQueueService($items, $access))->list(['main'], ['tab' => 'open', 'query' => "  water\t "], $text);

        self::assertSame([$water], array_map(static fn (array $item): int => (int)$item['uid'], $result['items']));
        self::assertSame(1, $result['total']);
        self::assertSame(2, $result['counts']['open'], 'Tab counts stay unfiltered, so "1 of 2 shown" can be said.');
        self::assertNotContains(3, $searched, 'A row the person may not see is never searched.');
        self::assertSame(RemediationQueueService::PER_PAGE, $result['perPage']);
    }

    #[Test]
    public function theSearchTextIsTrimmedCollapsedLowercasedAndCapped(): void
    {
        self::assertSame('water tariffs', RemediationQueueService::normalizeQuery("  Water \n Tariffs "));
        self::assertSame(RemediationQueueService::QUERY_MAX_LENGTH, mb_strlen(RemediationQueueService::normalizeQuery(str_repeat('ä', 300))));
    }

    #[Test]
    public function olderOpenWorkStaysReachableBehindAThousandNewerCompletedItems(): void
    {
        $items = new InMemoryRemediationItemRepository();
        $pending = $items->insert($this->row(['tstamp' => 1000, 'proposed_value' => 'Older chart of tariffs']));
        for ($i = 1; $i <= 1000; $i++) {
            $items->insert($this->row(['tstamp' => 2000 + $i, 'status' => RemediationStatus::VERIFIED]));
        }
        $queue = new RemediationQueueService($items, $this->access());
        $text = static fn (array $item): string => (string)$item['proposed_value'];

        $open = $queue->list(['main'], ['tab' => 'open', 'status' => RemediationStatus::PENDING_REVIEW, 'pageUid' => 3, 'languageUid' => 0]);
        self::assertSame([$pending], array_map(static fn (array $item): int => (int)$item['uid'], $open['items']));
        self::assertSame(['open' => 1, 'mine' => 1, 'done' => 1000], $open['counts']);
        self::assertSame(1, $queue->list(['main'], ['tab' => 'mine'])['total']);
        self::assertSame([$pending], array_map(static fn (array $item): int => (int)$item['uid'], $queue->list(['main'], ['tab' => 'open', 'query' => 'older chart'], $text)['items']));

        // Completed history is paged completely: the last page holds the oldest completed items.
        $done = $queue->list(['main'], ['tab' => 'done', 'page' => 40]);
        self::assertSame([1000, 40, 40], [$done['total'], $done['pages'], $done['page']]);
        self::assertSame([2025, 2001], [(int)$done['items'][0]['tstamp'], (int)$done['items'][24]['tstamp']]);
        self::assertSame(1000, $queue->list(['main'], ['tab' => 'done', 'query' => 'verified'], static fn (): string => 'verified')['total']);
    }

    #[Test]
    public function withoutTheQueuePermissionOnlyOwnRequestsOfThisWorkspaceAreListedAndCounted(): void
    {
        $items = new InMemoryRemediationItemRepository();
        $own = $items->insert($this->row([]));
        $items->insert($this->row(['created_by' => 8]));
        $items->insert($this->row(['workspace_id' => 4]));
        $items->insert($this->row(['created_by' => 8, 'status' => RemediationStatus::VERIFIED]));

        $result = (new RemediationQueueService($items, $this->access([], false)))->list(['main'], ['tab' => 'open']);

        self::assertSame([$own], array_map(static fn (array $item): int => (int)$item['uid'], $result['items']));
        self::assertSame(['open' => 1, 'mine' => 1, 'done' => 0], $result['counts']);
    }

    /** The access rule of RemediationAccessService::canSee(), by its parts: readable pages, workspace 0, ai.bulk. */
    private function access(array $unreadablePages = [], bool $bulk = true): RemediationAccessService
    {
        $access = $this->createMock(RemediationAccessService::class);
        $access->method('canReadPage')->willReturnCallback(static fn (int $pageUid): bool => !in_array($pageUid, $unreadablePages, true));
        $access->method('currentWorkspace')->willReturn(0);
        $access->method('mayUseBulk')->willReturn($bulk);
        $access->method('canReadTargetRecord')->willReturn(true);
        $access->method('userUid')->willReturn(7);
        $access->method('applyRefusal')->willReturn('');

        return $access;
    }

    /** @param array<string,mixed> $overrides */
    private function row(array $overrides): array
    {
        return $overrides + ['site_identifier' => 'main', 'page_uid' => 3, 'language_uid' => 0, 'workspace_id' => 0, 'created_by' => 7, 'finding_uid' => 1, 'origin' => 'bulk', 'owner' => 'editor', 'status' => RemediationStatus::PENDING_REVIEW];
    }
}
