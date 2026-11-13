<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The three values of a conflict (Atlas 08·B): the value the suggestion was made for (base), the value the target holds
 * now (saved now) and the suggestion. Read-only: nothing here writes, claims or moves the item, and the caller has
 * already checked that the person may see the item. The current value is read the way AQG located the target when
 * the suggestion was made; when that is no longer possible it stays unknown and the view says so, never guesses.
 */
final class RemediationConflictView
{
    public function __construct(
        private readonly RemediationTargetResolver $targetResolver,
        private readonly IssueRemediationRepositoryInterface $issues,
    ) {}

    /**
     * @param array<string,mixed> $item a tx_a11y_remediation_item row the person may see
     * @return array{base:string,current:string,currentKnown:bool,currentDecorative:bool,proposed:string,reason:string}
     */
    public function compare(array $item): array
    {
        $view = [
            'base' => (string)($item['original_value'] ?? ''),
            'current' => '',
            'currentKnown' => false,
            'currentDecorative' => false,
            'proposed' => RemediationItemData::finalValue($item),
            'reason' => (string)($item['status_reason'] ?? ''),
        ];
        try {
            $kind = (string)($item['target_kind'] ?? '');
            if ($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
                // The image use in the person's workspace (items are only visible in their own workspace).
                $reference = BackendUtility::getRecordWSOL(Tables::SYS_FILE_REFERENCE, (int)$item['target_uid']);
                if (is_array($reference) && (int)($reference['deleted'] ?? 0) === 0) {
                    $view['current'] = (string)($reference['alternative'] ?? '');
                    $view['currentDecorative'] = (int)($reference['tx_a11y_is_decorative'] ?? 0) === 1;
                    $view['currentKnown'] = true;
                }
            } elseif ($kind === RemediationTargetKind::RICH_TEXT) {
                // The element as the stored finding still locates it; a finding that no longer matches leaves it unknown.
                $issue = $this->issues->findByUid((int)$item['finding_uid']);
                $target = is_array($issue) ? $this->targetResolver->resolve($issue) : null;
                if ($target !== null && $target->isAvailable()) {
                    $view['current'] = $target->currentValue;
                    $view['currentKnown'] = true;
                }
            }
        } catch (\Throwable) {
            // Unknown, as above: the view offers the record instead of a value.
            $view['current'] = '';
            $view['currentKnown'] = false;
        }

        return $view;
    }
}
