<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiConfigurationRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiSiteSettingRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * AQG 3.0 storage: one configuration row per site and provider, site settings that carry the 2.x state forward, and
 * usage rows without content.
 */
final class AiProviderStorageTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function eachProviderKeepsItsOwnRowKeyAndVerification(): void
    {
        $subject = $this->get(AiConfigurationRepository::class);
        $subject->saveKey('main', 'encrypted-openai', 'sk-proj…1111', true);
        $subject->markTested('main', true, str_repeat('a', 64), 'gpt-5.4-mini', 'p', 'c');
        $subject->saveKey('main', 'encrypted-anthropic', 'sk-ant-…2222', true, 'anthropic');

        $openAi = $subject->findBySiteIdentifier('main');
        $anthropic = $subject->findBySiteIdentifier('main', 'anthropic');
        self::assertSame('encrypted-openai', $openAi['encrypted_api_key']);
        self::assertSame('gpt-5.4-mini', $openAi['verified_model_id'], 'Saving the Anthropic key leaves OpenAI untouched.');
        self::assertSame('encrypted-anthropic', $anthropic['encrypted_api_key']);
        self::assertSame('anthropic', $anthropic['provider']);
        self::assertNotSame((int)$openAi['uid'], (int)$anthropic['uid']);
    }

    #[Test]
    public function resetVerificationKeepsKeyAndModelAndRequiresRevalidation(): void
    {
        $subject = $this->get(AiConfigurationRepository::class);
        $subject->saveKey('main', 'encrypted-openai', 'sk-proj…1111', true);
        $subject->selectModel('main', 'gpt-5.4-mini');
        $subject->markTested('main', true, str_repeat('a', 64), 'gpt-5.4-mini', 'p', 'c');

        $subject->resetVerification('main', 'openai');
        $row = $subject->findBySiteIdentifier('main');
        self::assertSame('encrypted-openai', $row['encrypted_api_key']);
        self::assertSame('gpt-5.4-mini', $row['selected_model_id']);
        self::assertSame(0, (int)$row['last_verified_at']);
        self::assertSame(1, (int)$row['revalidation_required']);

        $subject->markTested('main', true, str_repeat('a', 64), 'gpt-5.4-mini', 'p', 'c');
        self::assertSame(0, (int)$subject->findBySiteIdentifier('main')['revalidation_required']);
    }

    #[Test]
    public function firstSiteSettingCarriesTheOpenAiStateOfA2xSiteForward(): void
    {
        $configurations = $this->get(AiConfigurationRepository::class);
        $configurations->saveKey('main', 'encrypted-openai', 'sk-proj…1111', true);
        $configurations->setLinkTextSuggestionsEnabled('main', true);
        $siteSettings = $this->get(AiSiteSettingRepository::class);

        $row = $siteSettings->findBySiteIdentifier('main');
        self::assertSame('openai', $row['active_provider']);
        self::assertSame(1, (int)$row['link_text_suggestions_enabled']);
        self::assertTrue($configurations->isLinkTextSuggestionsEnabled('main'));

        $siteSettings->savePrivacy('other', false, true);
        $other = $siteSettings->findBySiteIdentifier('other');
        self::assertSame('', $other['active_provider'], 'A site without an OpenAI configuration has no provider until one is chosen.');
        self::assertSame(0, (int)$other['allow_image_input']);
        self::assertFalse($configurations->isLinkTextSuggestionsEnabled('other'));
    }

    #[Test]
    public function legacyLinkTextSwitchIsReadUntilASiteSettingExists(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::AI_CONFIGURATION)->insert(Tables::AI_CONFIGURATION, [
            'site_identifier' => 'legacy',
            'provider' => 'openai',
            'encrypted_api_key' => 'x',
            'link_text_suggestions_enabled' => 1,
        ]);

        self::assertTrue($this->get(AiConfigurationRepository::class)->isLinkTextSuggestionsEnabled('legacy'));
        self::assertNull($this->get(AiSiteSettingRepository::class)->findBySiteIdentifier('legacy'));
    }

    #[Test]
    public function usageIsSummarizedPerFeatureProviderAndModelWithinThePeriod(): void
    {
        $usage = $this->get(AiUsageRepository::class);
        $row = static fn (string $site, string $feature, string $outcome, int $in, int $out, int $reported, int $time): array => [
            'site_identifier' => $site, 'be_user' => 3, 'feature' => $feature, 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5',
            'outcome' => $outcome, 'input_tokens' => $in, 'output_tokens' => $out, 'usage_reported' => $reported, 'crdate' => $time,
        ];
        $usage->add($row('main', 'alt_text', 'success', 100, 10, 1, 1_000));
        $usage->add($row('main', 'alt_text', 'failed_1771002324', 0, 0, 0, 1_500));
        $usage->add($row('main', 'connection_test', 'success', 300, 5, 1, 1_900));
        $usage->add($row('main', 'alt_text', 'success', 999, 99, 1, 2_000));
        $usage->add($row('other', 'alt_text', 'success', 999, 99, 1, 1_200));

        $summary = $usage->summarize('main', 1_000, 2_000);
        $byFeature = array_column($summary, null, 'feature');

        self::assertCount(2, $summary);
        self::assertSame(2, $byFeature['alt_text']['requests']);
        self::assertSame(1, $byFeature['alt_text']['failed']);
        self::assertSame(100, $byFeature['alt_text']['input_tokens']);
        self::assertSame(1, $byFeature['alt_text']['unreported']);
        self::assertSame(300, $byFeature['connection_test']['input_tokens']);
    }
}
