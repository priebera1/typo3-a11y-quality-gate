<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationManager;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Monthly budget settings (UX Atlas 01 E "Limits"). Open owner decision 01#4 is not decided: AQG has no default amount,
 * no default warning threshold and no preselected hard-stop choice.
 */
final class AiBudgetSettingsTest extends TestCase
{
    private InMemoryAiSiteSettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryAiSiteSettingRepository();
    }

    #[Test]
    public function aBudgetIsStoredWithTheExplicitHardStopChoice(): void
    {
        $this->manager()->saveBudget('main', '50', '80', '1', 4);

        self::assertSame(50_000_000, $this->settings->rows['main']['budget_micro_usd']);
        self::assertSame(80, $this->settings->rows['main']['budget_warning_percent']);
        self::assertSame(1, $this->settings->rows['main']['budget_hard_stop']);
        self::assertSame(4, $this->settings->rows['main']['budget_changed_by']);

        $this->manager()->saveBudget('main', '20.50', '', '0', 4);
        self::assertSame(20_500_000, $this->settings->rows['main']['budget_micro_usd']);
        self::assertSame(0, $this->settings->rows['main']['budget_warning_percent'], 'No warning threshold is prefilled.');
        self::assertSame(0, $this->settings->rows['main']['budget_hard_stop']);
    }

    #[Test]
    public function aBudgetNeedsAnExplicitChoiceAndAtLeastOneDollarButNoPriceEntry(): void
    {
        foreach ([
            'hard_stop_required' => ['50', '80', ''],
            'hard_stop_required ' => ['50', '80', 'yes'],
            'invalid_budget' => ['0.99', '80', '1'],
            'invalid_budget ' => ['abc', '', '1'],
            'invalid_warning_percent' => ['50', '0', '1'],
            'invalid_warning_percent ' => ['50', '101', '1'],
        ] as $code => [$budget, $warning, $hardStop]) {
            try {
                $this->manager()->saveBudget('main', $budget, $warning, $hardStop, 1);
                self::fail('Accepted: ' . $code);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(trim($code), $exception->getMessage());
            }
        }

        self::assertSame([], $this->settings->rows);

        // Prices are automatic: a budget is saved even before a model (and so a price) is chosen; a hard stop then
        // refuses requests it cannot bound instead of letting them through (AiBudgetService).
        $this->manager(priceKnown: false)->saveBudget('main', '50', '80', '1', 1);
        self::assertSame(50_000_000, $this->settings->rows['main']['budget_micro_usd']);
    }

    #[Test]
    public function anEmptyBudgetRemovesItEvenWithoutAPrice(): void
    {
        $this->manager()->saveBudget('main', '50', '80', '1', 1);
        $this->manager(priceKnown: false)->saveBudget('main', '', '80', '', 1);

        self::assertSame(0, $this->settings->rows['main']['budget_micro_usd']);
        self::assertSame(0, $this->settings->rows['main']['budget_hard_stop']);
    }

    #[Test]
    public function theBudgetAlertAddressIsValidatedBeforeAnythingIsStored(): void
    {
        try {
            $this->manager()->saveBudget('main', '50', '80', '1', 4, 'not-an-address');
            self::fail('An invalid alert address must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('invalid_alert_email', $exception->getMessage());
        }
        self::assertArrayNotHasKey('main', $this->settings->rows, 'Nothing is half-saved.');

        $this->manager()->saveBudget('main', '50', '80', '1', 4, ' alerts@example.com ');
        self::assertSame('alerts@example.com', $this->settings->rows['main']['budget_alert_email']);
        self::assertSame(50_000_000, $this->settings->rows['main']['budget_micro_usd']);

        // A form without the field (null) keeps the address; removing the budget keeps it too; '' removes it.
        $this->manager()->saveBudget('main', '60', '80', '1', 4);
        self::assertSame('alerts@example.com', $this->settings->rows['main']['budget_alert_email']);
        $this->manager()->saveBudget('main', '', '', '', 4, 'alerts@example.com');
        self::assertSame('alerts@example.com', $this->settings->rows['main']['budget_alert_email']);
        $this->manager()->saveBudget('main', '60', '80', '1', 4, '');
        self::assertSame('', $this->settings->rows['main']['budget_alert_email']);
    }

    private function manager(bool $priceKnown = true): AiConfigurationManager
    {
        $configurations = $this->createMock(AiConfigurationRepositoryInterface::class);
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteByIdentifier')->willReturn($this->createMock(Site::class));
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn(['budget' => ['priceKnown' => $priceKnown]]);

        return new AiConfigurationManager(
            $configurations,
            $this->createMock(SecretEncryptionServiceInterface::class),
            $sites,
            $resolver,
            new AiModelCompatibilityRegistry(),
            new AiModelCacheCodec(),
            new AiSiteSettingsService($this->settings, $configurations),
            $this->settings,
        );
    }
}
