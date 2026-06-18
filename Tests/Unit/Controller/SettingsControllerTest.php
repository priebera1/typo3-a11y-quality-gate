<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use ReflectionClass;
use ReflectionMethod;

final class SettingsControllerTest extends TestCase
{
    #[Test]
    public function adminReceivesStoredLicenceKeyAndPresenceFlag(): void
    {
        $viewData = $this->buildLicenceViewData('  aqg_live_secret  ', true);

        self::assertSame('aqg_live_secret', $viewData['licenceKey']);
        self::assertTrue($viewData['hasLicenceKey']);
    }

    #[Test]
    public function nonAdminNeverReceivesStoredLicenceKey(): void
    {
        $viewData = $this->buildLicenceViewData('aqg_live_secret', false);

        self::assertSame('', $viewData['licenceKey']);
        self::assertTrue($viewData['hasLicenceKey']);
    }

    #[Test]
    public function emptyStoredLicenceKeyHasNoPresenceFlag(): void
    {
        $viewData = $this->buildLicenceViewData('   ', false);

        self::assertSame('', $viewData['licenceKey']);
        self::assertFalse($viewData['hasLicenceKey']);
    }

    /**
     * @return array{licenceKey:string,hasLicenceKey:bool}
     */
    private function buildLicenceViewData(string $storedLicenceKey, bool $isAdmin): array
    {
        $reflection = new ReflectionClass(SettingsController::class);
        $subject = $reflection->newInstanceWithoutConstructor();
        self::assertInstanceOf(SettingsController::class, $subject);

        $method = new ReflectionMethod(SettingsController::class, 'buildLicenceViewData');
        $result = $method->invoke($subject, $storedLicenceKey, $isAdmin);
        self::assertIsArray($result);

        return $result;
    }
}
