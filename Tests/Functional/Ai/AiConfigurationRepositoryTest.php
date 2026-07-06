<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Domain\Repository\AiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;

final class AiConfigurationRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function keyCanBeStoredBeforeAProjectModelIsSelected(): void
    {
        $subject = $this->get(AiConfigurationRepository::class);
        $subject->saveKey('main', 'encrypted-value', 'sk-proj…1234', true);

        $row = $subject->findBySiteIdentifier('main');
        self::assertIsArray($row);
        self::assertSame('encrypted-value', $row['encrypted_api_key']);
        self::assertSame('', $row['selected_model_id']);
        self::assertSame('', $row['discovered_models_cache']);
        self::assertSame(0, (int)$row['last_verified_at']);
    }

    #[Test]
    public function discoveryPersistsNormalizedCacheAndOneModelSelection(): void
    {
        $subject = $this->get(AiConfigurationRepository::class);
        $subject->saveKey('main', 'encrypted-value', 'sk-proj…1234', true);
        $cache = (new AiModelCacheCodec())->encode([
            ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
        ], ['gpt-audio-1'], str_repeat('a', 64));
        $subject->saveDiscovery('main', $cache, 123456, 'gpt-4.1-mini');

        $row = $subject->findBySiteIdentifier('main');
        self::assertSame('gpt-4.1-mini', $row['selected_model_id']);
        self::assertSame($cache, $row['discovered_models_cache']);
        self::assertSame(123456, (int)$row['discovered_models_at']);
    }

    #[Test]
    public function successfulTestBindsVerificationToKeyModelPromptAndContract(): void
    {
        $subject = $this->preparedRepository('gpt-4.1-mini');
        $subject->markTested(
            'main',
            true,
            'fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
        );

        $row = $subject->findBySiteIdentifier('main');
        self::assertGreaterThan(0, (int)$row['last_tested_at']);
        self::assertGreaterThan(0, (int)$row['last_verified_at']);
        self::assertSame('fingerprint', $row['verified_key_fingerprint']);
        self::assertSame('gpt-4.1-mini', $row['verified_model_id']);
        self::assertSame(AiPromptDefinition::AI_PROMPT_VERSION, $row['verified_prompt_version']);
        self::assertSame(AiPromptDefinition::CONNECTION_TEST_VERSION, $row['verified_connection_contract_version']);
        self::assertSame('', $row['last_test_error_code']);
    }

    #[Test]
    public function failedTestNeverKeepsConfigurationConnected(): void
    {
        $subject = $this->preparedRepository('gpt-4.1-mini');
        $subject->markTested(
            'main',
            true,
            'fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
        );
        $subject->markTested(
            'main',
            false,
            'fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
            'model_not_permitted',
        );

        $row = $subject->findBySiteIdentifier('main');
        self::assertGreaterThan(0, (int)$row['last_tested_at']);
        self::assertSame(0, (int)$row['last_verified_at']);
        self::assertSame('', $row['verified_key_fingerprint']);
        self::assertSame('', $row['verified_model_id']);
        self::assertSame('model_not_permitted', $row['last_test_error_code']);
    }

    #[Test]
    public function changingSelectedModelResetsVerification(): void
    {
        $subject = $this->preparedRepository('gpt-4.1-mini');
        $subject->markTested(
            'main',
            true,
            'fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
        );

        $subject->selectModel('main', 'gpt-5.4-mini');
        $row = $subject->findBySiteIdentifier('main');

        self::assertSame('gpt-5.4-mini', $row['selected_model_id']);
        self::assertSame(0, (int)$row['last_verified_at']);
        self::assertSame('', $row['verified_model_id']);
    }

    #[Test]
    public function refreshingSameAvailableSelectionKeepsVerification(): void
    {
        $subject = $this->preparedRepository('gpt-4.1-mini');
        $subject->markTested(
            'main',
            true,
            'fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
        );
        $verified = $subject->findBySiteIdentifier('main');

        $subject->saveDiscovery(
            'main',
            (string)$verified['discovered_models_cache'],
            time(),
            'gpt-4.1-mini',
        );
        $refreshed = $subject->findBySiteIdentifier('main');

        self::assertSame((int)$verified['last_verified_at'], (int)$refreshed['last_verified_at']);
        self::assertSame('gpt-4.1-mini', $refreshed['verified_model_id']);
    }

    #[Test]
    public function replacingKeyResetsVerificationAndDiscoveryButPreservesCandidateSelection(): void
    {
        $subject = $this->preparedRepository('gpt-4.1-mini');
        $subject->markTested(
            'main',
            true,
            'old-fingerprint',
            'gpt-4.1-mini',
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
        );

        $subject->saveKey('main', 'encrypted-new', 'sk-proj…2222', true);
        $row = $subject->findBySiteIdentifier('main');

        self::assertSame('encrypted-new', $row['encrypted_api_key']);
        self::assertSame('gpt-4.1-mini', $row['selected_model_id']);
        self::assertSame('', $row['discovered_models_cache']);
        self::assertSame(0, (int)$row['last_verified_at']);
        self::assertSame('', $row['verified_key_fingerprint']);
    }

    private function preparedRepository(string $selectedModel): AiConfigurationRepository
    {
        $subject = $this->get(AiConfigurationRepository::class);
        $subject->saveKey('main', 'encrypted-value', 'sk-proj…1234', true);
        $subject->saveDiscovery(
            'main',
            (new AiModelCacheCodec())->encode([
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
                ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini'],
            ], [], str_repeat('a', 64)),
            time(),
            $selectedModel,
        );

        return $subject;
    }
}
