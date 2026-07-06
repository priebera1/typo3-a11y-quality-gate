<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiModelDiscoveryService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiModelDiscoveryServiceTest extends TestCase
{
    #[Test]
    public function oneCompatibleModelIsAutomaticallySelected(): void
    {
        $repository = $this->repository([]);
        $repository->expects(self::once())->method('saveDiscovery')->with(
            'main',
            self::isType('string'),
            self::greaterThan(0),
            'gpt-4.1-mini',
        );

        $result = $this->subject($repository, ['gpt-audio-1', 'gpt-4.1-mini'])->discover('main');

        self::assertSame('gpt-4.1-mini', $result['selectedModelId']);
        self::assertCount(1, $result['supported']);
        self::assertSame(['gpt-audio-1'], $result['unsupported']);
    }

    #[Test]
    public function multipleCompatibleModelsRequireExplicitAdminSelection(): void
    {
        $repository = $this->repository([]);
        $repository->expects(self::once())->method('saveDiscovery')->with(
            'main',
            self::isType('string'),
            self::greaterThan(0),
            '',
        );

        $result = $this->subject($repository, ['gpt-5.4-mini', 'gpt-4.1-mini'])->discover('main');

        self::assertSame('', $result['selectedModelId']);
        self::assertCount(2, $result['supported']);
    }

    #[Test]
    public function existingSelectionIsPreservedWhenStillAvailable(): void
    {
        $repository = $this->repository(['selected_model_id' => 'gpt-5.4-mini']);
        $repository->expects(self::once())->method('saveDiscovery')->with(
            'main',
            self::isType('string'),
            self::greaterThan(0),
            'gpt-5.4-mini',
        );

        $result = $this->subject($repository, ['gpt-5.4-mini', 'gpt-4.1-mini'])->discover('main');

        self::assertSame('gpt-5.4-mini', $result['selectedModelId']);
    }

    #[Test]
    public function fix19LegacyModelIsOnlyPreselectedAfterAvailabilityWasConfirmed(): void
    {
        $repository = $this->repository([
            'selected_model_id' => '',
            'model' => 'gpt-5.4-mini-2026-03-17',
        ]);
        $repository->expects(self::once())->method('saveDiscovery')->with(
            'main',
            self::isType('string'),
            self::greaterThan(0),
            'gpt-5.4-mini-2026-03-17',
        );

        $result = $this->subject($repository, [
            'gpt-5.4-mini-2026-03-17',
            'gpt-4.1-mini',
        ])->discover('main');

        self::assertSame('gpt-5.4-mini-2026-03-17', $result['selectedModelId']);
    }

    #[Test]
    public function zeroCompatibleModelsPersistsFailureWithoutFallback(): void
    {
        $repository = $this->repository([]);
        $repository->expects(self::once())->method('saveDiscovery')->with(
            'main',
            self::isType('string'),
            self::greaterThan(0),
            '',
        );
        $repository->expects(self::once())->method('markTested')->with(
            'main',
            false,
            str_repeat('b', 64),
            '',
            'aqg_alt_text_v3',
            self::isType('string'),
            'no_supported_models',
        );

        try {
            $this->subject($repository, ['gpt-audio-1', 'text-embedding-3-small'])->discover('main');
            self::fail('Expected no-supported-model failure.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame('no_supported_models', $exception->safeCode);
        }
    }

    #[Test]
    public function cacheFromDifferentKeyIsRediscoveredEvenWhenTimestampIsFresh(): void
    {
        $cache = (new AiModelCacheCodec())->encode([
            ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
        ], [], str_repeat('a', 64));
        $repository = $this->repository([
            'selected_model_id' => 'gpt-4.1-mini',
            'discovered_models_cache' => $cache,
            'discovered_models_at' => time(),
        ]);
        $repository->expects(self::once())->method('saveDiscovery');

        $this->subject($repository, ['gpt-4.1-mini'])->ensureFresh('main');
    }

    private function subject(
        AiConfigurationRepositoryInterface $repository,
        array $modelIds,
    ): AiModelDiscoveryService {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolveCredentials')->with('main')->willReturn(
            new AiProviderCredentials('openai', 'secret', 'site', str_repeat('b', 64)),
        );
        $provider = $this->createMock(AiModelDiscoveryProviderInterface::class);
        $provider->method('supports')->with('openai')->willReturn(true);
        $provider->method('listModelIds')->willReturn($modelIds);

        return new AiModelDiscoveryService(
            $resolver,
            $repository,
            new AiModelCompatibilityRegistry(),
            new AiModelCacheCodec(),
            [$provider],
        );
    }

    /** @param array<string,mixed> $row */
    private function repository(array $row): AiConfigurationRepositoryInterface
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->with('main')->willReturn($row);

        return $repository;
    }
}
