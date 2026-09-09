<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreeAccessTokenService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;

final class FreeAccessTokenServiceTest extends TestCase
{
    #[Test]
    public function acceptsCompleteFreeDailyTokenContract(): void
    {
        $api = $this->createMock(AqgApiClient::class);
        $api->expects(self::once())->method('issueFreeToken')
            ->with('anonymous-id', 'https://example.test/', 'main', '1.9.0')
            ->willReturn($this->tokenResponse());

        $result = $this->service($api)->getValidToken(
            'https://example.test/',
            'main',
            '1.9.0',
        );

        self::assertSame('free', $result->plan);
        self::assertSame('free_daily', $result->entitlement);
        self::assertSame(
            ['crawler_submit', 'crawler_status', 'crawler_results', 'crawler_summary'],
            $result->capabilities,
        );
    }

    #[Test]
    public function rejectsMissingSiteIdentifierBeforeCallingApi(): void
    {
        $api = $this->createMock(AqgApiClient::class);
        $api->expects(self::never())->method('issueFreeToken');

        try {
            $this->service($api)->getValidToken('https://example.test/', '', '1.9.0');
            self::fail('Expected missing site identifier error.');
        } catch (FreePreviewException $exception) {
            self::assertSame('MISSING_SITE_IDENTIFIER', $exception->state);
            self::assertSame('missing_site_identifier', $exception->errorCode);
        }
    }

    #[Test]
    public function rejectsIncompleteFreeTokenCapabilities(): void
    {
        $api = $this->createMock(AqgApiClient::class);
        $api->method('issueFreeToken')->willReturn(AccessTokenResponseDto::fromArray([
            'plan' => 'free',
            'entitlement' => 'free_daily',
            'access_token' => 'jwt',
            'expires_in' => 3600,
            'features' => ['crawler'],
            'capabilities' => ['crawler_status'],
        ]));

        try {
            $this->service($api)->getValidToken('https://example.test/', 'main', '1.9.0');
            self::fail('Expected token contract error.');
        } catch (FreePreviewException $exception) {
            self::assertSame('TOKEN_CONTRACT_ERROR', $exception->state);
            self::assertSame('invalid_free_token_contract', $exception->errorCode);
        }
    }

    #[Test]
    public function refreshesLegacyCachedTokenWithoutFreeDailyContract(): void
    {
        $api = $this->createMock(AqgApiClient::class);
        $api->expects(self::once())->method('issueFreeToken')->willReturn($this->tokenResponse());

        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(new AccessTokenResult(
            accessToken: 'legacy-jwt',
            expiresIn: 3600,
            issuedAt: time(),
            plan: 'free',
            features: ['crawler'],
        ));
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('anonymous-id');

        $result = (new FreeAccessTokenService($api, $cache, $identity))->getValidToken(
            'https://example.test/',
            'main',
            '1.9.0',
        );

        self::assertSame('jwt', $result->accessToken);
        self::assertSame('free_daily', $result->entitlement);
    }

    private function service(AqgApiClient $api): FreeAccessTokenService
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('anonymous-id');

        return new FreeAccessTokenService($api, $cache, $identity);
    }

    private function tokenResponse(): AccessTokenResponseDto
    {
        return AccessTokenResponseDto::fromArray([
            'plan' => 'free',
            'entitlement' => 'free_daily',
            'access_token' => 'jwt',
            'expires_in' => 3600,
            'features' => ['crawler'],
            'capabilities' => [
                'crawler_submit',
                'crawler_status',
                'crawler_results',
                'crawler_summary',
            ],
        ]);
    }
}
