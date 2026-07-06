<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationResolver;
use Priebera\A11yQualityGate\Ai\Service\AiKeyFingerprintService;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class AiConfigurationResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    protected function tearDown(): void
    {
        putenv('AQG_OPENAI_API_KEY');
        parent::tearDown();
    }

    #[Test]
    public function environmentKeyUsesAdminSelectedModelInsteadOfAqgDefault(): void
    {
        putenv('AQG_OPENAI_API_KEY=env-secret');
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->with('main')->willReturn($this->configuredRow('gpt-4.1-mini', 'env-secret'));

        $subject = $this->subject($repository);
        $configuration = $subject->resolve('main');

        self::assertSame('env-secret', $configuration->apiKey());
        self::assertSame('gpt-4.1-mini', $configuration->model);
        self::assertSame('environment', $configuration->source);
        self::assertNotSame('', $configuration->keyFingerprint);
        self::assertStringNotContainsString('env-secret', print_r($configuration, true));
    }

    #[Test]
    public function modelCacheFromDifferentEnvironmentKeyCannotBeUsed(): void
    {
        putenv('AQG_OPENAI_API_KEY=new-env-secret');
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->with('main')->willReturn(
            $this->configuredRow('gpt-4.1-mini', 'old-env-secret'),
        );

        $this->expectException(\Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException::class);
        $this->expectExceptionCode(1771002004);

        $this->subject($repository)->resolve('main');
    }

    #[Test]
    public function siteKeyUsesSelectedDiscoveredModel(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->with('main')->willReturn($this->configuredRow('gpt-5.4-nano'));
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->with('encrypted-main')->willReturn('site-secret');

        $configuration = $this->subject($repository, $encryption)->resolve('main');

        self::assertSame('site-secret', $configuration->apiKey());
        self::assertSame('gpt-5.4-nano', $configuration->model);
        self::assertSame('site', $configuration->source);
    }

    #[Test]
    public function connectedStatusIsBoundToKeyModelPromptAndContract(): void
    {
        $fingerprint = (new AiKeyFingerprintService())->fingerprint('site-secret');
        $row = array_replace($this->configuredRow('gpt-5.4-mini'), [
            'last_tested_at' => 200,
            'last_verified_at' => 200,
            'verified_key_fingerprint' => $fingerprint,
            'verified_model_id' => 'gpt-5.4-mini',
            'verified_prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
            'verified_connection_contract_version' => AiPromptDefinition::CONNECTION_TEST_VERSION,
        ]);
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($row);
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('site-secret');

        $status = $this->subject($repository, $encryption)->status('main');

        self::assertSame('connected', $status['connectionStatus']);
        self::assertSame(200, $status['lastVerifiedAt']);
        self::assertSame('gpt-5.4-mini', $status['selectedModelId']);
    }

    #[Test]
    public function promptContractMismatchIsNotConnected(): void
    {
        $fingerprint = (new AiKeyFingerprintService())->fingerprint('site-secret');
        $row = array_replace($this->configuredRow('gpt-5.4-mini'), [
            'last_verified_at' => 200,
            'verified_key_fingerprint' => $fingerprint,
            'verified_model_id' => 'gpt-5.4-mini',
            'verified_prompt_version' => 'old_prompt',
            'verified_connection_contract_version' => 'old_contract',
        ]);
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($row);
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('site-secret');

        $status = $this->subject($repository, $encryption)->status('main');

        self::assertSame('not_verified', $status['connectionStatus']);
        self::assertSame(0, $status['lastVerifiedAt']);
    }

    #[Test]
    public function changingCurrentModelInvalidatesPreviousVerification(): void
    {
        $fingerprint = (new AiKeyFingerprintService())->fingerprint('site-secret');
        $row = array_replace($this->configuredRow('gpt-4.1-mini'), [
            'last_verified_at' => 200,
            'verified_key_fingerprint' => $fingerprint,
            'verified_model_id' => 'gpt-5.4-mini',
            'verified_prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
            'verified_connection_contract_version' => AiPromptDefinition::CONNECTION_TEST_VERSION,
        ]);
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($row);
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('site-secret');

        $status = $this->subject($repository, $encryption)->status('main');

        self::assertSame('not_verified', $status['connectionStatus']);
        self::assertSame(0, $status['lastVerifiedAt']);
    }

    /** @return array<string,mixed> */
    private function configuredRow(string $selectedModelId, string $key = 'site-secret'): array
    {
        $codec = new AiModelCacheCodec();

        return [
            'enabled' => 1,
            'encrypted_api_key' => 'encrypted-main',
            'key_hint' => '…main',
            'selected_model_id' => $selectedModelId,
            'discovered_models_cache' => $codec->encode([
                ['id' => $selectedModelId, 'label' => $selectedModelId],
            ], [], (new AiKeyFingerprintService())->fingerprint($key)),
            'discovered_models_at' => time(),
            'last_tested_at' => 0,
            'last_verified_at' => 0,
            'last_test_error_code' => '',
        ];
    }

    private function subject(
        AiConfigurationRepositoryInterface $repository,
        ?SecretEncryptionServiceInterface $encryption = null,
    ): AiConfigurationResolver {
        return new AiConfigurationResolver(
            $repository,
            $encryption ?? $this->createMock(SecretEncryptionServiceInterface::class),
            $this->siteResolver(),
            new AiKeyFingerprintService(),
            new AiModelCompatibilityRegistry(),
            new AiModelCacheCodec(),
        );
    }

    private function siteResolver(): SiteResolutionServiceInterface
    {
        $resolver = $this->createMock(SiteResolutionServiceInterface::class);
        $resolver->method('resolveSiteByIdentifier')->with('main')->willReturn($this->createMock(Site::class));

        return $resolver;
    }
}
