<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Core\Configuration\Exception\SettingsWriteException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Saving the licence must never end in an uncaught exception.
 *
 * Regression guard: on a hardened install `config/system/settings.php` is read-only, TYPO3 throws
 * SettingsWriteException from ExtensionConfiguration::set(), and AQG propagated it — the editor got
 * a raw "Oops, an error occurred!" 500 page instead of a bounded, translated error. Reproduced on
 * typo3test14 (sys_log #1346323822).
 */
final class SettingsConfigurationWriteFailureTest extends TestCase
{
    #[Test]
    public function unwritableConfigurationIsReportedInsteadOfThrowing(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('set')->willThrowException(
            new SettingsWriteException('config/system/settings.php is not writable.', 1346323822)
        );

        $persisted = $this->persist($extensionConfiguration, ['licenceKey' => 'aqg_live_example']);

        self::assertFalse($persisted, 'A failed write must be reported as not persisted.');
    }

    #[Test]
    public function anyWriteFailureIsContainedNotOnlyTheTypo3One(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('set')->willThrowException(new \RuntimeException('disk full'));

        self::assertFalse($this->persist($extensionConfiguration, ['licenceKey' => '']));
    }

    #[Test]
    public function successfulWriteReportsPersistedAndUpdatesRuntimeConfiguration(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('set');

        $previous = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] ?? null;

        try {
            $persisted = $this->persist($extensionConfiguration, ['licenceKey' => 'aqg_live_example']);

            self::assertTrue($persisted);
            self::assertSame(
                ['licenceKey' => 'aqg_live_example'],
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']
            );
        } finally {
            if ($previous === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] = $previous;
            }
        }
    }

    #[Test]
    public function failedWriteDoesNotLeakIntoTheRuntimeConfiguration(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('set')->willThrowException(
            new SettingsWriteException('not writable', 1346323822)
        );

        $previous = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] = ['licenceKey' => 'previous'];

        try {
            $this->persist($extensionConfiguration, ['licenceKey' => 'never-stored']);

            self::assertSame(
                ['licenceKey' => 'previous'],
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'],
                'A failed write must not pretend the new value is active.'
            );
        } finally {
            if ($previous === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] = $previous;
            }
        }
    }

    #[Test]
    public function aFailedWriteLeavesTheStoredConfigurationUntouched(): void
    {
        $stored = ['licenceKey' => 'aqg_live_existing', 'showProHints' => '1'];

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($stored);
        $extensionConfiguration->method('set')->willThrowException(
            new SettingsWriteException('config/system/settings.php is not writable.', 1346323822)
        );

        self::assertFalse($this->persist($extensionConfiguration, ['licenceKey' => 'aqg_live_new']));

        // The write threw, so nothing was persisted and the previously valid settings still stand.
        self::assertSame($stored, $extensionConfiguration->get('a11y_quality_gate'));
    }

    #[Test]
    public function theBoundedErrorIsTranslatedAndCarriesNoExceptionDetail(): void
    {
        $language = (string)file_get_contents(
            __DIR__ . '/../../../Resources/Private/Language/locallang.xlf'
        );

        self::assertSame(
            1,
            preg_match(
                '#<trans-unit id="settings\.flash\.configurationNotWritable">\s*<source>(.+?)</source>#s',
                $language,
                $matches
            ),
            'The write-failure message must be a translatable label.'
        );

        $message = $matches[1];
        self::assertStringContainsString('settings.php', $message, 'The message must name the cause.');
        foreach (['Exception', 'Stack trace', 'SettingsWriteException', '#1346323822'] as $leak) {
            self::assertStringNotContainsString($leak, $message, 'The message must not leak internals.');
        }
    }

    #[Test]
    public function saveActionReportsTheFailureInsteadOfClaimingSuccess(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../../../Classes/Controller/SettingsController.php'
        );

        self::assertStringContainsString('$configurationPersisted = $this->persistExtensionConfiguration(', $source);
        self::assertStringContainsString("settings.flash.configurationNotWritable", $source);
        self::assertStringContainsString('ContextualFeedbackSeverity::ERROR', $source);

        $language = (string)file_get_contents(
            __DIR__ . '/../../../Resources/Private/Language/locallang.xlf'
        );
        self::assertStringContainsString('settings.flash.configurationNotWritable', $language);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function persist(ExtensionConfiguration $extensionConfiguration, array $configuration): bool
    {
        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(SettingsController::class, $subject);

        (new ReflectionProperty(SettingsController::class, 'extensionConfiguration'))
            ->setValue($subject, $extensionConfiguration);

        $method = new ReflectionMethod(SettingsController::class, 'persistExtensionConfiguration');

        return (bool)$method->invoke($subject, $configuration);
    }
}
