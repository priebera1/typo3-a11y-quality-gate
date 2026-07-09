<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

final class RuleMetadataKeyResolver
{
    /** @var array<string, string> */
    private const RULE_ALIASES = [
        'rendered.img_missing_alt' => 'image-alt',
        'img_missing_alt' => 'image-alt',
        'rendered.empty_link' => 'link-name',
        'rte.empty_link' => 'link-name',
        'empty_link' => 'link-name',
        'rendered.empty_button' => 'button-name',
        'empty_button' => 'button-name',
        'rendered.iframe_missing_title' => 'frame-title',
        'rte.iframe_missing_title' => 'frame-title',
        'iframe_missing_title' => 'frame-title',
        'rendered.form_control_missing_label' => 'form_control_missing_label',
        'rte.form_control_missing_label' => 'form_control_missing_label',
        'structured.form_field_label_missing' => 'form_field_label_missing',
        'rendered.empty_heading' => 'empty_heading',
        'rte.empty_heading' => 'empty_heading',
        'empty_heading' => 'empty_heading',
        'rendered.main_landmark_missing' => 'landmark-one-main',
        'main_landmark_missing' => 'landmark-one-main',
        'rendered.duplicate_id' => 'duplicate_id',
        'structured.file_reference_alt_quality' => 'file_reference_alt_quality',
        'structured.header_level_is_h1' => 'header_level_is_h1',
    ];

    /** @param list<string> $knownRuleIds */
    public function __construct(
        private readonly array $knownRuleIds = [],
    ) {}

    public function resolveFriendlyRuleKey(string $ruleId): string
    {
        $ruleId = strtolower(trim($ruleId));
        if ($ruleId === '') {
            return '';
        }

        $candidates = [
            $ruleId,
            $this->stripPrefix($ruleId),
        ];

        foreach ($candidates as $candidate) {
            if (isset(self::RULE_ALIASES[$candidate])) {
                return self::RULE_ALIASES[$candidate];
            }
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->knownRuleIds, true)) {
                return $candidate;
            }
        }

        $stripped = $this->stripPrefix($ruleId);
        foreach ($this->knownRuleIds as $knownRuleId) {
            if ($knownRuleId !== '' && str_contains($stripped, $knownRuleId)) {
                return $knownRuleId;
            }
        }

        return '';
    }

    public function stripPrefix(string $ruleId): string
    {
        return preg_replace('/^(axe|remote|rte|rendered|structured)\./', '', $ruleId) ?? $ruleId;
    }
}
