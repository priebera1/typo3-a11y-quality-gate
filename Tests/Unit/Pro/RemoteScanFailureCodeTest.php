<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;

/**
 * AQG 2.0.2 persona audit D3b: a crawler job that failed ("Sitemap request failed … HTTP 404") showed "Frontend scan did
 * not complete successfully." while the toast promised "The reason is shown in the scan status". The crawler now
 * reports a stable failure code; the status names the reason from it. Its free-text `error` is never read.
 */
final class RemoteScanFailureCodeTest extends TestCase
{
    private const JOB = 'a0000000-0000-4000-8000-000000000001';

    #[Test]
    public function aFailedStatusCarriesTheCrawlersFailureCodeButNotItsText(): void
    {
        $result = CrawlerStatusResult::fromResponseDto(CrawlerStatusResponseDto::fromArray([
            'jobId' => self::JOB,
            'status' => 'failed',
            'error' => 'Sitemap request failed for https://internal.example/sitemap.xml with HTTP 404',
            'errorCode' => 'sitemap_not_found',
        ]));

        self::assertSame('sitemap_not_found', $result->failureCode);
        self::assertStringNotContainsString('internal.example', json_encode(get_object_vars($result), JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function noFailureCodeProvider(): iterable
    {
        yield 'a running job' => [['status' => 'active', 'errorCode' => 'sitemap_not_found']];
        yield 'an older crawler without codes' => [['status' => 'failed', 'error' => 'Worker job timed out after 1ms']];
        yield 'a code that is not an identifier' => [['status' => 'failed', 'errorCode' => 'Sitemap <b>not</b> found']];
        yield 'a code that is not a string' => [['status' => 'failed', 'errorCode' => ['sitemap_not_found']]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('noFailureCodeProvider')]
    public function onlyAFailedJobHasAFailureCode(array $payload): void
    {
        $result = CrawlerStatusResult::fromResponseDto(CrawlerStatusResponseDto::fromArray(['jobId' => self::JOB] + $payload));

        self::assertNull($result->failureCode);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failureCodeProvider(): iterable
    {
        yield 'sitemap 404' => ['sitemap_not_found', 'Sitemap not found', 'EXT:seo'];
        yield 'sitemap refused' => ['sitemap_http_error', 'Sitemap could not be loaded', 'HTTP authentication'];
        yield 'sitemap broken' => ['sitemap_invalid', 'Sitemap is not valid', 'no usable XML sitemap'];
        yield 'sitemap elsewhere' => ['sitemap_blocked', 'Sitemap points to another host', 'own host name'];
        yield 'site down' => ['site_unreachable', 'Site did not answer', 'online and reachable'];
        yield 'unknown host' => ['site_not_found', 'Site address not found', 'base URL'];
        yield 'private host' => ['site_not_public', 'Site not reachable from the internet', 'local or private network'];
        yield 'certificate' => ['tls_certificate_invalid', 'TLS certificate not valid', 'Renew or correct the certificate'];
        yield 'time limit' => ['job_timeout', 'Scan took too long', 'Scan fewer pages'];
        yield 'stalled' => ['scan_stalled', 'Scan stopped', 'Start the scan again'];
        yield 'entitlement' => ['entitlement_ended', 'Licence ended before the scan started', 'Check the licence'];
        yield 'anything else' => ['scan_failed', 'Frontend scan failed', 'contact AQG support'];
    }

    #[Test]
    #[DataProvider('failureCodeProvider')]
    public function eachFailureCodeNamesItsReasonAndNextStep(string $code, string $title, string $messagePart): void
    {
        $presented = (new RemoteScanErrorPresenter())->presentJobFailure($code);

        self::assertSame($code, $presented['code']);
        self::assertSame($title, $presented['title']);
        self::assertStringContainsString($messagePart, $presented['message']);
        self::assertStringNotContainsString('did not complete successfully', $presented['message']);
        self::assertStringNotContainsString('TYPO3 log', $presented['message']);
    }

    #[Test]
    public function aCodeThisReleaseDoesNotKnowIsTheGenericFailure(): void
    {
        $unknown = (new RemoteScanErrorPresenter())->presentJobFailure('future_reason');
        $missing = (new RemoteScanErrorPresenter())->presentJobFailure(null);

        self::assertSame(['future_reason', 'Frontend scan failed'], [$unknown['code'], $unknown['title']]);
        self::assertSame(['scan_failed', 'Frontend scan failed'], [$missing['code'], $missing['title']]);
    }
}
