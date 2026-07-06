<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;

final class AiRateLimiterTest extends TestCase
{
    #[Test]
    public function repositoryBackedLimitAllowsTenAndRejectsEleventh(): void
    {
        $calls = 0;
        $repository = $this->createMock(AiRateLimitRepositoryInterface::class);
        $repository->method('consume')->willReturnCallback(
            static function () use (&$calls): array {
                $calls++;
                return ['allowed' => $calls <= 10, 'retryAfter' => 37, 'count' => $calls];
            },
        );
        $backendUser = $this->createMock(BackendUserServiceInterface::class);
        $backendUser->method('getBackendUserUid')->willReturn(42);
        $subject = new AiRateLimiter($repository, $backendUser);

        for ($request = 1; $request <= 10; $request++) {
            $subject->assertAllowed('main', 1000);
        }

        try {
            $subject->assertAllowed('main', 1000);
            self::fail('The eleventh request must be rejected.');
        } catch (AiRateLimitException $exception) {
            self::assertSame(37, $exception->retryAfter);
        }
    }

    #[Test]
    public function bucketIsIndependentForUserAndSite(): void
    {
        $hashes = [];
        $repository = $this->createMock(AiRateLimitRepositoryInterface::class);
        $repository->method('consume')->willReturnCallback(
            static function (string $hash) use (&$hashes): array {
                $hashes[] = $hash;
                return ['allowed' => true, 'retryAfter' => 60, 'count' => 1];
            },
        );
        $backendUser = $this->createMock(BackendUserServiceInterface::class);
        $backendUser->method('getBackendUserUid')->willReturnOnConsecutiveCalls(42, 43, 43);
        $subject = new AiRateLimiter($repository, $backendUser);

        $subject->assertAllowed('main', 1000);
        $subject->assertAllowed('main', 1000);
        $subject->assertAllowed('second-site', 1000);

        self::assertCount(3, array_unique($hashes));
    }
}
