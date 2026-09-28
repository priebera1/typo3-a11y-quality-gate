<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\MonitorCommand;
use Priebera\A11yQualityGate\Command\ScanCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\AttributeAutoconfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\DependencyInjection\ConsoleCommandPass;

/**
 * The AQG console commands reach TYPO3 exactly as `typo3 list` and the Scheduler read them.
 *
 * Runs the installed core's own `#[AsCommand]` autoconfiguration (Configuration/Services.php) and
 * ConsoleCommandPass over the extension's Services.yaml, so it checks TYPO3 13 or 14, whichever core is
 * installed. A command tagged in Services.yaml and also carrying #[AsCommand] gets two console.command tags;
 * the pass registers the second as an alias of the command itself, which `typo3 list` hides and which the
 * TYPO3 14 Scheduler drops from its task types — how `a11y:monitor` went missing on TYPO3 14.
 */
final class ConsoleCommandRegistrationTest extends TestCase
{
    #[Test]
    public function eachCommandIsRegisteredOnceAndNeverAsAnAliasOfItself(): void
    {
        $registrations = $this->commandRegistrations();

        foreach (['a11y:scan', 'a11y:monitor'] as $command) {
            self::assertCount(1, $registrations[$command] ?? [], $command . ' must be registered exactly once.');
            self::assertNull($registrations[$command][0]['aliasFor'], $command . ' must not be an alias (hidden from `typo3 list` and the TYPO3 14 Scheduler).');
            self::assertFalse($registrations[$command][0]['hidden'], $command . ' must be listed.');
        }
    }

    #[Test]
    public function monitoringIsScheduledByItsOwnTaskAndTheScanCommandStaysSchedulable(): void
    {
        $registrations = $this->commandRegistrations();

        self::assertFalse(
            $registrations['a11y:monitor'][0]['schedulable'],
            'The Scheduler offers monitoring as the "Accessibility monitoring" task with site and language selectors, not as a raw console command.'
        );
        self::assertTrue(
            $registrations['a11y:scan'][0]['schedulable'],
            '"Execute console commands" has offered a11y:scan on TYPO3 13 since 1.0.0; existing tasks must keep working.'
        );
    }

    #[Test]
    public function commandClassesAreConfiguredInServicesYamlOnly(): void
    {
        foreach ([ScanCommand::class, MonitorCommand::class] as $className) {
            self::assertSame(
                [],
                (new \ReflectionClass($className))->getAttributes(AsCommand::class),
                $className . ' is tagged in Services.yaml; #[AsCommand] would register it a second time.'
            );
        }
    }

    /**
     * @return array<string, list<array{hidden:bool,schedulable:bool,aliasFor:?string}>>
     */
    private function commandRegistrations(): array
    {
        $builder = new ContainerBuilder();
        $builder->register(CommandRegistry::class, CommandRegistry::class);

        $coreConfiguration = dirname((string)(new \ReflectionClass(CommandRegistry::class))->getFileName(), 3) . '/Configuration';
        (new PhpFileLoader($builder, new FileLocator($coreConfiguration)))->load('Services.php');
        (new YamlFileLoader($builder, new FileLocator(dirname(__DIR__, 3) . '/Configuration')))->load('Services.yaml');

        // The part of the container compilation that turns attributes and tags into command registrations, in
        // the compiler's order: class names from service ids, attribute autoconfiguration, then the TYPO3 pass.
        (new ResolveClassPass())->process($builder);
        (new AttributeAutoconfigurationPass())->process($builder);
        (new ResolveInstanceofConditionalsPass())->process($builder);
        (new ConsoleCommandPass('console.command'))->process($builder);

        $registrations = [];
        foreach ($builder->getDefinition(CommandRegistry::class)->getMethodCalls() as [$method, $arguments]) {
            if ($method !== 'addLazyCommand' || !str_starts_with((string)$arguments[0], 'a11y:')) {
                continue;
            }
            $registrations[(string)$arguments[0]][] = [
                'hidden' => (bool)$arguments[3],
                'schedulable' => (bool)$arguments[4],
                'aliasFor' => $arguments[5],
            ];
        }

        return $registrations;
    }
}
