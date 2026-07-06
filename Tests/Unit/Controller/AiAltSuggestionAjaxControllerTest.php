<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiAltSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiImagePayloadException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Controller\AiAltSuggestionAjaxController;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use TYPO3\CMS\Core\Http\ServerRequest;

final class AiAltSuggestionAjaxControllerTest extends TestCase
{
    #[Test]
    public function validNeedsReviewResponseIsReturnedWithoutPartialSuggestion(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->with(12)->willReturn([
            'result' => new AiAltSuggestionResult(
                status: 'needs_review',
                suggestion: '',
                provider: 'openai',
                model: 'gpt-5.4-mini-2026-03-17',
                promptVersion: 'aqg_alt_text_v3',
            ),
            'version' => 'safe-version-token',
        ]);

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('needs_review', $payload['status']);
        self::assertSame('', $payload['suggestion']);
        self::assertSame('safe-version-token', $payload['expectedVersion']);
        self::assertArrayNotHasKey('promptVersion', $payload);
    }

    #[Test]
    public function rateLimitReturns429AndRetryAfter(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->with(12)->willThrowException(
            new AiRateLimitException('Too many AI requests.', 37),
        );

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('37', $response->getHeaderLine('Retry-After'));
        self::assertSame('rate_limited', $payload['code']);
    }

    #[Test]
    public function freeOrExpiredLicenceReturnsFeatureUnavailable(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiConfigurationException('Licence missing.', 1771002401),
        );

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('ai_unavailable', $payload['code']);
    }

    #[Test]
    public function unsupportedRuleDirectRequestReturnsInvalidInput(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new ImageRemediationValidationException('unsupported_finding', 1771001001),
        );

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_input', $payload['code']);
    }

    #[Test]
    public function unsupportedOrMissingImageReturnsGracefulError(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiImagePayloadException('Unsafe image.', 1771002201),
        );

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('image_unavailable', $payload['code']);
    }


    #[Test]
    public function invalidProviderOutputReturnsSafeProviderFailure(): void
    {
        $service = $this->createMock(AiAltSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiProviderException('Unsafe provider output.', 1771002604),
        );

        $response = (new AiAltSuggestionAjaxController($service))->suggestAction($this->request());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('provider_failure', $payload['code']);
        self::assertArrayNotHasKey('message', $payload);
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.test/', 'POST'))
            ->withParsedBody(['findingId' => 12]);
    }
}
