<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\AiRateLimitRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;

final class AiRateLimitRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function fixedWindowLimitIsSharedAndExpires(): void
    {
        $subject = $this->get(AiRateLimitRepository::class);
        for ($request = 1; $request <= 10; $request++) {
            self::assertTrue($subject->consume('site-user-a', 10, 60, 1000)['allowed']);
        }
        $rejected = $subject->consume('site-user-a', 10, 60, 1000);
        self::assertFalse($rejected['allowed']);
        self::assertSame(60, $rejected['retryAfter']);

        self::assertTrue($subject->consume('site-user-b', 10, 60, 1000)['allowed']);
        self::assertTrue($subject->consume('other-site-user-a', 10, 60, 1000)['allowed']);
        self::assertTrue($subject->consume('site-user-a', 10, 60, 1060)['allowed']);
    }
}
