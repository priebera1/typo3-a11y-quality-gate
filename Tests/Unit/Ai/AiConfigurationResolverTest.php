<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationResolver;
use Priebera\A11yQualityGate\Ai\Service\AiKeyFingerprintService;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Service\AiReadinessPolicy;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
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
        $repository->method('findBySiteIdentifier')->with('main')->willReturn($this->verifiedRow('gpt-4.1-mini', 'env-secret'));

        $subject = $this->subject($repository, null, $this->acknowledgedSettings());
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
        $repository->method('findBySiteIdentifier')->with('main')->willReturn($this->verifiedRow('gpt-5.4-nano'));
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->with('encrypted-main')->willReturn('site-secret');

        $configuration = $this->subject($repository, $encryption, $this->acknowledgedSettings())->resolve('main');

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

    /**
     * AQG 2.x and the first 3.0 builds let resolve() use a selected model that never passed the connection test,
     * although the Settings tab told administrators that editors need "Model verified". The readiness policy now
     * refuses it server-side (1771002006); the connection test itself still resolves.
     */
    #[Test]
    public function resolveRefusesASelectedButUnverifiedModel(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->configuredRow('gpt-5.4-mini'));
        $subject = $this->subject($repository, $this->encryption(), $this->acknowledgedSettings());

        self::assertSame('gpt-5.4-mini', $subject->resolveForConnectionTest('main')->model);
        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionCode(AiConfigurationResolver::CODE_NOT_VERIFIED);
        $subject->resolve('main');
    }

    #[Test]
    public function resolveRefusesAVerifiedModelWithoutProcessingAcknowledgement(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = new InMemoryAiSiteSettingRepository();
        $settings->saveProvider('main', 'openai', 1);
        $subject = $this->subject($repository, $this->encryption(), $settings);

        // The connection test sends no site content and needs no acknowledgement.
        self::assertSame('gpt-5.4-mini', $subject->resolveForConnectionTest('main')->model);
        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionCode(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING);
        $subject->resolve('main');
    }

    #[Test]
    public function anAcknowledgementForTheOtherProviderDoesNotCount(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = new InMemoryAiSiteSettingRepository();
        $settings->saveProvider('main', 'openai', 1);
        $settings->saveAcknowledgement('main', 'anthropic', 1);

        $this->expectExceptionCode(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING);
        $this->subject($repository, $this->encryption(), $settings)->resolve('main');
    }

    #[Test]
    public function aReachedHardStopBudgetBlocksAndTheConnectionTestStaysPossible(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = $this->acknowledgedSettings();
        $settings->saveBudget('main', 5_000_000, 80, true, 1);
        $subject = $this->subject($repository, $this->encryption(), $settings, $this->budgetService($settings, 5_000_000, true));

        self::assertSame('gpt-5.4-mini', $subject->resolveForConnectionTest('main')->model);
        $status = $subject->status('main');
        self::assertSame('reached', $status['budget']['state']);
        self::assertSame('budget_reached', $status['budget']['blockCode']);
        self::assertSame(AiReadinessPolicy::MISSING, $status['readiness']['budget']);

        $this->expectExceptionCode(AiBudgetService::CODE_BUDGET_REACHED);
        $subject->resolve('main');
    }

    #[Test]
    public function aReachedWarningOnlyBudgetNeverBlocks(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = $this->acknowledgedSettings();
        $settings->saveBudget('main', 5_000_000, 80, false, 1);
        $subject = $this->subject($repository, $this->encryption(), $settings, $this->budgetService($settings, 9_000_000, true));

        self::assertSame('gpt-5.4-mini', $subject->resolve('main')->model);
        self::assertSame(AiReadinessPolicy::WARN, $subject->status('main')['readiness']['budget']);
    }

    /** Interim rule for open owner decision 01#3: a hard stop without a price cannot be enforced and holds AI. */
    #[Test]
    public function aHardStopBudgetWithoutAPriceIsUnenforceableAndBlocks(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = $this->acknowledgedSettings();
        $settings->saveBudget('main', 5_000_000, 0, true, 1);
        $subject = $this->subject($repository, $this->encryption(), $settings, $this->budgetService($settings, 0, false));

        self::assertSame('budget_unenforceable', $subject->status('main')['budget']['blockCode']);
        $this->expectExceptionCode(AiBudgetService::CODE_BUDGET_UNENFORCEABLE);
        $subject->resolve('main');
    }

    #[Test]
    public function aReadySiteResolvesAndReportsEveryReadinessItem(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('findBySiteIdentifier')->willReturn($this->verifiedRow('gpt-5.4-mini'));
        $settings = $this->acknowledgedSettings();
        $subject = $this->subject($repository, $this->encryption(), $settings, $this->budgetService($settings, 0, true));

        self::assertSame('gpt-5.4-mini', $subject->resolve('main')->model);
        $status = $subject->status('main');
        self::assertSame(AiReadinessPolicy::ITEMS, array_keys($status['readiness']));
        self::assertSame(['ok'], array_values(array_unique($status['readiness'])));
        self::assertTrue($status['acknowledgement']['confirmed']);
        self::assertSame('openai', $status['acknowledgement']['provider']);
    }

    private function encryption(): SecretEncryptionServiceInterface
    {
        $encryption = $this->createMock(SecretEncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('site-secret');

        return $encryption;
    }

    private function acknowledgedSettings(): InMemoryAiSiteSettingRepository
    {
        $settings = new InMemoryAiSiteSettingRepository();
        $settings->saveProvider('main', 'openai', 1);
        $settings->saveAcknowledgement('main', 'openai', 1);

        return $settings;
    }

    private function budgetService(InMemoryAiSiteSettingRepository $settings, int $spentMicroUsd, bool $priced): AiBudgetService
    {
        $budgetRepository = $this->createMock(AiBudgetRepositoryInterface::class);
        $budgetRepository->method('spentMicroUsd')->willReturn($spentMicroUsd);
        $budgetRepository->method('reservedMicroUsd')->willReturn(0);
        $budgetRepository->method('accounting')->willReturn(['spent' => $spentMicroUsd, 'reserved' => 0]);
        // Priced: the bundled automatic price of gpt-5.4-mini. Unpriced: a price table that knows no price (an unknown model).
        $table = new AiPriceTable($this->createMock(AiPriceRepositoryInterface::class), new AiModelCompatibilityRegistry());
        if (!$priced) {
            $table = $this->createMock(AiPriceTable::class);
            $table->method('find')->willReturn(null);
        }

        return new AiBudgetService(
            $budgetRepository,
            $table,
            new AiSiteSettingsService($settings, $this->createMock(AiConfigurationRepositoryInterface::class)),
        );
    }

    /** @return array<string,mixed> */
    private function verifiedRow(string $selectedModelId, string $key = 'site-secret'): array
    {
        return array_replace($this->configuredRow($selectedModelId, $key), [
            'last_tested_at' => 200,
            'last_verified_at' => 200,
            'verified_key_fingerprint' => (new AiKeyFingerprintService())->fingerprint($key),
            'verified_model_id' => $selectedModelId,
            'verified_prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
            'verified_connection_contract_version' => AiPromptDefinition::CONNECTION_TEST_VERSION,
        ]);
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
        ?InMemoryAiSiteSettingRepository $settings = null,
        ?AiBudgetService $budgetService = null,
    ): AiConfigurationResolver {
        return new AiConfigurationResolver(
            $repository,
            $encryption ?? $this->createMock(SecretEncryptionServiceInterface::class),
            $this->siteResolver(),
            new AiKeyFingerprintService(),
            new AiModelCompatibilityRegistry(),
            new AiModelCacheCodec(),
            $settings !== null ? new AiSiteSettingsService($settings, $repository) : null,
            $budgetService,
        );
    }

    private function siteResolver(): SiteResolutionServiceInterface
    {
        $resolver = $this->createMock(SiteResolutionServiceInterface::class);
        $resolver->method('resolveSiteByIdentifier')->with('main')->willReturn($this->createMock(Site::class));

        return $resolver;
    }
}
