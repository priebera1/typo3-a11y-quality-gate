<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiIframeTitleSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiIframeTitleSuggestionException;
use Priebera\A11yQualityGate\Controller\AiIframeTitleSuggestionAjaxController;
use TYPO3\CMS\Core\Http\ServerRequest;

final class AiIframeTitleSuggestionAjaxControllerTest extends TestCase
{
    #[Test]
    public function missingFindingIdReturnsInvalidInput(): void
    {
        $service = $this->createMock(AiIframeTitleSuggestionServiceInterface::class);
        $service->expects(self::never())->method('suggest');
        $controller = new AiIframeTitleSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody([]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_input', $payload['code']);
    }

    #[Test]
    public function supportedFindingReturnsReviewOnlySuggestion(): void
    {
        $service = $this->createMock(AiIframeTitleSuggestionServiceInterface::class);
        $service->expects(self::once())->method('suggest')->with(12)->willReturn(
            new AiIframeTitleSuggestionResult(
                status: 'suggestion',
                suggestedIframeTitle: 'Product introduction video',
                reason: 'The source indicates an embedded product video.',
                needsReview: true,
                provider: 'openai',
                model: 'gpt-5.4-mini',
                promptVersion: 'aqg_iframe_title_v1',
            )
        );
        $controller = new AiIframeTitleSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('Product introduction video', $payload['suggestedIframeTitle']);
        self::assertTrue($payload['reviewOnly']);
    }

    #[Test]
    public function needsReviewWithoutSuggestionReturnsSafeNoSuggestionState(): void
    {
        $service = $this->createMock(AiIframeTitleSuggestionServiceInterface::class);
        $service->expects(self::once())->method('suggest')->with(12)->willReturn(
            new AiIframeTitleSuggestionResult(
                status: 'needs_review',
                suggestedIframeTitle: '',
                reason: 'The iframe source is a generic placeholder URL and no safe description can be inferred.',
                needsReview: true,
                provider: 'openai',
                model: 'gpt-5.4-mini',
                promptVersion: 'aqg_iframe_title_v1',
            )
        );
        $controller = new AiIframeTitleSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('needs_review', $payload['code']);
        self::assertSame('needs_review', $payload['status']);
        self::assertSame('', $payload['suggestedIframeTitle']);
        self::assertSame('The iframe source is a generic placeholder URL and no safe description can be inferred.', $payload['reason']);
        self::assertTrue($payload['reviewOnly']);
    }

    #[Test]
    public function unsupportedContextReturnsSafeReadableErrorWithoutProviderDetails(): void
    {
        $service = $this->createMock(AiIframeTitleSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiIframeTitleSuggestionException('unsupported_context')
        );
        $controller = new AiIframeTitleSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('unsupported_context', $payload['code']);
    }

    #[Test]
    public function disabledFeatureReturnsSafeError(): void
    {
        $service = $this->createMock(AiIframeTitleSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiConfigurationException('disabled', 1771002941)
        );
        $controller = new AiIframeTitleSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ai_iframe_title_disabled', $payload['code']);
    }
}
