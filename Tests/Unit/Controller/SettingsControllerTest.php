<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use ReflectionClass;
use ReflectionMethod;

/**
 * The saved licence key never reaches the page: administrators see a masked form that identifies it, and the
 * form's empty key field keeps, replaces or (explicitly) removes it.
 */
final class SettingsControllerTest extends TestCase
{
    private const KEY = 'aqg_live_0123456789abcdef0123456789ab7f3a';

    #[Test]
    public function adminSeesAMaskedKeyThatIdentifiesButDoesNotDiscloseIt(): void
    {
        $viewData = $this->invoke('buildLicenceViewData', '  ' . self::KEY . '  ', true);

        self::assertTrue($viewData['hasLicenceKey']);
        self::assertSame('aqg_live_••••••••7f3a', $viewData['maskedLicenceKey']);
        self::assertSame('7f3a', $viewData['licenceKeyEnding']);
        self::assertSame(substr(hash('sha256', self::KEY), 0, 16), $viewData['licenceKeyFingerprint']);
        self::assertStringNotContainsString('0123456789abcdef', implode('|', array_map('strval', $viewData)));
        self::assertArrayNotHasKey('licenceKey', $viewData, 'The key itself is not handed to the view.');
    }

    #[Test]
    public function aShortKeyShowsNoCharactersOfIt(): void
    {
        $viewData = $this->invoke('buildLicenceViewData', 'aqg_test_x', true);

        self::assertSame('aqg_test_••••••••', $viewData['maskedLicenceKey']);
        self::assertSame('', $viewData['licenceKeyEnding']);
    }

    #[Test]
    public function nonAdminReceivesOnlyThePresenceFlag(): void
    {
        $viewData = $this->invoke('buildLicenceViewData', self::KEY, false);

        self::assertTrue($viewData['hasLicenceKey']);
        self::assertSame('', $viewData['maskedLicenceKey']);
        self::assertSame('', $viewData['licenceKeyEnding']);
        self::assertSame('', $viewData['licenceKeyFingerprint']);
    }

    #[Test]
    public function emptyStoredLicenceKeyHasNoPresenceFlag(): void
    {
        $viewData = $this->invoke('buildLicenceViewData', '   ', true);

        self::assertFalse($viewData['hasLicenceKey']);
        self::assertSame('', $viewData['maskedLicenceKey']);
    }

    #[Test]
    public function anEmptyKeyFieldKeepsTheSavedKey(): void
    {
        self::assertSame(self::KEY, $this->invoke('resolveSubmittedLicenceKey', ['licenceKey' => '  '], self::KEY));
        self::assertSame(self::KEY, $this->invoke('resolveSubmittedLicenceKey', [], self::KEY));
    }

    #[Test]
    public function aTypedKeyReplacesTheSavedKey(): void
    {
        self::assertSame('aqg_live_new', $this->invoke('resolveSubmittedLicenceKey', ['licenceKey' => ' aqg_live_new '], self::KEY));
        self::assertSame('aqg_live_new', $this->invoke('resolveSubmittedLicenceKey', ['licenceKey' => 'aqg_live_new'], ''));
    }

    #[Test]
    public function removingTheKeyIsAnExplicitChoice(): void
    {
        self::assertSame('', $this->invoke('resolveSubmittedLicenceKey', ['licenceKeyRemove' => '1'], self::KEY));
        self::assertSame('', $this->invoke('resolveSubmittedLicenceKey', ['licenceKey' => '', 'licenceKeyRemove' => '1'], self::KEY));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SettingsController::class, $method))->invoke($subject, ...$arguments);
    }
}
