<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwnerRuleValidator;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow\InMemoryRemediationItemRepository;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-08 (Atlas 07·E): the Overview's "What to fix first" gets an owner tag per issue type only from real assignments the
 * viewer may read, with the shipped owner labels, and an owner selector (in the closed Details) only for people who
 * may edit the site root and only for rules of a stored paid frontend scan. Nothing for Free; no AI tag (no frontend
 * rule has an AI flow). The tag never changes the order, rank or link of a fix.
 */
final class OverviewPriorityFixOwnerTagsTest extends TestCase
{
    private const PANEL = __DIR__ . '/../../../Resources/Private/Partials/Overview/RemotePanel.html';
    private const CONTROLLER = __DIR__ . '/../../../Classes/Controller/OverviewController.php';
    private const ROOT = 1;

    #[Test]
    public function assignedOwnersBecomeTagsWithTheShippedOwnerLabels(): void
    {
        $tags = $this->tags(false, canEditRoot: false);

        self::assertSame('developer', $tags['image-alt']['assignedOwner']);
        self::assertSame('Developer (label)', $tags['image-alt']['assignedOwnerLabel']);
        self::assertSame(['varies', 'Varies by page (label)'], [$tags['label']['assignedOwner'], $tags['label']['assignedOwnerLabel']]);
        self::assertArrayNotHasKey('color-contrast', $tags, 'Assigned only on a page outside the web mounts.');
        self::assertArrayNotHasKey('heading-order', $tags, 'No assignment: no tag, not a suggested owner.');
        self::assertFalse($tags['image-alt']['ownerEditable'], 'Without edit access to the site root: the tag only.');
        self::assertSame(0, $tags['image-alt']['ownerPageUid']);
    }

    #[Test]
    public function siteRootEditorsGetTheSelectorForStoredFrontendRulesOnly(): void
    {
        $tags = $this->tags(false, canEditRoot: true);

        // Stored in a paid frontend scan of the site: the selector, preset to the root assignment.
        self::assertTrue($tags['image-alt']['ownerEditable']);
        self::assertSame(self::ROOT, $tags['image-alt']['ownerPageUid']);
        self::assertSame('developer', $tags['image-alt']['ownerCurrent']);
        // Not yet assigned anywhere: a selector (preset to Unassigned) but no tag.
        self::assertSame(['assignedOwner' => '', 'assignedOwnerLabel' => '', 'ownerEditable' => true, 'ownerCurrent' => 'unassigned', 'ownerPageUid' => self::ROOT], $tags['heading-order']);
        // Not stored by any paid scan of the site (the route would refuse it): no selector.
        self::assertFalse($tags['label']['ownerEditable']);
    }

    #[Test]
    public function aFreeLicenceOrAFreeResultShowsNoOwnerAndNoSelector(): void
    {
        self::assertSame([], $this->tags(true, canEditRoot: true));
    }

    #[Test]
    public function withoutTheLicenceThereIsNoOwnerAndNoSelector(): void
    {
        self::assertSame([], $this->tags(false, canEditRoot: true, licensed: false));
    }

    #[Test]
    public function theTagIsAddedToTheFixWithoutChangingOrderRankOrLink(): void
    {
        $controller = (string)file_get_contents(self::CONTROLLER);
        // Both lists get the same data, appended after the existing keys (array union never overwrites them).
        self::assertStringContainsString("] + (\$priorityFixOwners[(string)(\$fix['ruleId'] ?? '')] ?? self::NO_PRIORITY_FIX_OWNER),", $controller);
        self::assertStringContainsString("\$isFreePreview || (is_array(\$remoteScan) && (bool)(\$remoteScan['is_free_preview'] ?? false))", $controller);

        $panel = (string)file_get_contents(self::PANEL);
        $item = substr($panel, (int)strpos($panel, '<f:section name="PriorityFix">'));
        $mainStart = (int)strpos($item, '<div class="aqg-fix__main">');
        $main = substr($item, $mainStart, (int)strpos($item, '<div class="aqg-fix__actions">') - $mainStart);
        $details = substr($item, (int)strpos($item, '<div class="aqg-fix__details'));
        // Visible next to the one short fix (Atlas 07·E), only when an owner is assigned.
        self::assertStringContainsString('<f:if condition="{fix.assignedOwner}">', $main);
        self::assertStringContainsString('{fix.assignedOwnerLabel}', $main);
        self::assertStringContainsString('overview.fixFirst.tag.ownerPrefix', $main);
        // The selector is no always-visible control: it sits in the closed Details, with a label for its id.
        self::assertStringNotContainsString('data-aqg-fix-owner-form', $main);
        self::assertStringContainsString('<f:if condition="{fix.ownerEditable}">', $details);
        self::assertStringContainsString('<label class="aqg-fix__owner-label" for="aqg-fix-owner-{fix.ruleId}">', $details);
        self::assertStringContainsString('id="aqg-fix-owner-{fix.ruleId}"', $details);
        // The suggested owner stays in the closed Details; the list has no AI tag.
        self::assertStringNotContainsString('fix.whoShouldFix', $main);
        foreach (['fix.ai', 'aiAvailable', 'aqg-wand', 'fixFirst.tag.ai'] as $ai) {
            self::assertStringNotContainsString($ai, $panel);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function tags(bool $isFree, bool $canEditRoot, bool $licensed = true): array
    {
        $items = new InMemoryRemediationItemRepository();
        $items->saveOwnerAssignment('main', self::ROOT, 'image-alt', 'developer', 1);
        $items->saveOwnerAssignment('main', 3, 'label', 'editor', 1);
        $items->saveOwnerAssignment('main', 4, 'label', 'developer', 1);
        $items->saveOwnerAssignment('main', 99, 'color-contrast', 'editor', 1);

        $access = $this->createMock(RemediationAccessService::class);
        $access->method('hasLicence')->willReturn($licensed);
        $access->method('canReadSiteIdentifier')->willReturn(true);
        $access->method('canReadPage')->willReturnCallback(static fn (int $pageUid): bool => $pageUid !== 99);
        $access->method('canEditPage')->willReturnCallback(static fn (int $pageUid): bool => $canEditRoot && $pageUid === self::ROOT);

        $rules = $this->createMock(RemediationOwnerRuleValidator::class);
        $rules->method('storedFrontendRuleIds')->willReturnCallback(
            static fn (string $site, array $ruleIds): array => array_values(array_intersect($ruleIds, ['image-alt', 'heading-order']))
        );

        $labels = $this->createMock(BackendContextService::class);
        $labels->method('translate')->willReturnCallback(static fn (string $key): string => match ($key) {
            'remediation.owner.developer' => 'Developer (label)',
            'overview.fixFirst.tag.ownerVaries' => 'Varies by page (label)',
            default => $key,
        });

        $controller = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        foreach (['remediationItems' => $items, 'remediationAccess' => $access, 'remediationOwnerRules' => $rules, 'backendContextService' => $labels] as $property => $value) {
            (new \ReflectionProperty($controller, $property))->setValue($controller, $value);
        }
        $fixes = [
            ['rank' => 1, 'ruleId' => 'image-alt'],
            ['rank' => 2, 'ruleId' => 'label'],
            ['rank' => 3, 'ruleId' => 'color-contrast'],
            ['rank' => 4, 'ruleId' => 'heading-order'],
        ];

        return (new ReflectionMethod(OverviewController::class, 'priorityFixOwnerTags'))->invoke($controller, 'main', self::ROOT, $fixes, $isFree);
    }
}
