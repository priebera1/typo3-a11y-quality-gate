<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;

final class RuleMetadataPresentationService implements RuleMetadataPresentationServiceInterface
{
    private readonly RuleMetadataKeyResolver $ruleMetadataKeyResolver;

    public function __construct(
        private readonly BackendLanguageService $backendLanguageService,
    ) {
        $this->ruleMetadataKeyResolver = new RuleMetadataKeyResolver(array_keys(self::FRIENDLY_RULES));
    }


    /** @var array<string, string> */
    private const AFFECTED_USER_TRANSLATION_KEYS = [
        'blind users' => 'rule.affectedUsers.blind',
        'deafblind users' => 'rule.affectedUsers.deafblind',
        'screen reader users' => 'rule.affectedUsers.screenReader',
        'keyboard users' => 'rule.affectedUsers.keyboard',
        'keyboard/screen reader navigation users' => 'rule.affectedUsers.keyboardScreenReader',
        'low vision' => 'rule.affectedUsers.lowVision',
        'low vision users' => 'rule.affectedUsers.lowVision',
        'color vision deficiency' => 'rule.affectedUsers.colorVision',
        'colour vision deficiency' => 'rule.affectedUsers.colorVision',
        'motor impairments' => 'rule.affectedUsers.motor',
        'touch device users' => 'rule.affectedUsers.touch',
        'voice control users' => 'rule.affectedUsers.voiceControl',
        'cognitive disabilities' => 'rule.affectedUsers.cognitive',
        'deaf or hard of hearing users' => 'rule.affectedUsers.deafHardOfHearing',
    ];

