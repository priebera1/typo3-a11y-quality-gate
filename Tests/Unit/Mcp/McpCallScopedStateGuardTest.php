<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Mcp\McpCallScopedState;
use Priebera\A11yQualityGate\Tests\Support\McpCallStateScanner;
use Symfony\Component\Yaml\Yaml;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * R7 (release-gate finding 1): the long-running MCP connector reuses every DI singleton for all calls of all users. A
 * service an MCP call can reach that writes instance state outside its constructor must be reset before and after every
 * call (McpCallScopedState, called by McpUserContext::runAs()) — or be listed below as deliberately process-wide, with
 * the reason. A new memo fails this test until one of the two is decided in review.
 */
final class McpCallScopedStateGuardTest extends UnitTestCase
{
    /** Loading every reachable class may register framework singletons (LogManager on TYPO3 14). */
    protected bool $resetSingletonInstances = true;

    private const ROOTS = [
        \Priebera\A11yQualityGate\Mcp\McpCallRunner::class,
        \Priebera\A11yQualityGate\Mcp\McpToolExecutor::class,
        \Priebera\A11yQualityGate\Mcp\Remediation\McpSuggestionTools::class,
        \Priebera\A11yQualityGate\Command\McpConnectorCommand::class,
        \Priebera\A11yQualityGate\Command\McpConnectorWorkerCommand::class,
    ];

    /**
     * Process-wide on purpose: never user, permission, budget or request dependent. property => reason.
     */
    private const PROCESS_WIDE = [
        \Priebera\A11yQualityGate\Command\McpConnectorCommand::class => ['stopRequested' => 'signal flag of the process'],
        \Priebera\A11yQualityGate\Command\McpConnectorWorkerCommand::class => ['stopRequested' => 'signal flag of the process'],
        \Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient::class => ['connectorInstanceId' => 'identity of the connector process (lease)'],
        // Reached through `new` in McpConnectorWorkerPoolFactory (G-15 scanner): the connector parent's supervision of its
        // worker processes; it never sees a call's user, arguments or result, only the call id it dispatched.
        \Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool::class => [
            'workers' => 'worker process handles of the connector',
            'nextWorkerId' => 'worker counter of the connector',
            'startFailures' => 'worker start health of the connector',
            'disabled' => 'worker pool health of the connector',
            'disabledReason' => 'worker pool health of the connector',
        ],
        // Must survive runAs(): added inside the call, run after the answer; McpCallRunner clears it before every call.
        \Priebera\A11yQualityGate\Mcp\McpDeferredWork::class => ['jobs' => 'cleared by McpCallRunner per call', 'callContext' => 'set by McpCallRunner::run() per call (begin), cleared before every call'],
        \Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository::class => ['tableColumnCache' => 'database schema'],
        \Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository::class => ['remoteScanPageColumns' => 'database schema'],
        \Priebera\A11yQualityGate\Domain\Repository\ScanRepository::class => ['scanColumns' => 'database schema'],
        \Priebera\A11yQualityGate\Remediation\FileReferenceSchemaService::class => [
            'hasDecorativeColumn' => 'database schema',
            'alternativeStorageLimit' => 'database schema',
            'tableColumns' => 'database schema',
        ],
        \Priebera\A11yQualityGate\Service\BackendLanguageService::class => ['explicitLanguageCatalogues' => 'language files, keyed by language'],
        // Set and restored around one evaluation (run() … finally).
        \Priebera\A11yQualityGate\Workspace\PublishPreviewContext::class => ['set' => 'restored by run() in finally'],
    ];

    private function scanner(): McpCallStateScanner
    {
        return new McpCallStateScanner(dirname(__DIR__, 3) . '/Classes');
    }

    #[Test]
    public function theScannerFollowsRuntimeReferencesAndCountsStaticState(): void
    {
        $scanner = $this->scanner();
        $reachable = $scanner->reachable(self::ROOTS);
        // G-15: only reachable through `new` (McpConnectorWorkerPoolFactory), never a constructor parameter.
        self::assertContains(\Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool::class, $reachable);
        // A static property written outside the constructor is state too.
        self::assertSame(['until'], $scanner->mutableState(\Priebera\A11yQualityGate\Ai\Provider\AiRequestTimeBudget::class));
    }

