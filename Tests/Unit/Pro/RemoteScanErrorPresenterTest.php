<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;

/**
 * A frontend scan of a site whose base URL is a DDEV host (typo314.ddev.site resolves to 127.0.0.1) was refused by
 * the crawler as `private_network_blocked` and reached the editor as "The frontend scan request failed. Details are
 * in the TYPO3 log." The crawler's network boundary is a site configuration to fix: the editor gets that, never the
 * resolved address, and never a licence message.
 */
final class RemoteScanErrorPresenterTest extends TestCase
{
    #[Test]
    public function aSiteOnAPrivateNetworkIsExplainedAsASiteConfigurationProblem(): void
    {
        // ProCrawlerService::submit() wraps the crawler's 400 in a TokenRefreshException.
        $exception = new TokenRefreshException(
            'Remote crawler submit failed: AQG crawler HTTP 400: Resolved IP 127.0.0.1 is not allowed | code=private_network_blocked',
            0,
            new ApiRequestFailedException('AQG crawler HTTP 400: Resolved IP 127.0.0.1 is not allowed', 400, null, 'private_network_blocked'),
        );

        $presented = (new RemoteScanErrorPresenter())->present($exception);

        self::assertSame(400, $presented['status']);
        self::assertSame('private_network_blocked', $presented['code']);
        self::assertSame('Site not reachable from the internet', $presented['title']);
        self::assertStringContainsString('local or private network', $presented['message']);
        self::assertStringContainsString('Site Configuration', $presented['message']);
        self::assertStringNotContainsString('127.0.0.1', $presented['title'] . $presented['message']);
        // It names the security safeguard and rules the licence out instead of sending the editor to the licence settings.
        self::assertStringContainsString('security safeguard, not because of the licence', $presented['message']);
        self::assertStringContainsString('Content scans keep working', $presented['message']);
        self::assertStringNotContainsString('revalidate', strtolower($presented['message']));
        self::assertStringNotContainsString('licence settings', strtolower($presented['message']));
        self::assertStringNotContainsString('TYPO3 log', $presented['message']);
    }

