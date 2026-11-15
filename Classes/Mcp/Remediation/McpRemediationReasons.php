<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Remediation;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Agent-facing explanations of AI Fixes outcomes (live MCP QA 2026-11-14: agents got bare codes such as
 * `no_safe_action`, `image_missing` or `no_suggestion` and could not tell a restriction from a defect).
 *
 * Texts are AQG's own English labels (the same `remediation.reason.*` units AI Fixes shows editors), never site content.
 * Nothing here decides eligibility: the codes come from the AI Fixes workflow, which runs every check as the user.
 */
final class McpRemediationReasons
{
    private const LANGUAGE_FILE = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:';

    /** Codes that mean "AI Fixes will not prepare this finding as it is": a person fixes it (or its content) by hand. */
    private const MANUAL = [
        'no_safe_action', 'rendered_output', 'manual_only', 'rule_based_in_editor', 'needs_review', 'no_basis', 'decorative',
        'default_fits', 'target_missing', 'target_changed', 'unsupported_rule',
        'image_missing', 'image_external', 'image_not_in_storage', 'image_not_image', 'image_unreadable', 'image_unavailable',
    ];

    /** Codes only the MCP layer produces (no AI Fixes label). */
    private const MCP_TEXTS = [
        'finding_not_found' => 'No readable content finding with this id on this site.',
        'not_found' => 'No readable content finding with this id on this site.',
        'rendered_output' => 'Found in the rendered page: AQG cannot prove which record field produced it, so AI Fixes never prepares it. Fix it in the template or content by hand.',
        'unsupported_rule' => 'AI Fixes has no safe automatic change for this rule. Follow the guidance.',
        'no_licence' => 'AI Fixes needs an active AQG licence for this site.',
        'permission_denied' => 'This TYPO3 user may not prepare this suggestion (several at once need the AI queue permission; image descriptions need the image permission).',
        'no_permission' => 'This TYPO3 user may not use AI suggestions.',
        'not_configured' => 'AI is not configured for this site.',
        'paused' => 'AI is paused for this site.',
        'in_progress' => 'Someone is preparing this finding right now. Try again in a moment.',
        'no_basis' => 'The content gives the AI no basis for a text (for example an empty heading with nothing after it). A person writes it.',
    ];

    public function __construct(private readonly ?LanguageServiceFactory $languageServiceFactory = null) {}

    public function text(string $code): string
    {
        if ($code === '') {
            return '';
        }
        $label = $this->label('remediation.reason.' . $code);

        return $label !== '' ? $label : (self::MCP_TEXTS[$code] ?? 'Not prepared (' . $code . '). Follow AQG\'s guidance or fix it in TYPO3.');
    }

    public static function needsManualFix(string $code): bool
    {
        return in_array($code, self::MANUAL, true);
    }

    /**
     * What AI Fixes can do for a rule and source type, from AQG's own catalog only (no finding, no user data): the
     * answer for "Can an agent prepare this?" before anything is prepared. A finding can still be skipped for its own
     * reasons (image file missing, no edit access, AI not ready); aqg_get_suggestion_status reports those per finding.
     *
     * @return array{supported: bool, kind: string, reason: string}
     */
    public static function ruleSupport(string $ruleId, string $sourceType): array
    {
        if ($sourceType === 'rendered' || str_starts_with($ruleId, 'rendered.')) {
            return ['supported' => false, 'kind' => '', 'reason' => 'rendered_output'];
        }
        if (in_array($ruleId, ImageFindingContextResolver::SUPPORTED_RULE_IDS, true)) {
            return ['supported' => true, 'kind' => EditorAiRuleCatalog::KIND_IMAGE_ALT, 'reason' => ''];
        }
        if (($sourceType === 'rte' || $sourceType === '') && EditorAiRuleCatalog::supports($ruleId)) {
            $entry = EditorAiRuleCatalog::entry($ruleId);

            return EditorAiRuleCatalog::hasAiFix($ruleId)
                ? ['supported' => true, 'kind' => (string)$entry['kind'], 'reason' => '']
                : ['supported' => false, 'kind' => (string)$entry['kind'], 'reason' => 'rule_based_in_editor'];
        }

        return ['supported' => false, 'kind' => '', 'reason' => 'no_safe_action'];
    }

    /** One line for aqg_get_remediation_guidance's `limits`. */
    public static function ruleSupportLine(string $ruleId, string $sourceType): string
    {
        $support = self::ruleSupport($ruleId, $sourceType);
        if ($support['supported']) {
            return 'AI Fixes: an agent with suggestions:prepare can ask for a suggestion for this rule ('
                . str_replace('_', ' ', $support['kind'])
                . (($support['kind'] === EditorAiRuleCatalog::KIND_IMAGE_ALT) ? ', AQG looks at the image file, which must be in a TYPO3 file storage' : '')
                . '); a person reviews it. aqg_get_suggestion_status tells per finding whether it can be prepared.';
        }

        return match ($support['reason']) {
            'rendered_output' => 'AI Fixes: not available for findings of the rendered page; fix the template or content by hand.',
            'rule_based_in_editor' => 'AI Fixes: no AI suggestion for this rule; the TYPO3 editor offers a rule-based fix without AI.',
            default => 'AI Fixes: not available for this rule (no single safe automatic change); a person fixes it by hand.',
        };
    }

    private function label(string $key): string
    {
        try {
            $languageService = $this->english();
        } catch (\Throwable) {
            return '';
        }

        return $languageService instanceof LanguageService ? trim((string)$languageService->sL(self::LANGUAGE_FILE . $key)) : '';
    }

    private function english(): ?LanguageService
    {
        $factory = $this->languageServiceFactory ?? (class_exists(LanguageServiceFactory::class) ? GeneralUtility::makeInstance(LanguageServiceFactory::class) : null);

        return $factory?->create('default');
    }
}
