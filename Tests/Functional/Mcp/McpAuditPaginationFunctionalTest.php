<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The agent access audit trail's database pages (post-pairing fix 5, Fable observation 5): newest first with `uid`
 * breaking ties of one second, every entry of the window on exactly one page, the "Refused" filter and the retention
 * window applied to count and rows alike, a page past the end showing the last one — with the deferred join.
 */
final class McpAuditPaginationFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function everyEntryOfTheWindowIsOnExactlyOnePageInAStableOrder(): void
    {
        $now = 2_000_000_000;
        $rows = [];
        for ($index = 0; $index < 130; $index++) {
            // Bursts of ten entries in the same second; every third one refused; ten older than the 90-day window.
            $crdate = $index < 10 ? $now - 91 * 86400 : $now - intdiv($index, 10) * 60;
            $rows[] = [$crdate, 'aqg_whoami', $index % 3 === 0 ? 'refused' : 'allowed', 'connector'];
        }
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)->bulkInsert(Tables::MCP_AUDIT, $rows, ['crdate', 'action', 'result', 'source']);
        $repository = $this->get(McpAuditRepository::class);

        $first = $repository->findPage(1, 25, '', $now);
        self::assertSame(120, $first['total'], 'the ten entries beyond 90 days are outside the window');
        self::assertSame(5, $first['pages']);
        $seen = [];
        $previous = null;
        for ($page = 1; $page <= 5; $page++) {
            $result = $repository->findPage($page, 25, '', $now);
            self::assertSame($page, $result['page']);
            foreach ($result['rows'] as $row) {
                $key = [(int)$row['crdate'], (int)$row['uid']];
                if ($previous !== null) {
                    self::assertTrue($key[0] < $previous[0] || ($key[0] === $previous[0] && $key[1] < $previous[1]), 'newest first, uid breaks ties');
                }
                $previous = $key;
                $seen[] = (int)$row['uid'];
                self::assertGreaterThanOrEqual($now - 90 * 86400, (int)$row['crdate']);
            }
        }
        self::assertCount(120, $seen);
        self::assertCount(120, array_unique($seen), 'no entry on two pages');

        $last = $repository->findPage(99, 25, '', $now);
        self::assertSame(5, $last['page'], 'a page past the end shows the last one');
        self::assertCount(20, $last['rows']);

        $refused = $repository->findPage(1, 100, 'refused', $now);
        self::assertSame(40, $refused['total']);
        self::assertCount(40, $refused['rows']);
        self::assertSame(['refused'], array_values(array_unique(array_column($refused['rows'], 'result'))));
        self::assertSame(100, $repository->findPage(1, 1000, '', $now)['perPage'], 'at most 100 per page');
        self::assertSame(['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 25], $repository->findPage(3, 25, 'refused', $now + 400 * 86400), 'an empty window');
    }
}
