<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Controller\EditorAiAjaxController;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class EditorAiAjaxControllerTest extends TestCase
{
    #[Test]
    public function aSuggestionIsReturnedAndTheRequestOnlyCarriesRecordFieldContentAndFinding(): void
    {
        $service = $this->createMock(EditorAiRemediationService::class);
        $service->expects(self::once())->method('fix')->with([
            'table' => 'tt_content',
            'uid' => 12,
            'field' => 'bodytext',
            'html' => '<p>x</p>',
            'ruleId' => 'rte.non_descriptive_link',
            'contextPath' => 'p > a',
            'occurrence' => 1,
        ])->willReturn(['status' => 'suggestion', 'value' => 'Prices']);

        $response = $this->controller($service)->fixAction($this->request(['recordUid' => 12, 'fieldName' => 'bodytext', 'html' => '<p>x</p>', 'ruleId' => 'rte.non_descriptive_link', 'contextPath' => 'p > a', 'occurrence' => 1, 'pid' => 99]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'status' => 'suggestion', 'value' => 'Prices'], json_decode((string)$response->getBody(), true));
    }

    #[Test]
    #[DataProvider('failures')]
    public function failuresBecomeStableSafeCodes(\Throwable $failure, int $status, string $code): void
    {
        $service = $this->createMock(EditorAiRemediationService::class);
        $service->method('fix')->willThrowException($failure);

        $response = $this->controller($service)->fixAction($this->request(['recordUid' => 1, 'fieldName' => 'bodytext']));
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $body['code']);
        self::assertFalse($body['success']);
        self::assertStringNotContainsString('Anthropic', (string)$response->getBody());
    }

    /** @return iterable<string,array{\Throwable,int,string}> */
    public static function failures(): iterable
    {
        yield 'stale' => [new EditorAiException('stale', 'x', 409), 409, 'stale'];
        yield 'permission' => [new EditorAiException('permission_denied', 'x', 403), 403, 'permission_denied'];
        yield 'rate limit' => [new AiRateLimitException('x', 30, 1771002101), 429, 'rate_limited'];
        yield 'images off' => [new AiConfigurationException('x', 1771002403), 422, 'privacy_images'];
        yield 'page titles off' => [new AiConfigurationException('x', 1771002405), 422, 'privacy_page_title'];
        yield 'licence' => [new AiConfigurationException('x', 1771002404), 403, 'no_licence'];
        yield 'revalidation' => [new AiConfigurationException('x', 1771002005), 422, 'paused'];
        // Readiness and budget refusals, also when raised by the locked budget check inside AiUsageRecorder::track().
        yield 'model not verified' => [new AiConfigurationException('x', 1771002006), 422, 'paused'];
        yield 'acknowledgement missing' => [new AiConfigurationException('x', 1771002007), 422, 'paused'];
        yield 'budget reached' => [new AiConfigurationException('x', 1771002008), 422, 'budget_reached'];
        yield 'budget not enforceable' => [new AiConfigurationException('x', 1771002009), 422, 'paused'];
        yield 'no provider row' => [new AiConfigurationException('x', 1771002001), 422, 'not_configured'];
        yield 'refusal' => [new AiProviderException('Anthropic declined to answer this request.', 1771002313), 502, 'refused'];
        yield 'schema mismatch' => [new AiProviderException('Anthropic returned an answer outside the AQG contract.', 1771002316), 502, 'invalid_response'];
        yield 'timeout' => [new AiProviderException('Anthropic transport failed.', 1771002302), 502, 'provider_timeout'];
        yield 'billing' => [new AiProviderException('Anthropic reports a billing problem.', 1771002323), 502, 'provider_credit'];
        yield 'overloaded' => [new AiProviderException('Anthropic could not complete the request.', 1771002324), 502, 'provider_unavailable'];
    }

    #[Test]
    public function theRetryDelayOfTheRateLimitIsReturned(): void
    {
        $service = $this->createMock(EditorAiRemediationService::class);
        $service->method('explain')->willThrowException(new AiRateLimitException('x', 42, 1771002101));

        $body = json_decode((string)$this->controller($service)->explainAction($this->request([]))->getBody(), true);

        self::assertSame(42, $body['retryAfter']);
    }

    #[Test]
    public function anExpiredSessionAndBrokenJsonAreRejectedWithoutWork(): void
    {
        $service = $this->createMock(EditorAiRemediationService::class);
        $service->expects(self::never())->method('fix');

        self::assertSame(401, $this->controller($service, false)->fixAction($this->request([]))->getStatusCode());
        $broken = $this->createMock(ServerRequestInterface::class);
        $broken->method('getMethod')->willReturn('POST');
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{nope');
        $broken->method('getBody')->willReturn($stream);
        self::assertSame(400, $this->controller($service)->fixAction($broken)->getStatusCode());
    }

    private function controller(EditorAiRemediationService $service, bool $loggedIn = true): EditorAiAjaxController
    {
        $users = $this->createMock(BackendUserService::class);
        $users->method('isLoggedIn')->willReturn($loggedIn);

        return new EditorAiAjaxController($service, $this->createMock(EditorAiAccessService::class), $users);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
