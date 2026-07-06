<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationManager;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class AiConfigurationManagerTest extends TestCase
{
    #[Test]
    public function keyCanBeSavedWithoutModelSelection(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('saveKey')
            ->with('main', 'encrypted', 'sk-proj…7890', true);
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->expects(self::once())->method('encrypt')->with('sk-proj-1234567890')->willReturn('encrypted');

        $this->subject($repository, $encryption)->save('main', ' sk-proj-1234567890 ');
    }

    #[Test]
    public function onlyCachedCompatibleAvailableModelCanBeSelected(): void
    {
        $codec = new AiModelCacheCodec();
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->with('main')->willReturn([
            'discovered_models_cache' => $codec->encode([
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
            ], [], str_repeat('a', 64)),
        ]);
        $repository->expects(self::once())->method('selectModel')->with('main', 'gpt-4.1-mini');

        $this->subject($repository, cacheCodec: $codec)->selectModel('main', 'gpt-4.1-mini');
    }

    #[Test]
    public function unknownOrUnavailableModelCannotBeSelected(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->expects(self::never())->method('selectModel');

        $this->expectException(\InvalidArgumentException::class);
        $this->subject($repository)->selectModel('main', 'gpt-audio-1');
    }

    private function subject(
        AiConfigurationRepositoryInterface $repository,
        ?SecretEncryptionServiceInterface $encryption = null,
        ?AiModelCacheCodec $cacheCodec = null,
    ): AiConfigurationManager {
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteByIdentifier')->with('main')->willReturn($this->createMock(Site::class));

        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolveCredentials')->with('main')->willReturn(
            new AiProviderCredentials('openai', 'secret', 'site', str_repeat('a', 64)),
        );

        return new AiConfigurationManager(
            $repository,
            $encryption ?? $this->createMock(SecretEncryptionServiceInterface::class),
            $sites,
            $resolver,
            new AiModelCompatibilityRegistry(),
            $cacheCodec ?? new AiModelCacheCodec(),
        );
    }
}
