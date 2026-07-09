<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiLinkTextSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiLinkTextSuggestionException;
use Priebera\A11yQualityGate\Controller\AiLinkTextSuggestionAjaxController;
use TYPO3\CMS\Core\Http\ServerRequest;

final class AiLinkTextSuggestionAjaxControllerTest extends TestCase
{
    #[Test]
    public function missingFindingIdReturnsInvalidInput(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->expects(self::never())->method('suggest');
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody([]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_input', $payload['code']);
    }

    #[Test]
    public function supportedFindingReturnsReviewOnlySuggestion(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->expects(self::once())->method('suggest')->with(12)->willReturn(
            new AiLinkTextSuggestionResult(
                status: 'suggestion',
                suggestedLinkText: 'Download the accessibility checklist PDF',
                reason: 'The label describes the PDF destination.',
                needsReview: true,
                provider: 'openai',
                model: 'gpt-5.4-mini',
                promptVersion: 'aqg_link_text_v1',
            )
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('Download the accessibility checklist PDF', $payload['suggestedLinkText']);
        self::assertTrue($payload['reviewOnly']);
    }


    #[Test]
    public function emptyLinkFindingReturnsReviewOnlySuggestion(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->expects(self::once())->method('suggest')->with(44)->willReturn(
            new AiLinkTextSuggestionResult(
                status: 'suggestion',
                suggestedLinkText: 'Contact us',
                reason: 'The href points to the contact page.',
                needsReview: true,
                provider: 'openai',
                model: 'gpt-5.4-mini',
                promptVersion: 'aqg_link_text_v1',
            )
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 44]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('Contact us', $payload['suggestedLinkText']);
        self::assertSame('suggestion', $payload['status']);
        self::assertTrue($payload['reviewOnly']);
    }

    #[Test]
    public function noSuggestionStatusReturnsReasonWithoutWriteAction(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->expects(self::once())->method('suggest')->with(45)->willReturn(
            new AiLinkTextSuggestionResult(
                status: 'unsupported_context',
                suggestedLinkText: '',
                reason: 'The link target cannot be inferred safely.',
                needsReview: true,
                provider: 'openai',
                model: 'gpt-5.4-mini',
                promptVersion: 'aqg_link_text_v1',
            )
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 45]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('unsupported_context', $payload['status']);
        self::assertSame('', $payload['suggestedLinkText']);
        self::assertSame('The link target cannot be inferred safely.', $payload['reason']);
        self::assertArrayNotHasKey('applyUrl', $payload);
        self::assertTrue($payload['reviewOnly']);
    }

    #[Test]
    public function unsupportedContextReturnsSafeReadableErrorWithoutProviderDetails(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiLinkTextSuggestionException('unsupported_context')
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('unsupported_context', $payload['code']);
    }

    #[Test]
    public function permissionDeniedReturnsForbidden(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiLinkTextSuggestionException('permission_denied')
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_denied', $payload['code']);
    }

    #[Test]
    public function disabledFeatureReturnsSafeError(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new AiConfigurationException('disabled', 1771002841)
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 12]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ai_link_text_disabled', $payload['code']);
    }
    #[Test]
    public function providerErrorReturnsJsonWithoutHttp502(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(
            new \Priebera\A11yQualityGate\Ai\Exception\AiProviderException('unsafe provider response')
        );
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 58648]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('provider_error', $payload['code']);
        self::assertSame('', $payload['suggestedLinkText']);
        self::assertTrue($payload['reviewOnly']);
    }

    #[Test]
    public function unexpectedRuntimeErrorReturnsUnsupportedContextJsonWithoutHttp502(): void
    {
        $service = $this->createMock(AiLinkTextSuggestionServiceInterface::class);
        $service->method('suggest')->willThrowException(new \RuntimeException('runtime-only resolver failure'));
        $controller = new AiLinkTextSuggestionAjaxController($service);

        $response = $controller->suggestAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 58648]));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('unsupported_context', $payload['code']);
        self::assertSame('unsupported_context', $payload['status']);
        self::assertSame('', $payload['suggestedLinkText']);
        self::assertStringContainsString('could not safely identify', $payload['reason']);
    }

}
