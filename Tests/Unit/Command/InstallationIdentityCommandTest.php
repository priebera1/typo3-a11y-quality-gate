<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\InstallationIdentityCommand;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Registry;

/**
 * `a11y:installation:identity` gives a database clone that became another installation an identity of its own — only
 * when the operator names the current fingerprint of that very installation — and otherwise changes nothing.
 */
final class InstallationIdentityCommandTest extends TestCase
{
    private const SOURCE_ID = '018f5f5d-8d31-4f4c-9bce-2e1c6a91c444';

    /** @var array<string, mixed> */
    private array $rows = [];
    private int $cacheFlushes = 0;
    /** @var list<array{string, array<string, mixed>}> */
    private array $warnings = [];

    #[Test]
    public function withoutOptionsItShowsTheFingerprintAndChangesNothing(): void
    {
        $this->rows = ['a11y_quality_gate:anonymous_installation_id' => self::SOURCE_ID];
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString(InstallationIdentityService::fingerprint(self::SOURCE_ID), $display);
        self::assertStringNotContainsString(self::SOURCE_ID, $display, 'the id is a credential and is never printed');
        self::assertSame(self::SOURCE_ID, $this->rows['a11y_quality_gate:anonymous_installation_id']);
        self::assertSame(0, $this->cacheFlushes);
    }

    #[Test]
    public function aNewIdentityIsRefusedWithoutTheCurrentFingerprint(): void
    {
        $this->rows = ['a11y_quality_gate:anonymous_installation_id' => self::SOURCE_ID];

        foreach ([[], ['--confirm' => '0000-0000-0000-0000']] as $confirmation) {
            $tester = $this->tester();
            self::assertSame(Command::FAILURE, $tester->execute(['--new-installation' => true] + $confirmation));
            self::assertStringContainsString('Nothing changed', $tester->getDisplay());
            self::assertStringContainsString('--confirm=' . InstallationIdentityService::fingerprint(self::SOURCE_ID), $tester->getDisplay());
        }

        self::assertSame(self::SOURCE_ID, $this->rows['a11y_quality_gate:anonymous_installation_id']);
        self::assertArrayNotHasKey('a11y_quality_gate:installation_id_reassignment', $this->rows);
        self::assertSame(0, $this->cacheFlushes);
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function theConfirmedCopyGetsANewIdentityAndForgetsTheSourceTokens(): void
    {
        $this->rows = ['a11y_quality_gate:anonymous_installation_id' => self::SOURCE_ID];
        $fingerprint = InstallationIdentityService::fingerprint(self::SOURCE_ID);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--new-installation' => true, '--confirm' => strtoupper($fingerprint)]));

        $newId = (string)$this->rows['a11y_quality_gate:anonymous_installation_id'];
        self::assertNotSame(self::SOURCE_ID, $newId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $newId);
        self::assertSame(1, $this->cacheFlushes, 'licence answers and scanner tokens of the source identity are dropped');
        self::assertCount(1, $this->warnings);
        self::assertSame($fingerprint, $this->warnings[0][1]['previousFingerprint']);

        $display = $tester->getDisplay();
        self::assertStringContainsString(InstallationIdentityService::fingerprint($newId), $display);
        self::assertStringNotContainsString(self::SOURCE_ID, $display);
        self::assertStringNotContainsString($newId, $display);
        self::assertStringNotContainsString(self::SOURCE_ID, serialize($this->rows), 'the copy keeps no credential of its source');

        // Running the same command line again (a copied script, a second run) changes nothing: the fingerprint moved on.
        $again = $this->tester();
        self::assertSame(Command::FAILURE, $again->execute(['--new-installation' => true, '--confirm' => $fingerprint]));
        self::assertSame($newId, $this->rows['a11y_quality_gate:anonymous_installation_id']);
        self::assertSame(1, $this->cacheFlushes);

        // The identity overview records when it happened and what came before.
        $overview = $this->tester();
        $overview->execute([]);
        self::assertStringContainsString($fingerprint, $overview->getDisplay());
    }

    #[Test]
    public function anInstallationWithoutAnIdentityHasNothingToReplace(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--new-installation' => true, '--confirm' => 'anything']));
        self::assertSame([], $this->rows);
        self::assertSame(0, $this->cacheFlushes);
    }

    private function tester(): CommandTester
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->rows[$namespace . ':' . $key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->rows[$namespace . ':' . $key] = $value;
        });

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('flush')->willReturnCallback(function (): void {
            $this->cacheFlushes++;
        });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('a11y_quality_gate_pro')->willReturn($cache);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->warnings[] = [(string)$message, $context];
        });

        return new CommandTester(new InstallationIdentityCommand(
            new InstallationIdentityService($registry),
            new ProCacheManager($cacheManager),
            $logger,
        ));
    }
}
