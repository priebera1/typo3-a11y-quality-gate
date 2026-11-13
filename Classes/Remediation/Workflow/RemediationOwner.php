<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/**
 * Who should fix a finding: a role, never a person and never a severity. AQG suggests it from the rule's own
 * metadata (RuleMetadataPresentationService "owner") and from whether the finding has an editable content target;
 * a person with edit access to the page may assign another one per page and rule.
 */
final class RemediationOwner
{
    public const EDITOR = 'editor';
    public const DEVELOPER = 'developer';
    public const MIXED = 'mixed';
    public const UNASSIGNED = 'unassigned';

    public const ALL = [self::EDITOR, self::DEVELOPER, self::MIXED, self::UNASSIGNED];

    public static function isValid(string $owner): bool
    {
        return in_array($owner, self::ALL, true);
    }

    /**
     * The suggested owner: the rule metadata decides between editor, developer and mixed. Content findings of the
     * content scan (rte.*, structured.*) default to the editor, and a rendered finding without an editable record is
     * template output (developer). Anything AQG cannot tell stays Unassigned rather than guessed.
     */
    public static function suggest(string $metadataOwner, string $ruleId, string $sourceType, bool $hasEditableTarget): string
    {
        $metadataOwner = strtolower(trim($metadataOwner));
        if (in_array($metadataOwner, [self::EDITOR, self::DEVELOPER, self::MIXED], true)) {
            if ($metadataOwner === self::EDITOR && $sourceType === 'rendered' && !$hasEditableTarget) {
                return self::MIXED;
            }

            return $metadataOwner;
        }
        if (in_array($metadataOwner, ['content', 'editorial', 'redaktion'], true)) {
            return self::EDITOR;
        }
        if (in_array($metadataOwner, ['template', 'integrator', 'development', 'entwicklung'], true)) {
            return self::DEVELOPER;
        }
        if (str_starts_with($ruleId, 'rte.') || str_starts_with($ruleId, 'structured.')) {
            return self::EDITOR;
        }
        if ($sourceType === 'rendered' && !$hasEditableTarget) {
            return self::DEVELOPER;
        }

        return self::UNASSIGNED;
    }

    private function __construct()
    {
    }
}
