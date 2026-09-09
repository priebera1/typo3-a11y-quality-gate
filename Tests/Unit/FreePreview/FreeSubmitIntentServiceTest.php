<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreeSubmitIntentService;

final class FreeSubmitIntentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    #[Test]
    public function sameIntentCreatesStableKeyAndNewIntentCreatesNewKey(): void
    {
        $backendUser = $this->createMock(BackendUserServiceInterface::class);
        $backendUser->method('getBackendUserUid')->willReturn(42);
        $service = new FreeSubmitIntentService($backendUser);

        $firstIntent = $service->create('main', 42);
        $firstKey = $service->buildIdempotencyKey($firstIntent, 'main', 42);

        self::assertSame($firstKey, $service->buildIdempotencyKey($firstIntent, 'main', 42));
        self::assertNotSame($firstKey, $service->buildIdempotencyKey($service->create('main', 42), 'main', 42));
        self::assertStringStartsWith('aqg-free-', $firstKey);
    }

    #[Test]
    public function intentCannotBeReusedForAnotherSite(): void
    {
        $backendUser = $this->createMock(BackendUserServiceInterface::class);
        $backendUser->method('getBackendUserUid')->willReturn(42);
        $service = new FreeSubmitIntentService($backendUser);

        $this->expectException(\InvalidArgumentException::class);
        $service->buildIdempotencyKey($service->create('main', 42), 'other', 42);
    }

    #[Test]
    public function intentCannotBeReusedForAnotherPage(): void
    {
        $backendUser = $this->createMock(BackendUserServiceInterface::class);
        $backendUser->method('getBackendUserUid')->willReturn(42);
        $service = new FreeSubmitIntentService($backendUser);

        $this->expectException(\InvalidArgumentException::class);
        $service->buildIdempotencyKey($service->create('main', 42), 'main', 43);
    }
}
