<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\InstallationIdentityCommand;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The installation identity in a real TYPO3: stored in sys_registry, kept through site and domain changes, and replaced
 * only by `a11y:installation:identity --new-installation` with the confirmed fingerprint.
 */
final class InstallationIdentityFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theIdentitySurvivesSiteAndDomainChangesAndANewProcess(): void
    {
        $id = $this->get(InstallationIdentityServiceInterface::class)->getOrCreateInstallationId();
        self::assertSame($id, $this->storedId(), 'kept in sys_registry, the database a backup or clone carries');

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1]);
        $siteWriter = $this->get(SiteWriter::class);
        foreach (['https://old.example.org/', 'https://new.example.org/'] as $base) {
            $siteWriter->write('main', ['rootPageId' => 1, 'base' => $base, 'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']]]);
            $this->get(SiteFinder::class)->getAllSites(false);
            self::assertSame($id, $this->get(InstallationIdentityServiceInterface::class)->getOrCreateInstallationId(), 'a domain change is the same installation');
        }

        // What a new PHP process, a deployment or a restore reads: the same row.
        self::assertSame($id, $this->storedId());
    }

    #[Test]
    public function theCommandResolvesFromTheContainerAndReplacesTheIdentityOnlyWhenConfirmed(): void
    {
        $registry = $this->get(CommandRegistry::class);
        $listed = $registry->filter('a11y');
        self::assertArrayHasKey('a11y:installation:identity', $listed);
        self::assertFalse($listed['a11y:installation:identity']['schedulable']);
        $command = $registry->get('a11y:installation:identity');
        self::assertInstanceOf(InstallationIdentityCommand::class, $command);

        $source = $this->get(InstallationIdentityServiceInterface::class)->getOrCreateInstallationId();
        $fingerprint = InstallationIdentityService::fingerprint($source);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString($fingerprint, $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--new-installation' => true]));
        self::assertSame($source, $this->storedId());

        self::assertSame(Command::SUCCESS, $tester->execute(['--new-installation' => true, '--confirm' => $fingerprint]));
        $replaced = $this->storedId();
        self::assertNotSame($source, $replaced);
        self::assertSame($replaced, $this->get(InstallationIdentityServiceInterface::class)->getOrCreateInstallationId());
        self::assertStringNotContainsString($source, (string)json_encode($this->registryRows()), 'no copy of the source identity is kept');
    }

    private function storedId(): string
    {
        $row = $this->get(ConnectionPool::class)->getConnectionForTable('sys_registry')->select(
            ['entry_value'],
            'sys_registry',
            ['entry_namespace' => 'a11y_quality_gate', 'entry_key' => 'anonymous_installation_id'],
        )->fetchAssociative();

        return is_array($row) ? (string)unserialize((string)$row['entry_value'], ['allowed_classes' => false]) : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registryRows(): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_registry')
            ->select(['entry_key', 'entry_value'], 'sys_registry', ['entry_namespace' => 'a11y_quality_gate'])
            ->fetchAllAssociative();
    }
}
