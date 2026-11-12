<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

/**
 * The plain rule name of a queue item or plan step, as Page Detail shows it: the rule's own title
 * (`rule.<id>.title`, else its plain-language group title) in the backend language. The generic presentation
 * title only follows when the rule has no translated title, so English never shows a humanised rule id such as
 * "File reference alt" (Atlas audit F-11).
 */
final class RemediationRuleTitle
{
    /** @param string $fallback the stored finding message, used only for a rule without any translated title */
    public static function plain(RuleMetadataPresentationService $ruleMetadata, string $ruleId, string $language, string $fallback = ''): string
    {
        $title = self::localized($ruleMetadata, $ruleId, $language);
        if ($title !== '') {
            return $title;
        }
        $title = trim((string)($ruleMetadata->present(['rule_id' => $ruleId, 'help' => $fallback], $language)['title'] ?? ''));

        return $title !== '' ? $title : $ruleId;
    }

    /** The translated rule title only ('' when the rule has none). */
    public static function localized(RuleMetadataPresentationService $ruleMetadata, string $ruleId, string $language): string
    {
        return $ruleId === '' ? '' : trim((string)($ruleMetadata->present(['rule_id' => $ruleId], $language)['localizedTitle'] ?? ''));
    }
}
