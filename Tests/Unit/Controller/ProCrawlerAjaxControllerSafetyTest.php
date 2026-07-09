<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use ReflectionClass;
use ReflectionMethod;

final class ProCrawlerAjaxControllerSafetyTest extends TestCase
{
    #[DataProvider('sensitiveLogKeyProvider')]
    #[Test]
    public function sensitiveRemoteCrawlerLogContextKeysAreMasked(string $key): void
    {
        $controller = (new ReflectionClass(ProCrawlerAjaxController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProCrawlerAjaxController::class, 'sanitizeLogContext');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            $key => 'secret-value',
            'nested' => [
                $key => 'nested-secret-value',
            ],
        ]);

        self::assertSame('***', $result[$key]);
        self::assertSame('***', $result['nested'][$key]);
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function sensitiveLogKeyProvider(): iterable
    {
        yield 'token' => ['token'];
        yield 'password' => ['password'];
        yield 'licenceKey' => ['licenceKey'];
        yield 'authorization' => ['authorization'];
        yield 'auth' => ['auth'];
        yield 'api_key' => ['api_key'];
        yield 'apikey' => ['apikey'];
        yield 'secret' => ['secret'];
        yield 'cookie' => ['cookie'];
        yield 'set-cookie' => ['set-cookie'];
        yield 'bearer' => ['bearer'];
        yield 'client_secret' => ['client_secret'];
        yield 'x-api-key' => ['x-api-key'];
    }

    #[Test]
    public function remoteCrawlerSubmitActionsUsePerSiteCoreLock(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../Classes/Controller/ProCrawlerAjaxController.php');
        self::assertIsString($source);

        self::assertStringContainsString('LockFactory::class', $source);
        self::assertStringContainsString('aqg_remote_scan:', $source);
        self::assertSame(2, substr_count($source, '$submitLock = $this->acquireRemoteSubmitLock($resolved->siteIdentifier);'));
        self::assertSame(2, substr_count($source, 'return $this->buildRemoteSubmitLockConflictResponse($resolved->siteIdentifier);'));
        self::assertStringContainsString('remote_scan_submit_in_progress', $source);
        self::assertStringContainsString('A remote scan submit is already in progress for this site.', $source);
        self::assertStringContainsString('], 409);', $source);
    }
}
