<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

/**
 * A stable HTML id for a rule's section on the frontend page detail, so a link can open that rule.
 * Rule identifiers may contain characters that are awkward in fragments; the id is derived from them.
 */
final class RuleAnchorUtility
{
    public static function anchorId(string $ruleId): string
    {
        return 'aqg-rule-' . substr(md5(strtolower(trim($ruleId))), 0, 12);
    }
}
