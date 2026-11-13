<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpSettingsViewBuilder;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * First real activation of AQG 3.0.0 MCP (typo3test14, 2026-11-09), TYPO3 side:
 * - right after the code was confirmed the tab reloaded into the red "Connector offline" alert, although the connector
 *   process had simply not polled yet (it reported ten seconds later; a manual reload showed Connected). A fresh
 *   connection without any heartbeat is "starting" now — a wait the tab polls itself out of — and becomes "offline" only
 *   after the grace; a pending activation (the Gateway answers 401 until it) is "starting" too, never "revoked";
 * - the audit trail is paginated in the database: stable newest-first order (uid breaks same-second ties), pages that
 *   neither overlap nor skip, the retention window and the Refused filter applied to count and rows alike.
 */
final class McpPostConnectionFunctionalTest extends AbstractFunctionalTestCase
{
    private const PAIRING = '6d3a1c0e-2f4b-4a5c-9d6e-7f8a9b0c1d2e';

    /** @var list<string> */
    private array $paths = [];
    /** @var array{0: int, 1: array<string, mixed>} */
    private array $statusAnswer = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(\Priebera\A11yQualityGate\Mcp\McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->paths = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request) {
            $path = $request->getUri()->getPath();
            $this->paths[] = $path;
            [$status, $json] = match (true) {
                str_ends_with($path, '/mcp/connector/status') => $this->statusAnswer,
                str_ends_with($path, '/mcp/connector/tokens') => [200, ['success' => true, 'tokens' => []]],
                default => [404, ['success' => false, 'error' => ['code' => 'route_not_found']]],
            };

            return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($json)));
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        putenv(\Priebera\A11yQualityGate\Mcp\McpHostKey::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function connect(bool $activate = true): McpConnectionStore
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://aqg.example/app/agent-access', 'admin');
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, 'aqgc_' . str_repeat('a', 40), 'typo3-14', 'https://gw.example/mcp/i/typo3-14'));
        if ($activate) {
            self::assertTrue($store->markActivatedIfCurrent((string)$store->currentCredential()['credentialId']));
        }

        return $store;
    }

    /** @param array<string, mixed> $installation */
    private function gatewayReports(array $installation, int $status = 200): void
    {
        $this->statusAnswer = $status === 200
            ? [200, ['success' => true, 'installation' => $installation + ['licenceActive' => true], 'quotas' => []]]
            : [$status, ['success' => false, 'error' => ['code' => 'connector_unauthorized']]];
    }

    private function state(?int $now = null): string
    {
        return $this->get(McpSettingsViewBuilder::class)->resolveState(true, null, $now)['state'];
    }

    #[Test]
    public function aFreshConnectionWithoutAHeartbeatIsStartingNotOffline(): void
    {
        $this->connect();
        $this->gatewayReports(['lastHeartbeatAt' => null, 'connectorOnline' => false]);
        self::assertSame('starting', $this->state(), 'the connector has not polled yet: a wait, not a failure');
        self::assertSame('offline', $this->state(time() + McpSettingsViewBuilder::STARTING_GRACE_SECONDS + 1), 'past the grace it is offline');

        $this->gatewayReports(['lastHeartbeatAt' => gmdate('Y-m-d\TH:i:s\Z'), 'connectorOnline' => true]);
        self::assertSame('connected', $this->state());

        $this->gatewayReports(['lastHeartbeatAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 120), 'connectorOnline' => false]);
        self::assertSame('offline', $this->state(), 'a connector that reported and then stopped is offline at once');
    }

    #[Test]
    public function aPendingActivationIsStartingAndAsksTheGatewayNothing(): void
    {
        $this->connect(activate: false);
        $this->gatewayReports([], 401);
        self::assertSame('starting', $this->state(), 'the Gateway answers 401 until activation: expected, never "revoked"');
        self::assertSame([], array_values(array_filter($this->paths, static fn (string $path): bool => str_contains($path, '/mcp/connector/'))), 'no connector call before activation');
        self::assertSame('revoked', $this->state(time() + McpSettingsViewBuilder::STARTING_GRACE_SECONDS + 1), 'still refused long after pairing: the Gateway ended it');
    }

    #[Test]
    public function anActivatedConnectionTheGatewayRefusesIsRevoked(): void
    {
        $this->connect();
        $this->gatewayReports([], 401);
        self::assertSame('revoked', $this->state());
    }

    #[Test]
    public function theAuditTrailIsPaginatedInTheDatabaseNewestFirstWithStableTies(): void
    {
        $repository = $this->get(McpAuditRepository::class);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT);
        $now = time();
        // 60 entries: three seconds of 20 each (same crdate within a second), one in five refused, and two past retention.
        for ($i = 0; $i < 60; $i++) {
            $repository->record('tool_call', $i % 5 === 0 ? 'refused' : 'ok', 'agent', ['beUserUid' => 3, 'target' => 'n' . $i]);
        }
        $rows = $connection->select(['uid'], Tables::MCP_AUDIT, [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        foreach ($rows as $index => $row) {
            $connection->update(Tables::MCP_AUDIT, ['crdate' => $now - 100 + intdiv($index, 20)], ['uid' => $row['uid']]);
        }
        $connection->insert(Tables::MCP_AUDIT, ['crdate' => $now - 91 * 86400, 'action' => 'old', 'result' => 'ok', 'source' => 'agent']);
        $connection->insert(Tables::MCP_AUDIT, ['crdate' => $now - 92 * 86400, 'action' => 'old', 'result' => 'refused', 'source' => 'agent']);

        $first = $repository->findPage(1, 25, '', $now);
        self::assertSame(60, $first['total'], 'entries past the retention window are not counted');
        self::assertSame(3, $first['pages']);
        self::assertCount(25, $first['rows']);

        $seen = [];
        $previous = null;
        foreach ([1, 2, 3] as $number) {
            $page = $repository->findPage($number, 25, '', $now);
            foreach ($page['rows'] as $row) {
                $key = [(int)$row['crdate'], (int)$row['uid']];
                if ($previous !== null) {
                    self::assertTrue($key[0] < $previous[0] || ($key[0] === $previous[0] && $key[1] < $previous[1]), 'strictly newest first, uid breaks ties');
                }
                $previous = $key;
                $seen[] = (int)$row['uid'];
            }
        }
        self::assertCount(60, $seen);
        self::assertCount(60, array_unique($seen), 'pages neither overlap nor skip');
        self::assertCount(10, $repository->findPage(3, 25, '', $now)['rows']);

        $beyond = $repository->findPage(99, 25, '', $now);
        self::assertSame(3, $beyond['page'], 'a page past the end shows the last page');
        self::assertSame(array_column($repository->findPage(3, 25, '', $now)['rows'], 'uid'), array_column($beyond['rows'], 'uid'));

        $refused = $repository->findPage(1, 25, 'refused', $now);
        self::assertSame(12, $refused['total'], 'the filter applies to the count too');
        self::assertSame(['refused'], array_values(array_unique(array_column($refused['rows'], 'result'))));

        self::assertSame(McpAuditRepository::MAX_PAGE_SIZE, $repository->findPage(1, 10000, '', $now)['perPage'], 'the page size is bounded');
        self::assertSame(['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 25], $repository->findPage(4, 25, 'unknown-filter-is-all', $now + 400 * 86400));
    }

    #[Test]
    public function theSettingsViewCarriesOnePageAndLinksThatKeepTheFilter(): void
    {
        $repository = $this->get(McpAuditRepository::class);
        for ($i = 0; $i < 30; $i++) {
            $repository->record('consent_granted', $i < 27 ? 'ok' : 'denied', 'backend', ['beUserUid' => 1]);
        }
        $view = $this->get(McpSettingsViewBuilder::class)->build(true, 'agency', ['id' => '1'], '', 2);
        self::assertCount(5, $view['audit']);
        self::assertSame(2, $view['auditPagination']['currentPage']);
        self::assertSame(26, $view['auditPagination']['firstItem']);
        self::assertSame(30, $view['auditPagination']['lastItem']);
        self::assertSame(30, $view['auditPagination']['totalItems']);
        self::assertStringNotContainsString('mcpAuditPage', $view['auditPagination']['previousUrl'], 'page 1 carries no page parameter');
        self::assertStringEndsWith('#aqg-mcp-audit', $view['auditPagination']['previousUrl']);
        self::assertSame('', $view['auditPagination']['nextUrl']);

        $refused = $this->get(McpSettingsViewBuilder::class)->build(true, 'agency', ['id' => '1'], 'refused', 1);
        self::assertSame(3, $refused['auditPagination']['totalItems']);
        foreach ($refused['auditPagination']['items'] as $item) {
            self::assertStringContainsString('mcpAudit=refused', urldecode((string)$item['url']), 'page links keep the filter');
        }
        self::assertArrayNotHasKey('token_suffix', $refused['audit'][0], 'rows are described, never passed through raw');
    }

    #[Test]
    public function theTabRendersStartingAsAPollingStatusAndThePagedTrail(): void
    {
        $this->connect();
        $this->gatewayReports(['lastHeartbeatAt' => null, 'connectorOnline' => false, 'pairedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        $repository = $this->get(McpAuditRepository::class);
        for ($i = 0; $i < 30; $i++) {
            $repository->record('tool_call', 'ok', 'agent', ['beUserUid' => 1]);
        }
        $html = $this->renderTab($this->get(McpSettingsViewBuilder::class)->build(true, 'agency', ['id' => '1'], '', 1));
        $xpath = $this->xpath($html);

        $starting = $xpath->query('//*[@data-aqg-mcp-starting="true"]');
        self::assertSame(1, $starting->length, 'the starting status line is rendered');
        $line = $starting->item(0);
        self::assertInstanceOf(\DOMElement::class, $line);
        self::assertSame('status', $line->getAttribute('role'), 'a polite status, never an alert');
        self::assertStringContainsString('/a11y/settings/mcp/connection-state', $line->getAttribute('data-state-url'));
        self::assertSame(0, $xpath->query('//*[@role="alert"]')->length, 'no alert while the connector starts');
        self::assertSame(1, $xpath->query('//*[@id="aqg-mcp-agents-title"]')->length, 'the agents list stays');
        self::assertSame(1, $xpath->query('//*[@id="aqg-mcp-disconnect-title"]')->length, 'the emergency disconnect stays');

        self::assertSame(25, $xpath->query('//*[@id="aqg-mcp-audit"]//tbody/tr')->length, 'one page of the trail');
        $next = $xpath->query('//*[@id="aqg-mcp-audit"]//a[@rel="next"]');
        self::assertSame(1, $next->length);
        self::assertStringContainsString('mcpAuditPage=2', urldecode((string)$next->item(0)?->attributes?->getNamedItem('href')?->nodeValue));
        self::assertSame(0, $xpath->query('//*[@id="aqg-mcp-audit"]//a[@rel="prev"]')->length, 'no previous link on page 1');
        self::assertStringContainsString('1–25', (string)$xpath->query('//*[@id="aqg-mcp-audit"]//*[contains(@class,"aqg-pagination__info")]')->item(0)?->textContent);
    }

    #[Test]
    public function aLongTrailBuildsLinksOnlyForTheShownPages(): void
    {
        $repository = $this->get(McpAuditRepository::class);
        for ($i = 0; $i < 200; $i++) {
            $repository->record('tool_call', 'ok', 'agent', ['beUserUid' => 1]);
        }
        $pagination = $this->get(McpSettingsViewBuilder::class)->build(true, 'agency', ['id' => '1'], '', 4)['auditPagination'];
        self::assertSame(8, $pagination['totalPages']);
        $pages = array_values(array_map(static fn (array $item): string => $item['label'], array_filter($pagination['items'], static fn (array $item): bool => $item['type'] === 'page')));
        self::assertSame(['1', '3', '4', '5', '8'], $pages);
        foreach ($pagination['items'] as $item) {
            if ($item['type'] === 'page') {
                self::assertStringContainsString('#aqg-mcp-audit', (string)$item['url'], 'every shown page link is a real link');
            }
        }
        self::assertStringContainsString('mcpAuditPage=3', urldecode((string)$pagination['previousUrl']));
        self::assertStringContainsString('mcpAuditPage=5', urldecode((string)$pagination['nextUrl']));
    }

    /** @param array<string, mixed> $mcp */
    private function renderTab(array $mcp): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/SettingsTabMcp.html',
        ));
        $view->assignMultiple(['activeTab' => 'mcp', 'mcp' => $mcp]);

        return $view->render();
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
