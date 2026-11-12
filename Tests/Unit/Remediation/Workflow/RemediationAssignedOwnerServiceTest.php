<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService;

/**
 * F-08: the Overview's "What to fix first" shows an owner only for a real assignment on a page this person may open,
 * never AQG's suggested owner, never across sites, and only with the PRO/Agency licence.
 */
final class RemediationAssignedOwnerServiceTest extends TestCase
{
    private InMemoryRemediationItemRepository $items;

    protected function setUp(): void
    {
        $this->items = new InMemoryRemediationItemRepository();
        // Page 99 is outside this person's web mounts.
        $this->items->saveOwnerAssignment('main', 3, 'image-alt', 'developer', 1);
        $this->items->saveOwnerAssignment('main', 4, 'image-alt', 'developer', 1);
        $this->items->saveOwnerAssignment('main', 3, 'label', 'editor', 1);
        $this->items->saveOwnerAssignment('main', 4, 'label', 'developer', 1);
        $this->items->saveOwnerAssignment('main', 99, 'color-contrast', 'editor', 1);
        $this->items->saveOwnerAssignment('main', 3, 'link-name', 'editor', 1);
        $this->items->saveOwnerAssignment('other', 3, 'link-name', 'developer', 1);
        $this->items->saveOwnerAssignment('main', 3, 'region', 'unassigned', 1);
        $this->items->saveOwnerAssignment('main', 3, 'list', 'tampered', 1);
        $this->items->saveOwnerAssignment('main', 3, 'not-in-the-list', 'editor', 1);
    }

    #[Test]
    public function agreeingAssignmentsOnReadablePagesGiveThatOwner(): void
    {
        $owners = $this->service()->forRules('main', ['image-alt', 'label', 'color-contrast', 'link-name', 'region', 'list', 'heading-order']);

        self::assertSame(['owner' => 'developer', 'pages' => 2], $owners['image-alt']);
        self::assertSame(['owner' => 'editor', 'pages' => 1], $owners['link-name'], 'An assignment of another site never counts.');
        self::assertArrayNotHasKey('region', $owners, '"Unassigned" means nobody owns it: no tag.');
    }

    #[Test]
    public function disagreeingAssignmentsVaryByPageInsteadOfAGuessedMajority(): void
    {
        self::assertSame(['owner' => RemediationAssignedOwnerService::VARIES, 'pages' => 2], $this->service()->forRules('main', ['label'])['label']);
    }

    #[Test]
    public function anUnassignedPageDoesNotTurnAnOwnerIntoVaries(): void
    {
        $this->items->saveOwnerAssignment('main', 4, 'region', 'developer', 1);

        self::assertSame(['owner' => 'developer', 'pages' => 1], $this->service()->forRules('main', ['region'])['region']);
    }

    #[Test]
    public function rulesWithoutAReadableValidAssignmentHaveNoOwner(): void
    {
        $owners = $this->service()->forRules('main', ['color-contrast', 'list', 'heading-order']);

        self::assertSame([], $owners, 'Unreadable page (scope), invalid stored value and no assignment all show nothing.');
    }

    #[Test]
    public function anAdministratorWhoMayOpenEveryPageSeesTheOtherwiseHiddenAssignment(): void
    {
        $owners = $this->service(readable: static fn (int $pageUid): bool => true)->forRules('main', ['color-contrast']);

        self::assertSame(['owner' => 'editor', 'pages' => 1], $owners['color-contrast']);
    }

    #[Test]
    public function onlyTheRulesOfTheListAreRead(): void
    {
        self::assertArrayNotHasKey('not-in-the-list', $this->service()->forRules('main', ['image-alt']));
    }

    #[Test]
    public function withoutTheLicenceOrSiteAccessThereIsNoOwner(): void
    {
        self::assertSame([], $this->service(licensed: false)->forRules('main', ['image-alt']));
        self::assertSame([], $this->service(siteReadable: false)->forRules('main', ['image-alt']));
        self::assertSame([], $this->service()->forRules('', ['image-alt']));
        self::assertSame([], $this->service()->forRules('main', []));
    }

    private function service(bool $licensed = true, bool $siteReadable = true, ?\Closure $readable = null): RemediationAssignedOwnerService
    {
        $access = $this->createMock(RemediationAccessService::class);
        $access->method('hasLicence')->willReturn($licensed);
        $access->method('canReadSiteIdentifier')->willReturn($siteReadable);
        $access->method('canReadPage')->willReturnCallback($readable ?? static fn (int $pageUid): bool => $pageUid !== 99);

        return new RemediationAssignedOwnerService($this->items, $access);
    }
}
