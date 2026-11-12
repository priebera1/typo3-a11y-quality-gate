<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;

/**
 * Second Sol audit A4: an agent's scan idempotency key is reserved durably in the database (not in a per-process
 * Registry cache), so a second connector process or a restart sees it, and every attempt for one key carries the same
 * crawler Idempotency-Key. Each store instance below stands in for a separate connector process.
 */
final class McpScanIdempotencyFunctionalTest extends AbstractFunctionalTestCase
{
    private function process(): McpScanRequestStore
    {
        return new McpScanRequestStore($this->get(ConnectionPool::class), $this->get(LockFactory::class));
    }

    #[Test]
    public function aKeyIsReservedOnceAcrossProcessesAndRestarts(): void
    {
        $first = $this->process();
        $second = $this->process();
        $hash = $first->requestHash(7, 'retry-key-0001');
        $fingerprint = McpScanRequestStore::fingerprint('client-a', 2, 0);
        self::assertSame($hash, $second->requestHash(7, 'retry-key-0001'));

        $reserved = $first->reserve($hash, $fingerprint, 7);
        self::assertSame(['new', ''], [$reserved['state'], $reserved['jobId']]);
        // Another process (or the same one after a crash) before the crawler answered: same reservation, submit again.
        $again = $second->reserve($hash, $fingerprint, 7);
        self::assertSame(['reserved', ''], [$again['state'], $again['jobId']]);
        self::assertSame($first->crawlerKey($reserved['nonce']), $second->crawlerKey($again['nonce']), 'every attempt sends the same crawler key');

        $first->markSubmitted($hash, 'aaaaaaaa-1111-4111-8111-111111111111');
        $restarted = $this->process();
        $submitted = $restarted->reserve($hash, $fingerprint, 7);
        self::assertSame(['submitted', 'aaaaaaaa-1111-4111-8111-111111111111'], [$submitted['state'], $submitted['jobId']]);
        self::assertSame('conflict', $restarted->reserve($hash, McpScanRequestStore::fingerprint('client-a', 3, 0), 7)['state'], 'one key, another page');
        self::assertSame(1, (int)$this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_SCAN_REQUEST)->count('*', Tables::MCP_SCAN_REQUEST, []));
    }

    #[Test]
    public function crawlerKeysAreStableValidAndScopedToUserAndInstallation(): void
    {
        $store = $this->process();
        $nonce = $store->reserve($store->requestHash(7, 'retry-key-0001'), McpScanRequestStore::fingerprint('client-a', 2, 0), 7)['nonce'];
        $key = $store->crawlerKey($nonce);
        self::assertTrue(McpScanRequestStore::isCrawlerKey($key));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,255}$/', $key, 'accepted by the crawler (readIdempotencyKey)');
        self::assertStringNotContainsString('retry-key', $key, 'the agent\'s own key is never sent');
        self::assertNotSame($key, $store->crawlerKey($store->reserve($store->requestHash(8, 'retry-key-0001'), McpScanRequestStore::fingerprint('client-a', 2, 0), 8)['nonce']), 'another TYPO3 user, another run');
        $saved = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'another-installation-' . str_repeat('x', 80);
        self::assertNotSame($key, $store->crawlerKey($nonce), 'another installation on the same licence, another run');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $saved;
        self::assertFalse(McpScanRequestStore::isCrawlerKey('aqg-paid-' . str_repeat('a', 32)), 'only keys this store issues are accepted from the request attribute');
    }

    #[Test]
    public function attemptsForOneKeyRunOneAfterTheOtherAndExpireAfterSevenDays(): void
    {
        $store = $this->process();
        $hash = $store->requestHash(7, 'retry-key-0003');
        $order = [];
        $store->exclusively($hash, function () use (&$order, $hash): void {
            $order[] = 'outer';
            // A second attempt for another key is not blocked by this one.
            $this->process()->exclusively($this->process()->requestHash(7, 'other-key-01'), function () use (&$order): void {
                $order[] = 'other key';
            });
        });
        self::assertSame(['outer', 'other key'], $order);

        $fingerprint = McpScanRequestStore::fingerprint('client-a', 2, 0);
        $store->reserve($hash, $fingerprint, 7);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_SCAN_REQUEST);
        $connection->update(Tables::MCP_SCAN_REQUEST, ['crdate' => time() - McpScanRequestStore::TTL_SECONDS - 60], ['request_hash' => $hash]);
        $before = $connection->select(['nonce'], Tables::MCP_SCAN_REQUEST, ['request_hash' => $hash])->fetchOne();
        self::assertSame(1, $store->prune());
        $renewed = $store->reserve($hash, $fingerprint, 7);
        self::assertSame('new', $renewed['state']);
        self::assertNotSame($store->crawlerKey((string)$before), $store->crawlerKey($renewed['nonce']), 'a key reused after expiry is a new scan, never an old run reported as new');
    }
}
