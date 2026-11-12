<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Service;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;

/**
 * Atlas audit F-16 / F-27: the AI tab formats dates, counts and amounts in the backend user's language, a cost below
 * one cent carries one qualifier, and the usage card lists every person with usage (3.0.0 usage report).
 */
final class AiSettingsUiStateBuilderLocaleTest extends TestCase
{
    private const DEC_1_2026 = 1796079600; // 2026-12-01 00:00 Europe/Berlin
    private const NOV_2_2026_1939 = 1793644740; // 2026-11-02 19:39 Europe/Berlin

    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function germanLabelsUseGermanDatesNumbersAndAmounts(): void
    {
        $state = $this->builder()->build('main', new BackendLocaleFormatter('de'));

        self::assertSame('01.12.2026', $state['budget']['resumeDate']);
        self::assertSame('November 2026', $state['budget']['monthLabel']);
        self::assertSame("25,00\u{00A0}$", $state['budget']['budgetLabel']);
        self::assertSame("< 0,01\u{00A0}$", $state['budget']['committedLabel']);
        self::assertSame('02.11.2026', $state['acknowledgement']['atDate']);
        self::assertSame('02.11.2026, 19:39', $state['lastVerifiedLabel']);
        self::assertSame('.', $state['thousandsSeparator']);
        self::assertSame("1.250,00\u{00A0}$", $state['usage']['totals']['costLabel']);
        self::assertFalse($state['usage']['totals']['costIsBound']);
        self::assertStringNotContainsString('-', $state['usage']['periodStartDate'], 'Not the ISO date "2026-11-01".');
    }

    #[Test]
    public function englishKeepsItsLabelsAndACentBoundHasNoSecondQualifier(): void
    {
        $state = $this->builder()->build('main', new BackendLocaleFormatter('en'));

        self::assertSame('1 Dec 2026', $state['budget']['resumeDate']);
        self::assertSame('November 2026', $state['budget']['monthLabel']);
        self::assertSame('$25.00', $state['budget']['budgetLabel']);
        self::assertSame('2 Nov 2026', $state['acknowledgement']['atDate']);
        self::assertSame(',', $state['thousandsSeparator']);
        self::assertSame('$1,250.00', $state['usage']['totals']['costLabel']);
        // The person below one cent: the label is already a bound ("< $0.01"), so the template adds no "≈".
        $eva = $state['usage']['people'][3];
        self::assertSame(2, $eva['beUser']);
        self::assertSame('< $0.01', $eva['costLabel']);
        self::assertTrue($eva['costIsBound']);
    }

    #[Test]
    public function everyPersonIsListedByExposureWithAccountStateAndFeatures(): void
    {
        $state = $this->builder()->build('main', new BackendLocaleFormatter('en'));
        $people = $state['usage']['people'];

        // No "N other people" row any more: every backend user with usage is a row, largest estimated + reserved first.
        self::assertSame([1, 3, 4, 2, 5, 0, 6], array_column($people, 'beUser'));
        self::assertSame(['Ada Admin', '#3', 'Old Editor', 'Eva Editor', 'paused', '', 'conn'], array_column($people, 'name'));
        self::assertSame(['active', 'missing', 'deleted', 'active', 'disabled', 'none', 'active'], array_column($people, 'accountState'));
        self::assertSame([true, false, true, true, true, false, true], array_column($people, 'showId'), 'No "#3 · #3" for an account without a record.');
        self::assertSame('olded', $people[2]['username']);
        self::assertSame('', $people[0]['username'], 'The username is hidden when it equals the display name.');
        self::assertSame([['feature' => 'alt_text', 'requests' => 1]], $people[0]['featuresShown']);
        self::assertTrue($people[6]['connectionTestsOnly']);
        self::assertSame(7, $state['usage']['peopleCount']);
        self::assertSame('$12.50', $people[1]['estimatedLabel']);
    }

    private function builder(): AiSettingsUiStateBuilder
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn([
            'configured' => true,
            'selectedModelAvailable' => true,
            'connectionStatus' => 'connected',
            'lastVerifiedAt' => self::NOV_2_2026_1939,
            'acknowledgement' => ['confirmed' => true, 'provider' => 'openai', 'by' => 1, 'at' => self::NOV_2_2026_1939],
            'budget' => ['state' => 'under', 'budgetMicroUsd' => 25_000_000, 'spentMicroUsd' => 4_000, 'reservedMicroUsd' => 0, 'periodEnd' => self::DEC_1_2026, 'warningPercent' => 80, 'hardStop' => true],
        ]);
        $repository = new AiUsageRepositoryDouble();
        $row = static fn (int $user, int $cost, string $feature = 'alt_text'): array => [
            'site_identifier' => 'main', 'be_user' => $user, 'feature' => $feature, 'provider' => 'openai', 'model' => 'gpt-5.4-mini',
            'outcome' => 'success', 'input_tokens' => 1, 'output_tokens' => 1, 'usage_reported' => 1,
            'estimated_cost_micro_usd' => $cost, 'cost_known' => 1, 'cost_basis' => 'reported', 'crdate' => time(),
        ];
        $repository->add($row(2, 4_000));
        $repository->add($row(1, 1_237_393_000));
        $repository->add($row(3, 12_500_000));
        $repository->add($row(4, 100_000));
        $repository->add($row(5, 3_000));
        $repository->add($row(6, 0, 'connection_test'));
        $repository->add($row(0, 0, 'connection_test'));
        $repository->add(['site_identifier' => 'other-site'] + $row(1, 99_000_000));
        $names = $this->createMock(AiBackendUserNames::class);
        $names->method('name')->willReturnCallback(static fn (int $uid): string => [1 => 'Ada Admin', 2 => 'Eva Editor'][$uid] ?? '#' . $uid);
        $names->method('describe')->willReturnCallback(static fn (int $uid): array => [
            1 => ['name' => 'Ada Admin', 'username' => 'Ada Admin', 'state' => 'active'],
            2 => ['name' => 'Eva Editor', 'username' => 'eva', 'state' => 'active'],
            3 => ['name' => '#3', 'username' => '', 'state' => 'missing'],
            4 => ['name' => 'Old Editor', 'username' => 'olded', 'state' => 'deleted'],
            5 => ['name' => 'paused', 'username' => 'paused', 'state' => 'disabled'],
            6 => ['name' => 'conn', 'username' => 'conn', 'state' => 'active'],
        ][$uid]);

        return new AiSettingsUiStateBuilder(
            $resolver,
            new AiUsageSummaryService($repository, new AiModelCompatibilityRegistry()),
            null,
            null,
            $names,
        );
    }
}
