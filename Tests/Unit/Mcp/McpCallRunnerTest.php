<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpCallRunner;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * One call as the connector and its workers run it: begin first, then consent, then the tool. Fable F-05: an answer is
 * measured as UTF-8 (a Cyrillic or CJK page is not six bytes per character) and one beyond the Gateway's relay limit is
 * replaced by `result_too_large`, so the agent learns to ask for a smaller page instead of waiting for a timeout.
 * Fable F-02: a call handed to a process under another tool name than the one the Gateway began is never run.
 */
final class McpCallRunnerTest extends TestCase
{
    private const CALL = ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_list_scans', 'arguments' => ['siteIdentifier' => 'main']];

    private McpGatewayClient&MockObject $gateway;
    private McpToolExecutor&MockObject $executor;
    private McpAuditRepository&MockObject $audit;
    /** @var list<array<string, mixed>> */
    private array $submitted = [];
    /** @var list<array{0: string, 1: string}> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(McpGatewayClient::class);
        $this->executor = $this->createMock(McpToolExecutor::class);
        $this->audit = $this->createMock(McpAuditRepository::class);
        $this->audit->method('record')->willReturnCallback(function (string $action, string $result, string $source, array $context = []): void {
            $this->audited[] = [$action, $result . ($context['reason'] ?? '' ? ':' . $context['reason'] : '')];
        });
        $this->gateway->method('submitResult')->willReturnCallback(function (string $callId, string $claimId, array $payload): void {
            $this->submitted[] = $payload;
        });
    }

    private function runner(): McpCallRunner
    {
        $ledger = $this->createMock(McpConsentLedger::class);
        $ledger->method('isRecorded')->willReturn(true);

        return new McpCallRunner($this->gateway, $this->executor, $this->audit, $ledger, $this->createMock(LogManager::class));
    }

    private function begins(string $tool = 'aqg_list_scans'): void
    {
        $this->gateway->method('beginCall')->willReturn(['remainingMs' => 8000, 'tool' => $tool, 'principal' => [
            'tokenId' => 'tttttttt-0000-4000-8000-000000000001', 'tokenSuffix' => 'abcd', 'clientName' => 'Agent', 'beUserUid' => 7,
            'beUserName' => 'Eva', 'scopes' => ['history:read'], 'siteIdentifiers' => ['main'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'consentRefHash' => str_repeat('a', 64),
        ]]);
    }

    /** @return array<string, mixed> a result of $bytes UTF-8 bytes of two-byte Cyrillic text */
    private static function cyrillicResult(int $bytes): array
    {
        return ['scans' => [['title' => str_repeat('Ж', intdiv($bytes, 2))]]];
    }

    #[Test]
    public function aLargeNonLatinResultBelowTheLimitIsDeliveredAsUtf8(): void
    {
        $this->begins();
        // 200 KiB of Cyrillic: 1.2 MiB with \u escapes, which the Gateway's 320 KiB body limit refused before F-05.
        $result = self::cyrillicResult(200 * 1024);
        $this->executor->method('execute')->willReturn($result);

        self::assertSame('executed', $this->runner()->run(self::CALL));
        self::assertSame([['ok' => true, 'result' => $result]], $this->submitted);
        $wire = json_encode($this->submitted[0], McpCallRunner::JSON_FLAGS);
        self::assertLessThan(McpCallRunner::MAX_RESULT_BYTES, strlen((string)$wire));
        self::assertStringNotContainsString('\\u0416', (string)$wire, 'no \\u escapes');
        self::assertSame([['aqg_list_scans', 'allowed']], $this->audited);
    }

    #[Test]
    public function aResultBeyondTheRelayLimitIsAnsweredAsResultTooLargeAndNothingOfItIsSent(): void
    {
        $this->begins();
        $this->executor->method('execute')->willReturn(self::cyrillicResult(McpCallRunner::MAX_RESULT_BYTES + 2));

        self::assertSame('executed', $this->runner()->run(self::CALL));
        self::assertCount(1, $this->submitted);
        self::assertFalse($this->submitted[0]['ok']);
        self::assertSame('result_too_large', $this->submitted[0]['error']['code']);
        self::assertStringContainsString('pageSize', $this->submitted[0]['error']['message']);
        self::assertStringNotContainsString('Ж', (string)json_encode($this->submitted[0], McpCallRunner::JSON_FLAGS));
        self::assertSame([['aqg_list_scans', 'refused:result_too_large']], $this->audited);
    }

    #[Test]
    public function theSizeBoundaryIsTheEncodedAnswer(): void
    {
        $envelope = McpCallRunner::encodedSize(['ok' => true, 'result' => ['scans' => [['title' => '']]]]);
        $fits = ['ok' => true, 'result' => ['scans' => [['title' => str_repeat('a', McpCallRunner::MAX_RESULT_BYTES - $envelope)]]]];
        self::assertSame(McpCallRunner::MAX_RESULT_BYTES, McpCallRunner::encodedSize($fits));
        self::assertSame(McpCallRunner::MAX_RESULT_BYTES + 3, McpCallRunner::encodedSize(['ok' => true, 'result' => ['scans' => [['title' => str_repeat('a', McpCallRunner::MAX_RESULT_BYTES - $envelope) . '漢']]]]));
        self::assertSame(strlen('{"t":"a/b"}'), McpCallRunner::encodedSize(['t' => 'a/b']), 'slashes are not escaped');
        self::assertGreaterThan(0, McpCallRunner::encodedSize(['broken' => "\xC3\x28"]), 'invalid UTF-8 is substituted, never a failed answer');
    }

    #[Test]
    public function aCallBegunUnderAnotherToolNameIsNeverRun(): void
    {
        $this->begins('aqg_start_scan');
        $this->executor->expects(self::never())->method('execute');

        $this->runner()->run(self::CALL);
        self::assertSame('call_mismatch', $this->submitted[0]['error']['code'] ?? null);
    }

    #[Test]
    public function aBeginTheGatewayRefusesRunsNothingAndAnswersNothing(): void
    {
        $this->gateway->method('beginCall')->willThrowException(new ApiRequestFailedException('refused', 403, null, 'token_revoked'));
        $this->executor->expects(self::never())->method('execute');

        self::assertSame('not_started', $this->runner()->run(self::CALL));
        self::assertSame([], $this->submitted);
        self::assertSame([['aqg_list_scans', 'refused:token_revoked']], $this->audited);
    }

    #[Test]
    public function aGatewayBeforeF02ThatNamesNoToolStillRunsTheCall(): void
    {
        $this->begins('');
        $this->executor->expects(self::once())->method('execute')->willReturn(['sites' => []]);

        self::assertSame('executed', $this->runner()->run(self::CALL));
    }
}
