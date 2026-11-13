<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;

/**
 * The assigned owner of whole issue types across a site, for the Overview's "What to fix first" (Atlas 07·E).
 *
 * Only real assignments count (tx_a11y_remediation_owner, written by "Change owner" with edit access to the page):
 * never AQG's suggested owner, which stays in the closed Details as "Suggested owner". An assignment is read only on
 * pages this person may open (ScopeAccessService via RemediationAccessService::canReadPage()), so an owner set on a
 * page outside the viewer's web mounts never shows. The Overview assigns frontend rules on the site root page; content
 * Page Detail assigns per page. Agreeing assignments give that owner; disagreeing ones give VARIES ("varies by
 * page"), never a guessed majority; "Unassigned" counts as no owner. Like the rest of the remediation workflow this needs the
 * PRO/Agency licence of the site (not Trial, not Free).
 */
final class RemediationAssignedOwnerService
{
    public const VARIES = 'varies';

    public function __construct(
        private readonly RemediationItemRepositoryInterface $items,
        private readonly RemediationAccessService $access,
    ) {}

    /**
     * @param list<string> $ruleIds
     * @return array<string,array{owner:string,pages:int}> rule id => assigned owner (or VARIES) and the number of
     *         readable pages with an assignment; rules without a readable assignment are absent
     */
    public function forRules(string $siteIdentifier, array $ruleIds): array
    {
        $ruleIds = array_values(array_unique(array_filter($ruleIds, static fn (mixed $ruleId): bool => is_string($ruleId) && $ruleId !== '')));
        if ($siteIdentifier === '' || $ruleIds === []
            || !$this->access->hasLicence($siteIdentifier) || !$this->access->canReadSiteIdentifier($siteIdentifier)) {
            return [];
        }

        /** @var array<string,array<string,int>> $counts */
        $counts = [];
        foreach ($this->items->findOwnerAssignmentsForRules($siteIdentifier, $ruleIds) as $row) {
            $ruleId = (string)$row['rule_id'];
            $owner = (string)$row['owner'];
            // "Unassigned" is a decision that nobody owns it yet: it shows no tag (and clears the root assignment's tag).
            if (!in_array($ruleId, $ruleIds, true) || !RemediationOwner::isValid($owner) || $owner === RemediationOwner::UNASSIGNED
                || !$this->access->canReadPage((int)$row['page_uid'])) {
                continue;
            }
            $counts[$ruleId][$owner] = ($counts[$ruleId][$owner] ?? 0) + 1;
        }

        $owners = [];
        foreach ($counts as $ruleId => $perOwner) {
            $owners[$ruleId] = [
                'owner' => count($perOwner) === 1 ? (string)array_key_first($perOwner) : self::VARIES,
                'pages' => array_sum($perOwner),
            ];
        }

        return $owners;
    }
}