    #[Test]
    public function anUnknownHostTellsTheEditorToCheckTheBaseUrl(): void
    {
        $presented = (new RemoteScanErrorPresenter())->present(
            new ApiRequestFailedException('Could not resolve hostname', 400, null, 'dns_lookup_failed')
        );

        self::assertSame(400, $presented['status']);
        self::assertSame('Site address not found', $presented['title']);
        self::assertStringContainsString('base URL', $presented['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlSafetyCodeProvider(): iterable
    {
        foreach (['invalid_url', 'invalid_url_scheme', 'invalid_url_host', 'credentials_not_allowed', 'invalid_start_url'] as $code) {
            yield $code => [$code];
        }
    }

    #[Test]
    #[DataProvider('urlSafetyCodeProvider')]
    public function theScannersOtherAddressRefusalsAreATargetProblem(string $code): void
    {
        $presented = (new RemoteScanErrorPresenter())->present(new ApiRequestFailedException('refused', 400, null, $code));

        self::assertSame(400, $presented['status']);
        self::assertSame('Remote scan target rejected', $presented['title']);
    }

    #[Test]
    public function licenceRefusalsStayLicenceProblems(): void
    {
        $presenter = new RemoteScanErrorPresenter();

        self::assertSame('Licence authentication failed', $presenter->present(new ApiRequestFailedException('revoked', 401, null, 'token_revoked'))['title']);
        self::assertSame('Licence authentication failed', $presenter->present(new TokenRefreshException('Invalid licence key'))['title']);
    }

    /**
     * @return iterable<string, array{0:string, 1:string, 2:string}>
     */
    public static function domainRefusalProvider(): iterable
    {
        yield 'not activated' => ['domain_not_activated', 'Domain not activated', 'Licence domains'];
        yield 'not detected' => ['domain_not_detected', 'Domain not in this installation', 'Site Configuration'];
        yield 'limit reached' => ['domain_limit_reached', 'Domain limit reached', 'Agency'];
    }

    /**
     * A licence refused only for the scanned site's domain (ProTokenService / ProCrawlerService keep the API code)
     * names the next step, instead of "revalidate the licence" or "the licence does not include this feature".
     */
    #[Test]
    #[DataProvider('domainRefusalProvider')]
    public function aDomainRefusalNamesItsNextStep(string $code, string $title, string $hint): void
    {
        $presented = (new RemoteScanErrorPresenter())->present(new TokenRefreshException(
            'AQG token issuance failed.',
            0,
            new ApiRequestFailedException('AQG token issuance refused for the domain.', 403, null, $code),
        ));

        self::assertSame(403, $presented['status']);
        self::assertSame($code, $presented['code']);
        self::assertSame($title, $presented['title']);
        self::assertStringContainsString($hint, $presented['message']);
        self::assertStringNotContainsString('Revalidate', $presented['message']);
    }

    /**
     * Every crawler code a paid frontend scan request can receive (aqg-crawler routes and URL safety, 2026-10)
     * maps to the next step it needs; none falls back to the generic "request failed" or names an internal detail.
     *
     * @return iterable<string, array{0:string, 1:int, 2:string}>
     */
    public static function crawlerCodeInventory(): iterable
    {
        foreach ([
            ['private_network_blocked', 400, 'Site not reachable from the internet'],
            ['dns_lookup_failed', 400, 'Site address not found'],
            ['cross_host_redirect_blocked', 400, 'Page redirects to another host'],
            ['ip_literal_blocked', 400, 'Remote scan target rejected'],
            ['invalid_url_port', 400, 'Remote scan target rejected'],
            ['invalid_url_scheme', 400, 'Remote scan target rejected'],
            ['credentials_not_allowed', 400, 'Remote scan target rejected'],
            ['missing_site_url', 400, 'Remote scan target rejected'],
            ['too_many_active_jobs', 429, 'Remote scan limit reached'],
            ['trial_crawl_limit', 429, 'Trial limit reached'],
            ['token_expired', 401, 'Licence authentication failed'],
            ['token_revoked', 401, 'Licence authentication failed'],
            ['token_rejected', 401, 'Licence authentication failed'],
            ['installation_identity_mismatch', 403, 'Licence authentication failed'],
            ['site_identity_mismatch', 403, 'Licence authentication failed'],
            ['missing_license_subject', 401, 'Licence authentication failed'],
            ['licence_project_removed', 403, 'Licence authentication failed'],
            ['domain_mismatch', 403, 'Domain not activated'],
            ['domain_not_activated', 403, 'Domain not activated'],
            ['domain_not_detected', 403, 'Domain not in this installation'],
            ['domain_limit_reached', 403, 'Domain limit reached'],
            ['forbidden_resource', 403, 'Scan not available to this licence'],
            ['job_site_mismatch', 400, 'Scan not available to this licence'],
            ['feature_not_available', 403, 'Remote scan not allowed'],
            ['not_found', 404, 'Remote scan not found'],
            ['internal_error', 500, 'Remote scanner unavailable'],
        ] as [$code, $status, $title]) {
            yield $code => [$code, $status, $title];
        }
    }

    #[Test]
    #[DataProvider('crawlerCodeInventory')]
    public function everyCrawlerCodeHasItsOwnNextStep(string $code, int $httpStatus, string $title): void
    {
        $presented = (new RemoteScanErrorPresenter())->present(new TokenRefreshException(
            'Remote crawler submit failed: AQG crawler HTTP ' . $httpStatus . ' | code=' . $code . ' | url=https://api.example/crawl/submit',
            0,
            new ApiRequestFailedException('AQG crawler HTTP ' . $httpStatus . ' at https://api.example/crawl/submit', $httpStatus, null, $code),
        ));

        self::assertSame($title, $presented['title']);
        self::assertSame($code, $presented['code']);
        self::assertStringNotContainsString('api.example', $presented['title'] . $presented['message']);
        self::assertStringNotContainsString($code, $presented['message']);
    }
}
