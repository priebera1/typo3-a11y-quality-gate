<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationManager;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationResolver;
use Priebera\A11yQualityGate\Ai\Service\AiKeyFingerprintService;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * One provider per site, separate credentials per provider, safe revalidation after a switch.
 */
final class AiProviderSwitchingTest extends TestCase
{
    private const OPENAI_KEY = 'sk-proj-openai-secret-0001';
    private const ANTHROPIC_KEY = 'sk-ant-api03-anthropic-secret-0001';

    private InMemoryAiConfigurationRepository $configurations;
    private InMemoryAiSiteSettingRepository $siteSettings;
    private AiConfigurationResolver $resolver;
    private AiConfigurationManager $manager;
    private AiKeyFingerprintService $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
        putenv('AQG_OPENAI_API_KEY');

        $this->configurations = new InMemoryAiConfigurationRepository();
        $this->siteSettings = new InMemoryAiSiteSettingRepository();
        $this->fingerprints = new AiKeyFingerprintService();
        $encryption = new class () implements SecretEncryptionServiceInterface {
            public function encrypt(string $plainText): string
            {
                return 'sodium:v1:' . base64_encode(strrev($plainText));
            }

            public function decrypt(string $encryptedValue): string
            {
                return str_starts_with($encryptedValue, 'sodium:v1:') ? strrev((string)base64_decode(substr($encryptedValue, 10))) : '';
            }
        };
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteByIdentifier')->willReturnCallback(
            fn (string $identifier): ?Site => in_array($identifier, ['main', 'other'], true) ? $this->createMock(Site::class) : null,
        );
        $settingsService = new AiSiteSettingsService($this->siteSettings, $this->configurations);
        $registry = new AiModelCompatibilityRegistry();
        $codec = new AiModelCacheCodec();
        $this->resolver = new AiConfigurationResolver($this->configurations, $encryption, $sites, $this->fingerprints, $registry, $codec, $settingsService);
        $this->manager = new AiConfigurationManager($this->configurations, $encryption, $sites, $this->resolver, $registry, $codec, $settingsService, $this->siteSettings);
    }

    protected function tearDown(): void
    {
        putenv('AQG_OPENAI_API_KEY');
        parent::tearDown();
    }

    /**
     * An upgraded 2.x site keeps OpenAI. Since the readiness policy, its editors get AI only once the model is
     * verified and an administrator confirmed the processing acknowledgement (the upgrade sets neither).
     */
    #[Test]
    public function upgradedSiteWithoutProviderChoiceKeepsUsingOpenAi(): void
    {
        $this->manager->save('main', self::OPENAI_KEY);
        $this->discover('main', 'openai', self::OPENAI_KEY, 'gpt-5.4-mini');

        $credentials = $this->resolver->resolveCredentials('main');
        self::assertSame('openai', $credentials->provider);
        self::assertSame(self::OPENAI_KEY, $credentials->apiKey());
        self::assertTrue($this->resolver->status('main')['providerChosen']);
        self::assertSame([], $this->siteSettings->rows, 'Reading never writes a site-setting row.');
        $this->assertRefused(AiConfigurationResolver::CODE_NOT_VERIFIED);

        $this->verify('main', 'openai', self::OPENAI_KEY, 'gpt-5.4-mini');
        $this->assertRefused(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING);

        $this->manager->acknowledgeProcessing('main', 'openai', 3);
        $configuration = $this->resolver->resolve('main');
        self::assertSame('openai', $configuration->provider);
        self::assertSame(self::OPENAI_KEY, $configuration->apiKey());
    }

    #[Test]
    public function switchingTheProviderClearsTheAcknowledgement(): void
    {
        $this->manager->save('main', self::OPENAI_KEY);
        $this->manager->acknowledgeProcessing('main', 'openai', 3);
        self::assertTrue($this->resolver->status('main')['acknowledgement']['confirmed']);
        self::assertSame(3, $this->siteSettings->rows['main']['processing_ack_by']);

        $this->manager->selectProvider('main', 'anthropic', 3);
        self::assertFalse($this->resolver->status('main')['acknowledgement']['confirmed']);

        // Switching back does not bring the old acknowledgement back either.
        $this->manager->selectProvider('main', 'openai', 3);
        self::assertFalse($this->resolver->status('main')['acknowledgement']['confirmed']);
    }

    #[Test]
    public function anAcknowledgementFormRenderedBeforeASwitchCannotConfirmTheNewProvider(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);

        try {
            $this->manager->acknowledgeProcessing('main', 'openai', 1);
            self::fail('The acknowledgement names the provider it covers.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(1771002010, $exception->getCode());
        }
        self::assertFalse($this->resolver->status('main')['acknowledgement']['confirmed']);
    }

    #[Test]
    public function switchingProvidersKeepsBothKeysAndUsesOnlyTheActiveOne(): void
    {
        $this->manager->save('main', self::OPENAI_KEY);
        $openAiRow = $this->configurations->findBySiteIdentifier('main', 'openai');

        self::assertTrue($this->manager->selectProvider('main', 'anthropic', 7));
        $this->manager->save('main', self::ANTHROPIC_KEY, 'anthropic');
        $this->discover('main', 'anthropic', self::ANTHROPIC_KEY, 'claude-sonnet-5-5');
        $this->verify('main', 'anthropic', self::ANTHROPIC_KEY, 'claude-sonnet-5-5');
        $this->manager->acknowledgeProcessing('main', 'anthropic', 7);

        self::assertSame($openAiRow['encrypted_api_key'], $this->configurations->findBySiteIdentifier('main', 'openai')['encrypted_api_key']);
        $configuration = $this->resolver->resolve('main');
        self::assertSame('anthropic', $configuration->provider);
        self::assertSame(self::ANTHROPIC_KEY, $configuration->apiKey());
        self::assertSame('claude-sonnet-5-5', $configuration->model);
        self::assertSame($this->fingerprints->fingerprint(self::ANTHROPIC_KEY, 'anthropic'), $configuration->keyFingerprint);
        self::assertSame(7, $this->siteSettings->rows['main']['provider_changed_by']);

        $cards = array_column($this->resolver->status('main')['providers'], null, 'id');
        self::assertTrue($cards['openai']['configured']);
        self::assertFalse($cards['openai']['active']);
        self::assertTrue($cards['anthropic']['configured']);
        self::assertTrue($cards['anthropic']['active']);
        self::assertStringNotContainsString('secret', json_encode($this->resolver->status('main'), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function providersNeverShareAFingerprintForTheSameKeyString(): void
    {
        self::assertNotSame(
            $this->fingerprints->fingerprint('sk-same-string-0001', 'openai'),
            $this->fingerprints->fingerprint('sk-same-string-0001', 'anthropic'),
        );
        self::assertSame(
            $this->fingerprints->fingerprint('sk-same-string-0001'),
            $this->fingerprints->fingerprint('sk-same-string-0001', 'openai'),
            'The OpenAI fingerprint is unchanged since 2.0.',
        );
    }

    #[Test]
    public function openAiEnvironmentKeyNeverReachesAnthropic(): void
    {
        putenv('AQG_OPENAI_API_KEY=sk-env-openai-secret-0001');
        $this->manager->selectProvider('main', 'anthropic', 1);
        $this->manager->save('main', self::ANTHROPIC_KEY, 'anthropic');

        $credentials = $this->resolver->resolveCredentials('main');

        self::assertSame('anthropic', $credentials->provider);
        self::assertSame('site', $credentials->source);
        self::assertSame(self::ANTHROPIC_KEY, $credentials->apiKey());
        self::assertFalse($this->resolver->status('main')['environmentOverride']);
    }

    #[Test]
    public function switchingBackRequiresANewConnectionTestBeforeReuse(): void
    {
        $this->manager->save('main', self::OPENAI_KEY);
        $this->discover('main', 'openai', self::OPENAI_KEY, 'gpt-5.4-mini');
        $this->verify('main', 'openai', self::OPENAI_KEY, 'gpt-5.4-mini');
        $this->manager->acknowledgeProcessing('main', 'openai', 1);
        self::assertSame('connected', $this->resolver->status('main')['connectionStatus']);
        self::assertSame('openai', $this->resolver->resolve('main')->provider);

        $this->manager->selectProvider('main', 'anthropic', 1);
        $this->manager->selectProvider('main', 'openai', 1);

        $status = $this->resolver->status('main');
        self::assertSame('not_verified', $status['connectionStatus']);
        self::assertTrue($status['revalidationRequired']);
        self::assertSame('gpt-5.4-mini', $status['selectedModelId'], 'The model choice is kept for revalidation.');
        try {
            $this->resolver->resolve('main');
            self::fail('A model awaiting revalidation must not be used.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(1771002005, $exception->getCode());
        }

        $testConfiguration = $this->resolver->resolveForConnectionTest('main');
        self::assertSame('gpt-5.4-mini', $testConfiguration->model);
        $this->verify('main', 'openai', self::OPENAI_KEY, 'gpt-5.4-mini');
        // The switch cleared the acknowledgement: verified again, AI still waits for it.
        $this->assertRefused(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING);
        $this->manager->acknowledgeProcessing('main', 'openai', 1);

        self::assertSame('openai', $this->resolver->resolve('main')->provider);
        self::assertFalse($this->resolver->status('main')['revalidationRequired']);
    }

    #[Test]
    public function selectingTheActiveProviderAgainChangesNothing(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);

        self::assertFalse($this->manager->selectProvider('main', 'anthropic', 1));
    }

    #[Test]
    public function aKeyFormRenderedBeforeASwitchCannotStoreUnderTheWrongProvider(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);

        try {
            $this->manager->save('main', self::OPENAI_KEY, 'openai');
            self::fail('Expected a provider mismatch.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(1771002010, $exception->getCode());
        }
        self::assertNull($this->configurations->findBySiteIdentifier('main', 'openai'));
        self::assertNull($this->configurations->findBySiteIdentifier('main', 'anthropic'));
    }

    #[Test]
    public function anOpenAiKeyIsNotStoredAsAnthropicKey(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);

        foreach (['sk-proj-not-anthropic-0001', 'sk-ant-admin01-not-for-messages-0001', 'sk-ant-short'] as $key) {
            try {
                $this->manager->save('main', $key);
                self::fail('Expected an invalid key format: ' . $key);
            } catch (AiConfigurationException $exception) {
                self::assertSame(1771002011, $exception->getCode());
            }
        }
        self::assertNull($this->configurations->findBySiteIdentifier('main', 'anthropic'));
    }

    #[Test]
    public function anAnthropicKeyIsNeverStoredForOrSentToOpenAi(): void
    {
        try {
            $this->manager->save('main', self::ANTHROPIC_KEY);
            self::fail('Expected an Anthropic key to be refused for OpenAI.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(1771002012, $exception->getCode());
        }
        self::assertNull($this->configurations->findBySiteIdentifier('main', 'openai'));

        // Any other OpenAI key keeps its 2.x behaviour: stored, the connection test reports a rejection.
        $this->manager->save('main', 'legacy-style-openai-key');
        self::assertNotNull($this->configurations->findBySiteIdentifier('main', 'openai'));
    }

    #[Test]
    public function unknownProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->selectProvider('main', 'ollama', 1);
    }

    #[Test]
    public function sitesAreIsolated(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);
        $this->manager->save('main', self::ANTHROPIC_KEY);
        $this->manager->savePrivacy('main', false, false);

        $other = $this->resolver->status('other');
        self::assertFalse($other['configured']);
        self::assertFalse($other['providerChosen']);
        self::assertTrue($other['allowImageInput']);
        self::assertTrue($other['allowPageTitle']);
        $main = $this->resolver->status('main');
        self::assertFalse($main['allowImageInput']);
        self::assertFalse($main['allowPageTitle']);
    }

    #[Test]
    public function modelOfTheOtherProviderCannotBeSelected(): void
    {
        $this->manager->selectProvider('main', 'anthropic', 1);
        $this->manager->save('main', self::ANTHROPIC_KEY);
        $this->discover('main', 'anthropic', self::ANTHROPIC_KEY, '');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->selectModel('main', 'gpt-5.4-mini');
    }

    private function assertRefused(int $code): void
    {
        try {
            $this->resolver->resolve('main');
            self::fail('resolve() must refuse with ' . $code);
        } catch (AiConfigurationException $exception) {
            self::assertSame($code, $exception->getCode());
        }
    }

    private function discover(string $site, string $provider, string $key, string $selectedModelId): void
    {
        $supported = $provider === 'anthropic'
            ? [['id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5'], ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5']]
            : [['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini']];
        $this->configurations->saveDiscovery(
            $site,
            (new AiModelCacheCodec())->encode($supported, [], $this->fingerprints->fingerprint($key, $provider), $provider),
            time(),
            $selectedModelId,
            $provider,
        );
    }

    private function verify(string $site, string $provider, string $key, string $model): void
    {
        $this->configurations->markTested(
            $site,
            true,
            $this->fingerprints->fingerprint($key, $provider),
            $model,
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
            '',
            $provider,
        );
    }
}