    #[Test]
    public function everyMcpReachableServiceWithStateIsResetPerCall(): void
    {
        $scanner = $this->scanner();
        $reachable = $scanner->reachable(self::ROOTS);
        self::assertContains(\Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService::class, $reachable, 'The reachability walk must reach the remediation access service.');

        $untagged = [];
        foreach ($reachable as $class) {
            $state = $scanner->mutableState($class);
            if ($state === [] || is_subclass_of($class, McpCallScopedState::class)) {
                continue;
            }
            $unexplained = array_diff($state, array_keys(self::PROCESS_WIDE[$class] ?? []));
            if ($unexplained !== []) {
                $untagged[] = $class . ' (' . implode(', ', $unexplained) . ')';
            }
        }

        self::assertSame([], $untagged, 'MCP-reachable services keep per-process state without McpCallScopedState: '
            . "implement resetCallState(), or list the property in PROCESS_WIDE with the reason it can never depend on the user.\n" . implode("\n", $untagged));
    }

    #[Test]
    public function resetCallStateEmptiesEveryMemoOfATaggedService(): void
    {
        $scanner = $this->scanner();
        foreach ($scanner->reachable(self::ROOTS) as $class) {
            if (!is_subclass_of($class, McpCallScopedState::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $instance = $reflection->newInstanceWithoutConstructor();
            $defaults = [];
            foreach ($scanner->mutableState($class) as $name) {
                $property = $reflection->getProperty($name);
                $defaults[$name] = $property->hasDefaultValue() ? $property->getDefaultValue() : null;
                // Dirty every memo, then reset.
                $type = $property->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                $dirty = match ($typeName) {
                    'array' => ['dirty' => true],
                    'bool' => !($defaults[$name] ?? false),
                    'string' => 'dirty',
                    'int' => 42,
                    default => null,
                };
                if ($dirty !== null) {
                    $property->setValue($instance, $dirty);
                }
            }
            $instance->resetCallState();
            foreach ($defaults as $name => $default) {
                self::assertSame($default, $reflection->getProperty($name)->getValue($instance), $class . '::$' . $name . ' survives resetCallState().');
            }
        }
    }

    #[Test]
    public function processWideListHasNoStaleEntries(): void
    {
        $scanner = $this->scanner();
        foreach (self::PROCESS_WIDE as $class => $properties) {
            self::assertTrue(class_exists($class), $class . ' no longer exists.');
            self::assertSame([], array_diff(array_keys($properties), $scanner->mutableState($class)), $class . ' lists properties that are no longer state.');
        }
    }

    #[Test]
    public function servicesYamlTagsAndInjectsTheResettableServices(): void
    {
        $services = Yaml::parse((string)file_get_contents(dirname(__DIR__, 3) . '/Configuration/Services.yaml'), Yaml::PARSE_CUSTOM_TAGS)['services'];
        self::assertSame(McpCallScopedState::TAG, $services['_instanceof'][McpCallScopedState::class]['tags'][0]['name'] ?? null);
        $argument = $services[\Priebera\A11yQualityGate\Mcp\McpUserContext::class]['arguments']['$callScopedState'] ?? null;
        self::assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $argument);
        self::assertSame('tagged_iterator', $argument->getTag());
        self::assertSame(McpCallScopedState::TAG, $argument->getValue());
    }

    #[Test]
    public function scannerDetectsAnUntaggedMemo(): void
    {
        // The guard itself: a memo like RemediationAccessService's before 3.0.0 is found.
        self::assertSame(['readablePages'], $this->scanner()->mutableState(\Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService::class));
        self::assertSame(['memo'], $this->scanner()->mutableState(McpCallStateGuardProbe::class));
    }
}

/** @internal test probe: an untagged memo assigned with ??= */
final class McpCallStateGuardProbe
{
    /** @var array<int,bool> */
    private array $memo = [];

    public function __construct(private readonly int $seed = 0)
    {
    }

    public function check(int $page): bool
    {
        return $this->memo[$page] ??= ($page + $this->seed) % 2 === 0;
    }
}
