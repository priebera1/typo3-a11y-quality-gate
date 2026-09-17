<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractApiController;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The Free Remote Preview card and notification show the title and message of this payload; its state decides
 * whether the card offers Retry.
 */
final class FreePreviewErrorResponseTest extends TestCase
{
    #[Test]
    public function aThrottledFreeScanIsAnnouncedAsTooManyRequestsAndStaysRetryable(): void
    {
        $payload = $this->payload(new FreePreviewException(
            'AQG paused Free Remote Preview requests from this site for now. Try again later.',
            'API_UNAVAILABLE',
            'free_preview_rate_limited',
            429,
        ), $status);

        self::assertSame(429, $status);
        self::assertSame('free_preview_rate_limited', $payload['code']);
        self::assertSame('API_UNAVAILABLE', $payload['state']);
        self::assertSame('Too many free scan requests', $payload['title']);
        self::assertSame('AQG paused Free Remote Preview requests from this site for now. Try again later.', $payload['message']);
    }

    #[Test]
    public function otherFreeFailuresKeepTheirOwnTitles(): void
    {
        self::assertSame(
            'Free Remote Preview API contract rejected',
            $this->payload(new FreePreviewException('Rejected.', 'API_CONTRACT_ERROR', 'new_client_error', 400))['title'],
        );
        self::assertSame(
            'Free scan limit reached',
            $this->payload(new FreePreviewException('Limit.', 'FREE_LIMIT_REACHED', 'free_daily_limit_reached', 429))['title'],
        );
        self::assertSame(
            'Free Remote Preview unavailable',
            $this->payload(new FreePreviewException('Down.', 'API_UNAVAILABLE', 'free_preview_unavailable', 503))['title'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FreePreviewException $exception, ?int &$status = null): array
    {
        $controller = (new \ReflectionClass(ProCrawlerAjaxController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function (): void {
            $this->responseFactory = new ResponseFactory();
            $this->streamFactory = new StreamFactory();
        }, $controller, AbstractApiController::class)();

        $response = (new \ReflectionMethod(ProCrawlerAjaxController::class, 'buildFreePreviewExceptionResponse'))
            ->invoke($controller, $exception);
        $status = $response->getStatusCode();

        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
