<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;

final class LocalIssueGuidanceServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('localRuleProvider')]
    public function localFindingWithMetadataExposesGuidanceBadgesAndImpact(string $ruleId): void
    {
        $subject = $this->createSubject([
            $ruleId => [
                'title' => 'Accessible control name',
                'whyItMatters' => 'This blocks a user from understanding the control.',
                'howToFix' => 'Give the control a descriptive accessible name.',
                'owner' => 'editor',
                'ownerLabel' => 'Editor',
                'fixType' => 'content',
                'fixTypeLabel' => 'Content',
                'wcagPrimaryLabel' => 'WCAG 1.1.1 Non-text Content - Level A',
                'wcagCompactLabel' => 'WCAG 1.1.1 A',
                'affectedUserItems' => [
                    ['key' => 'blind', 'label' => 'Blind users'],
                    ['key' => 'keyboard', 'label' => 'Keyboard users'],
                ],
                'wcagReferences' => [
                    ['criterion' => '1.1.1', 'level' => 'A', 'name' => 'Non-text Content', 'label' => 'WCAG 1.1.1 Non-text Content - Level A'],
                ],
                'standards' => ['WCAG 2.2 A'],
                'technicalTags' => ['wcag111'],
            ],
        ]);

        $result = $subject->present([
            'rule_id' => $ruleId,
            'hint' => 'Fallback hint must not replace metadata guidance.',
        ]);

        self::assertTrue($result['hasAny']);
        self::assertTrue($result['hasBadges']);
        self::assertTrue($result['hasGuidanceText']);
        self::assertTrue($result['hasStandardsAndImpact']);
        self::assertSame('editor', $result['owner']);
        self::assertSame('Editor', $result['ownerLabel']);
        self::assertSame('content', $result['fixType']);
        self::assertSame('Content', $result['fixTypeLabel']);
        self::assertSame('WCAG 1.1.1 Non-text Content - Level A', $result['wcagPrimaryLabel']);
        self::assertSame('This blocks a user from understanding the control.', $result['whyItMatters']);
        self::assertSame('Give the control a descriptive accessible name.', $result['howToFix']);
        self::assertFalse($result['howToFixIsFallback']);
        self::assertNotEmpty($result['affectedUserItems']);
        self::assertSame(['WCAG 2.2 A'], $result['standards']);
        self::assertSame(['wcag111'], $result['technicalTags']);
    }

    /** @return iterable<string, array{0:string}> */
    public static function localRuleProvider(): iterable
    {
        yield 'structured finding' => ['structured.file_reference_alt_quality'];
        yield 'rte finding' => ['rte.non_descriptive_link'];
        yield 'rendered finding' => ['rendered.empty_button'];
    }

    #[Test]
    public function findingWithoutMetadataUsesHintAsHowToFixFallbackOnly(): void
    {
        $subject = $this->createSubject();
        $result = $subject->present([
            'rule_id' => 'custom_extension.unknown_rule_without_metadata',
            'hint' => 'Use the existing local hint as fallback guidance.',
        ]);

        self::assertTrue($result['hasAny']);
        self::assertFalse($result['hasBadges']);
        self::assertTrue($result['hasGuidanceText']);
        self::assertFalse($result['hasStandardsAndImpact']);
        self::assertSame('', $result['owner']);
        self::assertSame('', $result['fixType']);
        self::assertSame('', $result['wcagPrimaryLabel']);
        self::assertSame('', $result['whyItMatters']);
        self::assertSame('Use the existing local hint as fallback guidance.', $result['howToFix']);
        self::assertTrue($result['howToFixIsFallback']);
        self::assertSame([], $result['affectedUserItems']);
        self::assertSame([], $result['wcagReferences']);
    }

    #[Test]
    public function affectedUsersAloneDoNotOpenEmptyStandardsAndImpactDetails(): void
    {
        $subject = $this->createSubject([
            'rte.non_descriptive_link' => [
                'affectedUserItems' => [
                    ['key' => 'screen-reader', 'label' => 'Screen reader users'],
                ],
            ],
        ]);

        $result = $subject->present([
            'rule_id' => 'rte.non_descriptive_link',
        ]);

        self::assertTrue($result['hasAny']);
        self::assertTrue($result['hasAffectedUsers']);
        self::assertFalse($result['hasStandardsAndImpact']);
        self::assertFalse($result['hasGuidanceText']);
        self::assertFalse($result['hasBadges']);
    }

    #[Test]
    public function standardsAndImpactOnlyOpensForMetadataRenderedInsideDetails(): void
    {
        $subject = $this->createSubject([
            'rendered.empty_button' => [
                'wcagReferences' => [
                    ['label' => 'WCAG 4.1.2 Name, Role, Value - Level A'],
                ],
            ],
        ]);

        $result = $subject->present([
            'rule_id' => 'rendered.empty_button',
        ]);

        self::assertTrue($result['hasAny']);
        self::assertFalse($result['hasAffectedUsers']);
        self::assertTrue($result['hasStandardsAndImpact']);
        self::assertTrue($result['hasWcagReferences']);
    }

    /** @param array<string, array<string, mixed>> $presentations */
    private function createSubject(array $presentations = []): LocalIssueGuidanceService
    {
        return new LocalIssueGuidanceService(
            new FakeRuleMetadataPresentationService($presentations)
        );
    }
}

final class FakeRuleMetadataPresentationService implements RuleMetadataPresentationServiceInterface
{
    /** @param array<string, array<string, mixed>> $presentations */
    public function __construct(
        private readonly array $presentations,
    ) {}

    public function present(array $issue, string $language = 'en'): array
    {
        return $this->presentations[(string)($issue['rule_id'] ?? '')] ?? [];
    }
}