    /** @var array<string, array{titleKey:string,whyKey:string,fixKey:string,owner:string,fixType:string,affected:list<string>}> */
    private const FRIENDLY_RULES = [
        'image-alt' => [
            'titleKey' => 'rule.presentation.image_alt.title',
            'whyKey' => 'rule.presentation.image_alt.why',
            'fixKey' => 'rule.presentation.image_alt.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users'],
        ],
        'input-image-alt' => [
            'titleKey' => 'rule.presentation.input_image_alt.title',
            'whyKey' => 'rule.presentation.input_image_alt.why',
            'fixKey' => 'rule.presentation.input_image_alt.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users'],
        ],
        'button-name' => [
            'titleKey' => 'rule.presentation.button_name.title',
            'whyKey' => 'rule.presentation.button_name.why',
            'fixKey' => 'rule.presentation.button_name.fix',
            'owner' => 'developer',
            'fixType' => 'template_content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users', 'voice control users'],
        ],
        'select-name' => [
            'titleKey' => 'rule.presentation.select_name.title',
            'whyKey' => 'rule.presentation.select_name.why',
            'fixKey' => 'rule.presentation.select_name.fix',
            'owner' => 'developer',
            'fixType' => 'template_content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users'],
        ],
        'label' => [
            'titleKey' => 'rule.presentation.label.title',
            'whyKey' => 'rule.presentation.label.why',
            'fixKey' => 'rule.presentation.label.fix',
            'owner' => 'developer',
            'fixType' => 'template_content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'frame-title' => [
            'titleKey' => 'rule.presentation.frame_title.title',
            'whyKey' => 'rule.presentation.frame_title.why',
            'fixKey' => 'rule.presentation.frame_title.fix',
            'owner' => 'developer',
            'fixType' => 'template_content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users'],
        ],
        'link-name' => [
            'titleKey' => 'rule.presentation.link_name.title',
            'whyKey' => 'rule.presentation.link_name.why',
            'fixKey' => 'rule.presentation.link_name.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'Deafblind users', 'screen reader users', 'keyboard users'],
        ],
        'color-contrast' => [
            'titleKey' => 'rule.presentation.color_contrast.title',
            'whyKey' => 'rule.presentation.color_contrast.why',
            'fixKey' => 'rule.presentation.color_contrast.fix',
            'owner' => 'mixed',
            'fixType' => 'design',
            'affected' => ['Low vision', 'Color vision deficiency'],
        ],
        'target-size' => [
            'titleKey' => 'rule.presentation.target_size.title',
            'whyKey' => 'rule.presentation.target_size.why',
            'fixKey' => 'rule.presentation.target_size.fix',
            'owner' => 'mixed',
            'fixType' => 'design',
            'affected' => ['Motor impairments', 'Touch device users', 'Low vision users'],
        ],
        'region' => [
            'titleKey' => 'rule.presentation.region.title',
            'whyKey' => 'rule.presentation.region.why',
            'fixKey' => 'rule.presentation.region.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'landmark-unique' => [
            'titleKey' => 'rule.presentation.landmark_unique.title',
            'whyKey' => 'rule.presentation.landmark_unique.why',
            'fixKey' => 'rule.presentation.landmark_unique.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'marquee' => [
            'titleKey' => 'rule.presentation.marquee.title',
            'whyKey' => 'rule.presentation.marquee.why',
            'fixKey' => 'rule.presentation.marquee.fix',
            'owner' => 'developer',
            'fixType' => 'template_content',
            'affected' => ['Cognitive disabilities', 'low vision users', 'keyboard users'],
        ],
        'rte.img_alt_is_filename' => [
            'titleKey' => 'rule.presentation.rte_img_alt_is_filename.title',
            'whyKey' => 'rule.presentation.rte_img_alt_is_filename.why',
            'fixKey' => 'rule.presentation.rte_img_alt_is_filename.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users'],
        ],
        'rte.non_descriptive_link' => [
            'titleKey' => 'rule.presentation.rte_non_descriptive_link.title',
            'whyKey' => 'rule.presentation.rte_non_descriptive_link.why',
            'fixKey' => 'rule.presentation.rte_non_descriptive_link.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'duplicate_id' => [
            'titleKey' => 'rule.presentation.duplicate_id.title',
            'whyKey' => 'rule.presentation.duplicate_id.why',
            'fixKey' => 'rule.presentation.duplicate_id.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'form_control_missing_label' => [
            'titleKey' => 'rule.presentation.form_control_missing_label.title',
            'whyKey' => 'rule.presentation.form_control_missing_label.why',
            'fixKey' => 'rule.presentation.form_control_missing_label.fix',
            'owner' => 'mixed',
            'fixType' => 'mixed',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'img_alt_too_long' => [
            'titleKey' => 'rule.presentation.img_alt_too_long.title',
            'whyKey' => 'rule.presentation.img_alt_too_long.why',
            'fixKey' => 'rule.presentation.img_alt_too_long.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users'],
        ],
        'img_alt_redundant_phrase' => [
            'titleKey' => 'rule.presentation.img_alt_redundant_phrase.title',
            'whyKey' => 'rule.presentation.img_alt_redundant_phrase.why',
            'fixKey' => 'rule.presentation.img_alt_redundant_phrase.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users'],
        ],
        'link_text_duplicate_different_targets' => [
            'titleKey' => 'rule.presentation.link_text_duplicate_different_targets.title',
            'whyKey' => 'rule.presentation.link_text_duplicate_different_targets.why',
            'fixKey' => 'rule.presentation.link_text_duplicate_different_targets.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'link_text_is_url_or_filename' => [
            'titleKey' => 'rule.presentation.link_text_is_url_or_filename.title',
            'whyKey' => 'rule.presentation.link_text_is_url_or_filename.why',
            'fixKey' => 'rule.presentation.link_text_is_url_or_filename.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'link_to_document' => [
            'titleKey' => 'rule.presentation.link_to_document.title',
            'whyKey' => 'rule.presentation.link_to_document.why',
            'fixKey' => 'rule.presentation.link_to_document.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'link_to_document_missing_notice' => [
            'titleKey' => 'rule.presentation.link_to_document_missing_notice.title',
            'whyKey' => 'rule.presentation.link_to_document_missing_notice.why',
            'fixKey' => 'rule.presentation.link_to_document_missing_notice.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'file_reference_alt_quality' => [
            'titleKey' => 'rule.presentation.file_reference_alt_quality.title',
            'whyKey' => 'rule.presentation.file_reference_alt_quality.why',
            'fixKey' => 'rule.presentation.file_reference_alt_quality.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users'],
        ],
        'form_field_label_missing' => [
            'titleKey' => 'rule.presentation.form_field_label_missing.title',
            'whyKey' => 'rule.presentation.form_field_label_missing.why',
            'fixKey' => 'rule.presentation.form_field_label_missing.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'form_placeholder_as_label' => [
            'titleKey' => 'rule.presentation.form_placeholder_as_label.title',
            'whyKey' => 'rule.presentation.form_placeholder_as_label.why',
            'fixKey' => 'rule.presentation.form_placeholder_as_label.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Low vision users', 'cognitive disabilities', 'screen reader users'],
        ],
        'form_autocomplete_missing' => [
            'titleKey' => 'rule.presentation.form_autocomplete_missing.title',
            'whyKey' => 'rule.presentation.form_autocomplete_missing.why',
            'fixKey' => 'rule.presentation.form_autocomplete_missing.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Motor impairments', 'cognitive disabilities'],
        ],
        'media_no_transcript_hint' => [
            'titleKey' => 'rule.presentation.media_no_transcript_hint.title',
            'whyKey' => 'rule.presentation.media_no_transcript_hint.why',
            'fixKey' => 'rule.presentation.media_no_transcript_hint.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Deaf or hard of hearing users', 'deafblind users'],
        ],
        'header_level_is_h1' => [
            'titleKey' => 'rule.presentation.header_level_is_h1.title',
            'whyKey' => 'rule.presentation.header_level_is_h1.why',
            'fixKey' => 'rule.presentation.header_level_is_h1.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'empty_heading' => [
            'titleKey' => 'rule.presentation.empty_heading.title',
            'whyKey' => 'rule.presentation.empty_heading.why',
            'fixKey' => 'rule.presentation.empty_heading.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
        'landmark_unique' => [
            'titleKey' => 'rule.presentation.landmark_unique.title',
            'whyKey' => 'rule.presentation.landmark_unique.why',
            'fixKey' => 'rule.presentation.landmark_unique.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Blind users', 'screen reader users', 'keyboard/screen reader navigation users'],
        ],
        'landmark-one-main' => [
            'titleKey' => 'rule.presentation.landmark_one_main.title',
            'whyKey' => 'rule.presentation.landmark_one_main.why',
            'fixKey' => 'rule.presentation.landmark_one_main.fix',
            'owner' => 'developer',
            'fixType' => 'template',
            'affected' => ['Blind users', 'screen reader users', 'keyboard users'],
        ],
        'page-has-heading-one' => [
            'titleKey' => 'rule.presentation.page_has_heading_one.title',
            'whyKey' => 'rule.presentation.page_has_heading_one.why',
            'fixKey' => 'rule.presentation.page_has_heading_one.fix',
            'owner' => 'editor',
            'fixType' => 'content',
            'affected' => ['Blind users', 'screen reader users', 'cognitive disabilities'],
        ],
    ];
    /** @var array<string, array{criterion:string,level:string,name:string,technique?:string,techniqueLabel?:string,w3c?:string,techniqueUrl?:string,standards:list<string>,tags:list<string>}> */
    private const WCAG_MAP = [
        'image-alt' => ['criterion' => '1.1.1', 'level' => 'A', 'name' => 'Non-text Content', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag111']],
        'input-image-alt' => ['criterion' => '1.1.1', 'level' => 'A', 'name' => 'Non-text Content', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag111']],
        'label' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'color-contrast' => ['criterion' => '1.4.3', 'level' => 'AA', 'name' => 'Contrast (Minimum)', 'standards' => ['WCAG 2.1 AA', 'WCAG 2.2 AA', 'EN 301 549'], 'tags' => ['wcag2aa', 'wcag143']],
        'marquee' => ['criterion' => '2.2.2', 'level' => 'A', 'name' => 'Pause, Stop, Hide', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag222']],
        'link-name' => ['criterion' => '2.4.4', 'level' => 'A', 'name' => 'Link Purpose (In Context)', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag244']],
        'frame-title' => ['criterion' => '4.1.2', 'level' => 'A', 'name' => 'Name, Role, Value', 'technique' => 'H64', 'techniqueLabel' => 'Using the title attribute of the iframe element', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag412', 'cat.name-role-value']],
        'button-name' => ['criterion' => '4.1.2', 'level' => 'A', 'name' => 'Name, Role, Value', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag412', 'cat.name-role-value']],
        'select-name' => ['criterion' => '4.1.2', 'level' => 'A', 'name' => 'Name, Role, Value', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag412', 'cat.name-role-value']],
        'target-size' => ['criterion' => '2.5.8', 'level' => 'AA', 'name' => 'Target Size (Minimum)', 'standards' => ['WCAG 2.2 AA', 'EN 301 549'], 'tags' => ['wcag22aa', 'wcag258', 'cat.sensory-and-visual-cues']],
        'region' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'landmark-unique' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'duplicate_id' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'form_control_missing_label' => ['criterion' => '4.1.2', 'level' => 'A', 'name' => 'Name, Role, Value', 'additionalReferences' => [['criterion' => '3.3.2', 'level' => 'A', 'name' => 'Labels or Instructions']], 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag412', 'wcag332']],
        'landmark_unique' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'landmark-one-main' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'page-has-heading-one' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
        'empty_heading' => ['criterion' => '1.3.1', 'level' => 'A', 'name' => 'Info and Relationships', 'standards' => ['WCAG 2.1 A', 'WCAG 2.2 A', 'EN 301 549'], 'tags' => ['wcag2a', 'wcag131']],
    ];

    /** @return array<string, mixed> */
    public function present(array $issue, string $language = 'en'): array
    {
        $language = $this->normalizeLanguage($language);
        $ruleId = $this->normalizeRuleId((string)($issue['rule_id'] ?? $issue['ruleId'] ?? ''));
        $metadata = $this->extractMetadata($issue);
        $friendlyKey = $this->ruleMetadataKeyResolver->resolveFriendlyRuleKey($ruleId);
        $friendly = $friendlyKey !== '' ? (self::FRIENDLY_RULES[$friendlyKey] ?? null) : null;
        $wcagFallback = $friendlyKey !== '' ? (self::WCAG_MAP[$friendlyKey] ?? null) : null;

        $title = $this->firstString([
            $metadata['plainLanguageTitle'] ?? null,
            $metadata['plain_language_title'] ?? null,
            $issue['plainLanguageTitle'] ?? null,
            $issue['plain_language_title'] ?? null,
            $this->translateFriendly($friendly['titleKey'] ?? null, $language),
            $metadata['title'] ?? null,
            $issue['help'] ?? null,
            $issue['title'] ?? null,
        ]);
        if ($title === '') {
            $title = $this->humanizeRuleId($ruleId);
        }

        $why = $this->firstString([
            $metadata['whyItMatters'] ?? null,
            $metadata['why_it_matters'] ?? null,
            $issue['guidance_why_it_matters'] ?? null,
            $this->translateFriendly($friendly['whyKey'] ?? null, $language),
        ]);
        $fix = $this->firstString([
            $metadata['remediation'] ?? null,
            $metadata['howToFix'] ?? null,
            $metadata['how_to_fix'] ?? null,
            $issue['guidance_how_to_fix'] ?? null,
            $this->translateFriendly($friendly['fixKey'] ?? null, $language),
        ]);

        $affectedUsers = $this->localizeAffectedUsers(
            $this->stringList(
                $metadata['affectedUsers'] ?? $metadata['affected_users'] ?? $issue['affectedUsers'] ?? $issue['affected_users'] ?? $issue['affected_users_json'] ?? [],
                5,
                $friendly['affected'] ?? []
            ),
            $language
        );
        $wcagReferences = $this->normalizeWcagReferences(
            $metadata['wcagReferences'] ?? $metadata['wcag_references'] ?? $metadata['wcag'] ?? $issue['wcagReferences'] ?? $issue['wcag_references'] ?? $issue['wcag_references_json'] ?? [],
            $wcagFallback
        );
        $standards = $this->stringList($metadata['standards'] ?? $issue['standards'] ?? $issue['standards_json'] ?? [], 6, $wcagFallback['standards'] ?? []);
        $technicalTags = $this->stringList($metadata['tags'] ?? $metadata['technicalTags'] ?? $metadata['technical_tags'] ?? $issue['technicalTags'] ?? $issue['technical_tags'] ?? $issue['technical_tags_json'] ?? [], 8, $wcagFallback['tags'] ?? []);
        $docs = $this->normalizeDocumentation(
            $metadata['documentation'] ?? $metadata['ruleDocumentation'] ?? $metadata['rule_documentation'] ?? $issue['documentationLinks'] ?? $issue['ruleDocumentation'] ?? $issue['rule_documentation'] ?? $issue['rule_documentation_json'] ?? [],
            (string)($issue['help_url'] ?? ''),
            $ruleId,
            $language
        );
        $techniques = $this->normalizeTechniques($metadata['techniques'] ?? $metadata['technique'] ?? [], $wcagFallback);

        $owner = $this->normalizeMachineValue($metadata['suggestedOwner'] ?? $metadata['suggested_owner'] ?? $issue['who_should_fix'] ?? $friendly['owner'] ?? '');
        $fixType = $this->normalizeMachineValue($metadata['fixType'] ?? $metadata['fix_type'] ?? $issue['fix_type'] ?? $friendly['fixType'] ?? '');

        return [
            'ruleId' => $ruleId,
            'title' => $title,
            'whyItMatters' => $why,
            'howToFix' => $fix,
            'affectedUsers' => $affectedUsers,
            'affectedUserItems' => $this->affectedUserItems($affectedUsers),
            'affectedUsersLabel' => implode(', ', $affectedUsers),
            'wcagReferences' => $wcagReferences,
            'wcagPrimaryLabel' => $wcagReferences[0]['label'] ?? '',
            'wcagCompactLabel' => $this->compactWcagLabel($wcagReferences[0] ?? []),
            'techniques' => $techniques,
            'standards' => $standards,
            'documentationLinks' => $docs,
            'technicalTags' => $technicalTags,
            'owner' => $owner,
            'ownerLabel' => $this->formatBadgeLabel($owner),
            'fixType' => $fixType,
            'fixTypeLabel' => $this->formatBadgeLabel($fixType),
            'hasAffectedUsers' => $affectedUsers !== [],
            'hasWcagReferences' => $wcagReferences !== [],
            'hasTechniques' => $techniques !== [],
            'hasStandards' => $standards !== [],
            'hasDocumentationLinks' => $docs !== [],
            'hasTechnicalTags' => $technicalTags !== [],
            'hasStandardsAndImpact' => $affectedUsers !== [] || $wcagReferences !== [] || $techniques !== [] || $standards !== [] || $docs !== [] || $technicalTags !== [],
        ];
    }

    public function friendlyTitleForRule(string $ruleId, string $fallback = '', string $language = 'en'): string
    {
        $presented = $this->present(['rule_id' => $ruleId, 'help' => $fallback], $language);
        return (string)$presented['title'];
    }

    /** @return array<string, mixed> */
    private function extractMetadata(array $issue): array
    {
        $metadata = [];
        foreach (['ruleMetadata', 'rule_metadata', 'metadata', 'normalizedMetadata', 'normalized_metadata'] as $key) {
            if (is_array($issue[$key] ?? null)) {
                $metadata = array_replace_recursive($metadata, $issue[$key]);
            }
        }
        foreach (['rule_metadata_json', 'metadata_json'] as $key) {
            if (!is_string($issue[$key] ?? null) || trim((string)$issue[$key]) === '') {
                continue;
            }
            $decoded = json_decode((string)$issue[$key], true);
            if (is_array($decoded)) {
                $metadata = array_replace_recursive($metadata, $decoded);
            }
        }
        return $metadata;
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        return str_starts_with($language, 'de') ? 'de' : 'en';
    }

    private function translateFriendly(?string $key, string $language): string
    {
        if ($key === null || trim($key) === '') {
            return '';
        }

        return $this->backendLanguageService->translateForLanguage($key, $language);
    }

    private function normalizeRuleId(string $ruleId): string
    {
        return strtolower(trim($ruleId));
    }

    private function humanizeRuleId(string $ruleId): string
    {
        $ruleId = $this->ruleMetadataKeyResolver->stripPrefix($ruleId);
        $ruleId = str_replace(['_', '-'], ' ', $ruleId);
        $ruleId = trim($ruleId);
        return $ruleId !== '' ? ucfirst($ruleId) : 'Accessibility issue';
    }

    /** @param list<mixed> $values */
    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            if ($value === null || is_array($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return substr($value, 0, 500);
            }
        }
        return '';
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit, array $fallback = []): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } elseif (trim($value) !== '') {
                $value = preg_split('/[,;]+/', $value) ?: [];
            }
        }
        if (!is_array($value)) {
            $value = [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['label'] ?? $item['name'] ?? $item['title'] ?? $item['id'] ?? '';
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $items[$item] = substr($item, 0, 160);
            }
            if (count($items) >= $limit) {
                break;
            }
        }
        if ($items === [] && $fallback !== []) {
            foreach ($fallback as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $items[$item] = $item;
                }
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
        return array_values($items);
    }


    /** @param list<string> $affectedUsers @return list<string> */
    private function localizeAffectedUsers(array $affectedUsers, string $language): array
    {
        $localized = [];
        foreach ($affectedUsers as $label) {
            $label = trim((string)$label);
            if ($label === '') {
                continue;
            }

            $key = self::AFFECTED_USER_TRANSLATION_KEYS[strtolower($label)] ?? null;
            $translated = $key !== null
                ? $this->backendLanguageService->translateForLanguage($key, $language)
                : '';
            $displayLabel = $translated !== '' ? $translated : $label;
            $localized[$displayLabel] = $displayLabel;
        }

        return array_values($localized);
    }

    /**
     * @param list<string> $affectedUsers
     * @return list<array{label:string,icon:string}>
     */
    private function affectedUserItems(array $affectedUsers): array
    {
        $items = [];
        foreach ($affectedUsers as $label) {
            $label = trim((string)$label);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'label' => $label,
                'icon' => $this->affectedUserIcon($label),
            ];
        }
        return $items;
    }

    private function affectedUserIcon(string $label): string
    {
        $normalized = strtolower($label);
        return match (true) {
            str_contains($normalized, 'deafblind') || str_contains($normalized, 'taubblind') => 'sensory',
            str_contains($normalized, 'keyboard') || str_contains($normalized, 'tastatur') => 'keyboard',
            str_contains($normalized, 'screen reader') || str_contains($normalized, 'screenreader') => 'audio',
            str_contains($normalized, 'touch'),
            str_contains($normalized, 'berühr'),
            str_contains($normalized, 'pointer'),
            str_contains($normalized, 'tap') => 'touch',
            str_contains($normalized, 'motor'),
            str_contains($normalized, 'mobilität'),
            str_contains($normalized, 'mobility'),
            str_contains($normalized, 'movement') => 'mobility',
            str_contains($normalized, 'color') || str_contains($normalized, 'colour') || str_contains($normalized, 'farb'),
            str_contains($normalized, 'contrast') => 'contrast',
            str_contains($normalized, 'low vision'),
            str_contains($normalized, 'sehbehinderung'),
            str_contains($normalized, 'vision') => 'eye',
            str_contains($normalized, 'blind') => 'blind',
            str_contains($normalized, 'voice') || str_contains($normalized, 'sprachsteuer') => 'microphone',
            str_contains($normalized, 'cognitive'),
            str_contains($normalized, 'kognitiv'),
            str_contains($normalized, 'learning') => 'cognitive',
            str_contains($normalized, 'deaf'),
            str_contains($normalized, 'hearing'),
            str_contains($normalized, 'hör'),
            str_contains($normalized, 'caption') => 'caption',
            default => 'accessibility',
        };
    }

    /** @return list<array{criterion:string,level:string,name:string,label:string,url:string}> */
    private function normalizeWcagReferences(mixed $value, ?array $fallback): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            $value = [];
        }
        $refs = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $criterion = trim($item);
                $level = '';
                $name = '';
                $url = '';
            } elseif (is_array($item)) {
                $criterion = trim((string)($item['criterion'] ?? $item['id'] ?? $item['number'] ?? ''));
                $level = strtoupper(trim((string)($item['level'] ?? '')));
                $name = trim((string)($item['name'] ?? $item['title'] ?? ''));
                $url = $this->safeUrl($item['url'] ?? $item['href'] ?? '');
            } else {
                continue;
            }
            if ($criterion === '') {
                continue;
            }
            $refs[] = [
                'criterion' => $criterion,
                'level' => $level,
                'name' => $name,
                'label' => 'WCAG ' . $criterion . ($name !== '' ? ' ' . $name : '') . ($level !== '' ? ' - Level ' . $level : ''),
                'url' => $url,
            ];
        }
        if ($refs === [] && $fallback !== null) {
            $criterion = (string)$fallback['criterion'];
            $level = (string)$fallback['level'];
            $name = (string)$fallback['name'];
            $refs[] = [
                'criterion' => $criterion,
                'level' => $level,
                'name' => $name,
                'label' => 'WCAG ' . $criterion . ' ' . $name . ' - Level ' . $level,
                'url' => $this->wcagUnderstandingUrl($criterion),
            ];
        }
        if ($refs !== [] && $fallback !== null && is_array($fallback['additionalReferences'] ?? null)) {
            foreach ($fallback['additionalReferences'] as $additionalReference) {
                if (!is_array($additionalReference)) {
                    continue;
                }
                $criterion = trim((string)($additionalReference['criterion'] ?? ''));
                if ($criterion === '' || in_array($criterion, array_column($refs, 'criterion'), true)) {
                    continue;
                }
                $level = strtoupper(trim((string)($additionalReference['level'] ?? '')));
                $name = trim((string)($additionalReference['name'] ?? ''));
                $refs[] = [
                    'criterion' => $criterion,
                    'level' => $level,
                    'name' => $name,
                    'label' => 'WCAG ' . $criterion . ($name !== '' ? ' ' . $name : '') . ($level !== '' ? ' - Level ' . $level : ''),
                    'url' => $this->wcagUnderstandingUrl($criterion),
                ];
            }
        }
        return array_slice($refs, 0, 4);
    }

    /** @return list<array{label:string,url:string}> */
    private function normalizeTechniques(mixed $value, ?array $fallback): array
    {
        $items = [];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $label = trim((string)($item['label'] ?? $item['title'] ?? $item['id'] ?? ''));
                    $url = $this->safeUrl($item['url'] ?? $item['href'] ?? '');
                } else {
                    $label = trim((string)$item);
                    $url = '';
                }
                if ($label !== '') {
                    $items[] = ['label' => $label, 'url' => $url];
                }
            }
        }
        if ($items === [] && is_array($fallback) && isset($fallback['technique'])) {
            $items[] = ['label' => (string)$fallback['technique'] . ': ' . (string)($fallback['techniqueLabel'] ?? ''), 'url' => $this->techniqueUrl((string)$fallback['technique'])];
        }
        return array_slice($items, 0, 4);
    }

    /** @return list<array{label:string,url:string,type:string}> */
    private function normalizeDocumentation(mixed $value, string $helpUrl, string $ruleId, string $language): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            $value = [];
        }
        $docs = [];
        $seenUrls = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $url = $this->safeUrl($item['url'] ?? $item['href'] ?? '');
                $label = trim((string)($item['label'] ?? $item['title'] ?? $item['name'] ?? ''));
                $type = trim((string)($item['type'] ?? 'rule'));
            } else {
                $url = $this->safeUrl($item);
                $label = '';
                $type = 'rule';
            }
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $normalizedLabel = strtolower(trim($label));
            $isAxeDocumentation = strtolower($type) === 'deque'
                || str_contains($url, 'dequeuniversity.com')
                || str_contains($url, 'deque.com');
            if (
                $label === ''
                || in_array(
                    $normalizedLabel,
                    [
                        'rule documentation',
                        'deque axe rule documentation',
                        'axe-core rule documentation',
                    ],
                    true
                )
            ) {
                $label = $this->backendLanguageService->translateForLanguage(
                    $isAxeDocumentation
                        ? 'rule.metadata.documentation.axe'
                        : 'rule.metadata.documentation.rule',
                    $language
                );
            }
            $docs[] = ['label' => substr($label, 0, 160), 'url' => $url, 'type' => $type];
        }
        $helpUrl = $this->safeUrl($helpUrl);
        if ($helpUrl !== '' && !isset($seenUrls[$helpUrl])) {
            $docs[] = [
                'label' => $this->backendLanguageService->translateForLanguage(
                    'rule.metadata.documentation.axe',
                    $language
                ),
                'url' => $helpUrl,
                'type' => 'deque',
            ];
        }
        return array_slice($docs, 0, 4);
    }


    private function wcagUnderstandingUrl(string $criterion): string
    {
        return match ($criterion) {
            '1.1.1' => 'https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html',
            '3.3.2' => 'https://www.w3.org/WAI/WCAG22/Understanding/labels-or-instructions.html',
            '1.3.1' => 'https://www.w3.org/WAI/WCAG22/Understanding/info-and-relationships.html',
            '1.4.3' => 'https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html',
            '2.2.2' => 'https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html',
            '2.4.4' => 'https://www.w3.org/WAI/WCAG22/Understanding/link-purpose-in-context.html',
            '4.1.2' => 'https://www.w3.org/WAI/WCAG22/Understanding/name-role-value.html',
            default => '',
        };
    }

    private function techniqueUrl(string $technique): string
    {
        $technique = strtoupper(trim($technique));
        if ($technique === 'H64') {
            return 'https://www.w3.org/WAI/WCAG22/Techniques/html/H64';
        }
        return '';
    }

    private function safeUrl(mixed $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true) ? substr($url, 0, 1000) : '';
    }

    /** @param array<string, mixed> $reference */
    private function compactWcagLabel(array $reference): string
    {
        $criterion = trim((string)($reference['criterion'] ?? ''));
        $level = strtoupper(trim((string)($reference['level'] ?? '')));
        if ($criterion === '') {
            return '';
        }
        return 'WCAG ' . $criterion . ($level !== '' ? ' · Level ' . $level : '');
    }

    private function normalizeMachineValue(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        return trim($value, '_-');
    }

    private function formatBadgeLabel(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));
        return $value !== '' ? ucwords($value) : '';
    }
}
