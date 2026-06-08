<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;

final class RemoteReportingSummaryService
{
    private const PRIORITY_FIXES_LIMIT = 10;
    private const GUIDANCE_FALLBACK_TEXT = 'Review this finding in context.';


    /**
     * Use the API /crawl/summary payload as the canonical reporting source.
     * The locally computed report is intentionally only a fallback for older
     * scans or API responses that do not provide wcagSummary/priorityFixes yet.
     *
     * @param array<int, array<string, mixed>> $wcagSummary
     * @param array<int, array<string, mixed>> $priorityFixes
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>,
     *   reportSummary: array<string, mixed>,
     *   manualReviewChecklist: array<int, array<string, mixed>>,
     *   reportingGroups: array<string, mixed>,
     *   score: array<string, mixed>,
     *   remediationSummary: array<string, mixed>
     * }
     */
    public function buildFromApiSummary(array $wcagSummary, array $priorityFixes): array
    {
        return $this->buildFromApiSummaryWithFallback($wcagSummary, $priorityFixes);
    }

    /**
     * Build reporting data from the API summary and optionally fill missing
     * reporting arrays from the local DB fallback. This keeps the API summary
     * as the primary source, but prevents older crawler responses with missing
     * wcagSummary/rule WCAG fields from rendering an empty WCAG breakdown.
     *
     * @param array<int, array<string, mixed>> $wcagSummary
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<string, mixed> $fallbackSummary
     * @param array<string, mixed> $reportSummary
     * @param array<int, array<string, mixed>> $manualReviewChecklist
     * @param array<string, mixed> $reportingGroups
     * @param array<string, mixed> $score
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>,
     *   reportSummary: array<string, mixed>,
     *   manualReviewChecklist: array<int, array<string, mixed>>,
     *   reportingGroups: array<string, mixed>,
     *   score: array<string, mixed>,
     *   remediationSummary: array<string, mixed>
     * }
     */
    public function buildFromApiSummaryWithFallback(
        array $wcagSummary,
        array $priorityFixes,
        array $fallbackSummary = [],
        array $reportSummary = [],
        array $manualReviewChecklist = [],
        array $reportingGroups = [],
        array $score = [],
        array $keyboardSummary = [],
        array $structureSummary = [],
        array $contrastDetails = [],
        array $remediationSummary = [],
        array $componentSummary = [],
    ): array
    {
        $normalizedPriorityFixes = $this->normalizePriorityFixes($priorityFixes);
        if ($normalizedPriorityFixes === [] && is_array($fallbackSummary['priorityFixes'] ?? null)) {
            $normalizedPriorityFixes = $this->normalizePriorityFixes($fallbackSummary['priorityFixes']);
        }

        $normalizedWcagSummary = $this->normalizeWcagSummary($wcagSummary);
        if ($normalizedWcagSummary === [] && is_array($fallbackSummary['wcagSummary'] ?? null)) {
            $normalizedWcagSummary = $this->normalizeWcagSummary($fallbackSummary['wcagSummary']);
        }

        return $this->buildResult(
            $normalizedPriorityFixes,
            $normalizedWcagSummary,
            $this->normalizeReportSummary($reportSummary),
            $this->normalizeManualReviewChecklist($manualReviewChecklist),
            $this->normalizeReportingGroups($reportingGroups),
            $this->normalizeScore($score),
            $this->normalizeKeyboardSummary($keyboardSummary),
            $this->normalizeStructureSummary($structureSummary),
            $this->normalizeContrastDetails($contrastDetails),
            $this->normalizeRemediationSummary($remediationSummary),
            $this->normalizeComponentSummary($componentSummary)
        );
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>,
     *   reportSummary: array<string, mixed>,
     *   manualReviewChecklist: array<int, array<string, mixed>>,
     *   reportingGroups: array<string, mixed>,
     *   score: array<string, mixed>,
     *   remediationSummary: array<string, mixed>
     * }
     */
    public function buildForRemoteScan(?array $remoteScan, RemoteIssueRepository $remoteIssueRepository): array
    {
        if (!is_array($remoteScan) || (int)($remoteScan['uid'] ?? 0) <= 0) {
            return [
                'priorityFixes' => [],
                'priorityFixesVisible' => [],
                'priorityFixesMoreCount' => 0,
                'wcagSummary' => [],
                'reportSummary' => [],
                'manualReviewChecklist' => [],
                'reportingGroups' => [],
                'score' => [],
                'keyboardSummary' => [],
                'structureSummary' => [],
                'contrastDetails' => [],
                'remediationSummary' => [],
            ];
        }

        $rows = $remoteIssueRepository->findIssueRowsForRemoteScan((int)$remoteScan['uid']);

        return $this->buildResult(
            $this->buildPriorityFixes($rows),
            $this->buildWcagSummary($rows),
            []
        );
    }

    /**
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<int, array<string, mixed>> $wcagSummary
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>,
     *   reportSummary: array<string, mixed>,
     *   manualReviewChecklist: array<int, array<string, mixed>>,
     *   reportingGroups: array<string, mixed>,
     *   score: array<string, mixed>,
     *   remediationSummary: array<string, mixed>
     * }
     */
    private function buildResult(
        array $priorityFixes,
        array $wcagSummary,
        array $reportSummary = [],
        array $manualReviewChecklist = [],
        array $reportingGroups = [],
        array $score = [],
        array $keyboardSummary = [],
        array $structureSummary = [],
        array $contrastDetails = [],
        array $remediationSummary = [],
        array $componentSummary = [],
    ): array
    {
        $visiblePriorityFixes = array_slice($priorityFixes, 0, 5);

        return [
            'priorityFixes' => $priorityFixes,
            'priorityFixesVisible' => $visiblePriorityFixes,
            'priorityFixesMoreCount' => max(0, count($priorityFixes) - count($visiblePriorityFixes)),
            'wcagSummary' => $wcagSummary,
            'reportSummary' => $reportSummary,
            'manualReviewChecklist' => $manualReviewChecklist,
            'reportingGroups' => $reportingGroups,
            'score' => $score,
            'keyboardSummary' => $keyboardSummary,
            'structureSummary' => $structureSummary,
            'contrastDetails' => $contrastDetails,
            'remediationSummary' => $remediationSummary,
            'componentSummary' => $componentSummary,
        ];
    }

    /**
     * @param array<string, mixed> $score
     * @return array<string, mixed>
     */
    private function normalizeScore(array $score): array
    {
        if ($score === []) {
            return [];
        }

        $hasValue = array_key_exists('value', $score) || array_key_exists('score', $score);
        $value = max(0, min(100, (int)($score['value'] ?? $score['score'] ?? 0)));
        $max = max(1, (int)($score['max'] ?? 100));
        $rawTone = $this->normalizeMachineValue($score['tone'] ?? $score['status'] ?? null);
        $tone = match ($rawTone) {
            'critical', 'danger', 'error', 'err' => 'critical',
            'warning', 'warn', 'attention', 'needs_attention', 'needs-attention' => 'attention',
            'good', 'ok', 'success' => 'good',
            'info', 'neutral', 'unavailable' => $rawTone,
            default => '',
        };
        $label = $this->normalizeNullableString($score['label'] ?? null);

        return [
            'value' => $value,
            'max' => $max,
            'label' => $label,
            'tone' => $tone,
            'basis' => $this->normalizeMachineValue($score['basis'] ?? null),
            'explanation' => $this->normalizeNullableString($score['explanation'] ?? null),
            'manualReviewRequired' => (bool)($score['manualReviewRequired'] ?? $score['manual_review_required'] ?? false),
            'issuesTotal' => max(0, (int)($score['issuesTotal'] ?? $score['issues_total'] ?? 0)),
            'wcagMappedIssuesTotal' => max(0, (int)($score['wcagMappedIssuesTotal'] ?? $score['wcag_mapped_issues_total'] ?? 0)),
            'priorityFixesTotal' => max(0, (int)($score['priorityFixesTotal'] ?? $score['priority_fixes_total'] ?? 0)),
            'hasValue' => $hasValue,
        ];
    }

    /**
     * @param array<string, mixed> $keyboardSummary
     * @return array<string, mixed>
     */
    private function normalizeKeyboardSummary(array $keyboardSummary): array
    {
        if ($keyboardSummary === []) {
            return [];
        }

        $tested = (bool)($keyboardSummary['tested'] ?? false);
        $possibleKeyboardTrap = (bool)($keyboardSummary['possibleKeyboardTrap'] ?? $keyboardSummary['possible_keyboard_trap'] ?? false);
        $manualReviewRequired = (bool)($keyboardSummary['manualReviewRequired'] ?? $keyboardSummary['manual_review_required'] ?? true);
        $invisibleFocusIssuesTotal = max(0, (int)($keyboardSummary['invisibleFocusIssuesTotal'] ?? $keyboardSummary['invisible_focus_issues_total'] ?? 0));

        return [
            'available' => true,
            'tested' => $tested,
            'testedLabel' => $tested ? 'Available' : 'Not available',
            'focusStepsTotal' => max(0, (int)($keyboardSummary['focusStepsTotal'] ?? $keyboardSummary['focus_steps_total'] ?? 0)),
            'uniqueFocusedElementsTotal' => max(0, (int)($keyboardSummary['uniqueFocusedElementsTotal'] ?? $keyboardSummary['unique_focused_elements_total'] ?? 0)),
            'possibleKeyboardTrap' => $possibleKeyboardTrap,
            'possibleKeyboardTrapLabel' => $possibleKeyboardTrap ? 'Possible keyboard trap' : 'No trap signal',
            'possibleKeyboardTrapTone' => $possibleKeyboardTrap ? 'critical' : 'neutral',
            'invisibleFocusIssuesTotal' => $invisibleFocusIssuesTotal,
            'invisibleFocusIssuesLabel' => $invisibleFocusIssuesTotal === 1 ? '1 invisible focus issue' : $invisibleFocusIssuesTotal . ' invisible focus issues',
            'manualReviewRequired' => $manualReviewRequired,
            'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : 'Manual review still recommended',
        ];
    }

    /**
     * @param array<string, mixed> $remediationSummary
     * @return array<string, mixed>
     */
    private function normalizeRemediationSummary(array $remediationSummary): array
    {
        if ($remediationSummary === []) {
            return [];
        }

        $items = [];
        foreach ([
            'editorContentFixes' => ['label' => 'Editor/content fixes', 'keys' => ['editorContentFixes', 'editor_content_fixes', 'editorFixes', 'editor_fixes', 'contentFixes', 'content_fixes']],
            'developerTemplateFixes' => ['label' => 'Developer/template fixes', 'keys' => ['developerTemplateFixes', 'developer_template_fixes', 'developerFixes', 'developer_fixes', 'templateFixes', 'template_fixes']],
            'designFixes' => ['label' => 'Design fixes', 'keys' => ['designFixes', 'design_fixes']],
            'quickWins' => ['label' => 'Quick wins', 'keys' => ['quickWins', 'quick_wins']],
        ] as $key => $config) {
            $value = null;
            foreach ($config['keys'] as $sourceKey) {
                if (array_key_exists($sourceKey, $remediationSummary)) {
                    $value = $remediationSummary[$sourceKey];
                    break;
                }
            }

            $item = $this->normalizeRemediationSummaryItem((string)$key, (string)$config['label'], $value);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $recommendation = $this->normalizeNullableString($remediationSummary['recommendation'] ?? $remediationSummary['recommendedNextStep'] ?? $remediationSummary['recommended_next_step'] ?? null);
        $note = $this->normalizeNullableString($remediationSummary['note'] ?? $remediationSummary['disclaimer'] ?? null);

        if ($items === [] && $recommendation === null && $note === null) {
            return [];
        }

        return [
            'title' => 'Suggested remediation grouping',
            'items' => $items,
            'hasItems' => $items !== [],
            'recommendation' => $recommendation,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeRemediationSummaryItem(string $key, string $label, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $count = null;
        $description = null;

        if (is_array($value)) {
            $count = isset($value['count']) || isset($value['total']) || isset($value['issuesTotal']) || isset($value['issues_total'])
                ? max(0, (int)($value['count'] ?? $value['total'] ?? $value['issuesTotal'] ?? $value['issues_total'] ?? 0))
                : null;
            $description = $this->normalizeNullableString($value['label'] ?? $value['description'] ?? $value['note'] ?? null);
        } elseif (is_numeric($value)) {
            $count = max(0, (int)$value);
        } else {
            $description = $this->normalizeNullableString($value);
        }

        if ($count === null && $description === null) {
            return null;
        }

        if ($count === 0 && $description === null) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'countLabel' => $count !== null ? $this->countLabel($count, 'item') : '',
            'description' => $description,
        ];
    }

    /**
     * @param array<string, mixed> $componentSummary
     * @return array<string, mixed>
     */
    private function normalizeComponentSummary(array $componentSummary): array
    {
        if ($componentSummary === []) {
            return [];
        }

        $likelyRepeatedTemplateIssuesTotal = max(0, (int)(
            $componentSummary['likelyRepeatedTemplateIssuesTotal']
            ?? $componentSummary['likely_repeated_template_issues_total']
            ?? $componentSummary['repeatedTemplateIssuesTotal']
            ?? $componentSummary['repeated_template_issues_total']
            ?? 0
        ));
        $topRepeatedRules = $this->normalizeComponentTopRepeatedRules(
            $componentSummary['topRepeatedRules']
            ?? $componentSummary['top_repeated_rules']
            ?? []
        );
        $note = $this->normalizeNullableString($componentSummary['note'] ?? $componentSummary['disclaimer'] ?? null);

        if ($likelyRepeatedTemplateIssuesTotal === 0 && $topRepeatedRules === [] && $note === null) {
            return [];
        }

        return [
            'title' => 'Likely shared template/component issue',
            'likelyRepeatedTemplateIssuesTotal' => $likelyRepeatedTemplateIssuesTotal,
            'likelyRepeatedTemplateIssuesLabel' => $this->countLabel($likelyRepeatedTemplateIssuesTotal, 'repeated issue'),
            'topRepeatedRules' => $topRepeatedRules,
            'hasTopRepeatedRules' => $topRepeatedRules !== [],
            'note' => $note ?? 'Repeated findings can indicate a shared template or component issue. Review the shared layout before assigning work to editors.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeComponentTopRepeatedRules(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $ruleId = trim((string)$item);
                if ($ruleId !== '') {
                    $normalized[] = [
                        'ruleId' => $ruleId,
                        'issuesTotal' => 0,
                        'issuesLabel' => 'issues',
                        'affectedPagesTotal' => 0,
                        'affectedPagesLabel' => '',
                        'componentHint' => null,
                        'recommendedOwner' => '',
                        'recommendedOwnerLabel' => '',
                        'suggestedAction' => null,
                    ];
                }
                continue;
            }

            $ruleId = trim((string)($item['ruleId'] ?? $item['rule_id'] ?? $item['id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? $item['count'] ?? $item['total'] ?? 0));
            $affectedPagesTotal = max(0, (int)(
                $item['affectedPagesTotal']
                ?? $item['affected_pages_total']
                ?? $item['pagesTotal']
                ?? $item['pages_total']
                ?? $item['pageCount']
                ?? $item['page_count']
                ?? 0
            ));
            $recommendedOwner = $this->normalizeMachineValue($item['recommendedOwner'] ?? $item['recommended_owner'] ?? null);

            $normalized[] = [
                'ruleId' => $ruleId,
                'issuesTotal' => $issuesTotal,
                'issuesLabel' => $this->countLabel($issuesTotal, 'issue'),
                'affectedPagesTotal' => $affectedPagesTotal,
                'affectedPagesLabel' => $affectedPagesTotal > 0 ? $this->countLabel($affectedPagesTotal, 'page') : '',
                'componentHint' => $this->normalizeNullableString($item['componentHint'] ?? $item['component_hint'] ?? null),
                'recommendedOwner' => $recommendedOwner,
                'recommendedOwnerLabel' => $this->formatBadgeLabel($recommendedOwner),
                'suggestedAction' => $this->normalizeNullableString($item['suggestedAction'] ?? $item['suggested_action'] ?? null),
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @param array<string, mixed> $structureSummary
     * @return array<string, mixed>
     */
    private function normalizeStructureSummary(array $structureSummary): array
    {
        if ($structureSummary === []) {
            return [];
        }

        $issuesTotal = max(0, (int)($structureSummary['landmarkIssuesTotal'] ?? $structureSummary['landmark_issues_total'] ?? 0));
        $affectedPagesTotal = max(0, (int)($structureSummary['affectedPagesTotal'] ?? $structureSummary['affected_pages_total'] ?? 0));
        $likelyTemplateIssue = (bool)($structureSummary['likelyTemplateIssue'] ?? $structureSummary['likely_template_issue'] ?? false);
        $recommendation = $this->normalizeNullableString($structureSummary['recommendation'] ?? null);
        $topRules = $this->normalizeStructureTopRules($structureSummary['topRules'] ?? $structureSummary['top_rules'] ?? []);

        if ($issuesTotal === 0 && $affectedPagesTotal === 0 && $recommendation === null && !$likelyTemplateIssue && $topRules === []) {
            return [];
        }

        return [
            'landmarkIssuesTotal' => $issuesTotal,
            'landmarkIssuesLabel' => $issuesTotal === 1 ? '1 landmark/heading issue' : $issuesTotal . ' landmark/heading issues',
            'affectedPagesTotal' => $affectedPagesTotal,
            'affectedPagesLabel' => $affectedPagesTotal === 1 ? '1 page' : $affectedPagesTotal . ' pages',
            'likelyTemplateIssue' => $likelyTemplateIssue,
            'likelyTemplateIssueLabel' => $likelyTemplateIssue ? 'Likely template/layout issue' : 'Review structure in context',
            'recommendation' => $recommendation,
            'topRules' => $topRules,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStructureTopRules(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $ruleId = trim((string)$item);
                if ($ruleId !== '') {
                    $normalized[] = [
                        'ruleId' => $ruleId,
                        'issuesTotal' => 0,
                        'issuesLabel' => 'issues',
                        'affectedPagesTotal' => 0,
                        'affectedPagesLabel' => '',
                    ];
                }
                continue;
            }

            $ruleId = trim((string)($item['ruleId'] ?? $item['rule_id'] ?? $item['id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? $item['count'] ?? 0));
            $affectedPagesTotal = max(0, (int)($item['affectedPagesTotal'] ?? $item['affected_pages_total'] ?? $item['pagesTotal'] ?? $item['pages_total'] ?? 0));

            $normalized[] = [
                'ruleId' => $ruleId,
                'issuesTotal' => $issuesTotal,
                'issuesLabel' => $issuesTotal === 1 ? '1 issue' : $issuesTotal . ' issues',
                'affectedPagesTotal' => $affectedPagesTotal,
                'affectedPagesLabel' => $affectedPagesTotal > 0 ? ($affectedPagesTotal === 1 ? '1 page' : $affectedPagesTotal . ' pages') : '',
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeContrastDetails(array $items): array
    {
        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $suggestion = $this->normalizeContrastSuggestion($item['contrastSuggestion'] ?? $item['contrast_suggestion'] ?? []);
            $actualRatio = $this->normalizeNullableString($item['actualRatio'] ?? $item['actual_ratio'] ?? $suggestion['actualRatio'] ?? null);
            $requiredRatio = $this->normalizeNullableString($item['requiredRatio'] ?? $item['required_ratio'] ?? $suggestion['requiredRatio'] ?? null);
            $foreground = $this->normalizeColorValue($item['foreground'] ?? $item['foregroundColor'] ?? $item['foreground_color'] ?? $suggestion['currentForeground'] ?? null);
            $background = $this->normalizeColorValue($item['background'] ?? $item['backgroundColor'] ?? $item['background_color'] ?? $suggestion['currentBackground'] ?? null);
            $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0));

            if ($actualRatio === null && $requiredRatio === null && $foreground === null && $background === null && $issuesTotal === 0 && $suggestion === []) {
                continue;
            }

            $normalized[] = [
                'actualRatio' => $actualRatio,
                'requiredRatio' => $requiredRatio,
                'foreground' => $foreground,
                'background' => $background,
                'issuesTotal' => $issuesTotal,
                'issuesLabel' => $issuesTotal === 1 ? '1 issue' : $issuesTotal . ' issues',
                'contrastSuggestion' => $suggestion,
                'hasContrastSuggestion' => $suggestion !== [],
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContrastSuggestion(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $foregroundCandidates = $this->normalizeColorCandidates($value['suggestedForegroundCandidates'] ?? $value['suggested_foreground_candidates'] ?? []);
        $backgroundCandidates = $this->normalizeColorCandidates($value['suggestedBackgroundCandidates'] ?? $value['suggested_background_candidates'] ?? []);
        $actualRatio = $this->normalizeNullableString($value['actualRatio'] ?? $value['actual_ratio'] ?? null);
        $requiredRatio = $this->normalizeNullableString($value['requiredRatio'] ?? $value['required_ratio'] ?? null);
        $currentForeground = $this->normalizeColorValue($value['currentForeground'] ?? $value['current_foreground'] ?? null);
        $currentBackground = $this->normalizeColorValue($value['currentBackground'] ?? $value['current_background'] ?? null);
        $note = $this->normalizeNullableString($value['note'] ?? null);

        if ($foregroundCandidates === [] && $backgroundCandidates === [] && $actualRatio === null && $requiredRatio === null && $currentForeground === null && $currentBackground === null && $note === null) {
            return [];
        }

        return [
            'currentForeground' => $currentForeground,
            'currentBackground' => $currentBackground,
            'actualRatio' => $actualRatio,
            'requiredRatio' => $requiredRatio,
            'suggestedForegroundCandidates' => $foregroundCandidates,
            'suggestedBackgroundCandidates' => $backgroundCandidates,
            'hasSuggestedForegroundCandidates' => $foregroundCandidates !== [],
            'hasSuggestedBackgroundCandidates' => $backgroundCandidates !== [],
            'note' => $note ?? 'Candidate colors are generated as an automated remediation aid and must be reviewed in the brand/design context.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeColorCandidates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $candidate) {
            $color = $this->normalizeColorValue($candidate);
            if ($color !== null) {
                $items[$color] = $color;
            }
        }

        return array_slice(array_values($items), 0, 6);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizePriorityFixes(array $items): array
    {
        $normalized = [];
        $rank = 1;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ruleId = trim((string)($item['ruleId'] ?? $item['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $wcagFallback = $this->resolveWcagByRuleId($ruleId);
            $wcagCriterion = $this->normalizeNullableString($item['wcagCriterion'] ?? $item['criterion'] ?? null)
                ?? ($wcagFallback['criterion'] ?? null);
            $wcagLevel = $this->normalizeNullableString($item['wcagLevel'] ?? $item['level'] ?? null)
                ?? ($wcagFallback['level'] ?? null);
            $wcagLabel = $this->normalizeNullableString($item['wcagLabel'] ?? $item['label'] ?? null)
                ?? ($wcagFallback['label'] ?? null);

            $guidance = is_array($item['guidance'] ?? null) ? $item['guidance'] : [];
            $guidanceTitle = $this->normalizeNullableString($item['title'] ?? $guidance['title'] ?? null);
            $shortFix = $this->normalizeNullableString($item['shortFix'] ?? $item['short_fix'] ?? $guidance['shortFix'] ?? $guidance['short_fix'] ?? null);
            $whyItMatters = $this->normalizeNullableString($item['whyItMatters'] ?? $item['why_it_matters'] ?? $guidance['whyItMatters'] ?? $guidance['why_it_matters'] ?? null);
            $howToFix = $this->normalizeNullableString($item['howToFix'] ?? $item['how_to_fix'] ?? $guidance['howToFix'] ?? $guidance['how_to_fix'] ?? null);
            $whoShouldFix = $this->normalizeMachineValue($item['whoShouldFix'] ?? $item['who_should_fix'] ?? $guidance['whoShouldFix'] ?? $guidance['who_should_fix'] ?? null);
            $fixType = $this->normalizeMachineValue($item['fixType'] ?? $item['fix_type'] ?? $guidance['fixType'] ?? $guidance['fix_type'] ?? null);
            $confidence = $this->normalizeMachineValue($item['confidence'] ?? $item['confidence_level'] ?? $guidance['confidence'] ?? $guidance['confidence_level'] ?? null);
            $quickWin = (bool)($item['quickWin'] ?? $item['quick_win'] ?? $guidance['quickWin'] ?? $guidance['quick_win'] ?? false);

            $normalized[] = [
                'rank' => max(1, (int)($item['rank'] ?? $rank)),
                'ruleId' => $ruleId,
                'wcagCriterion' => $wcagCriterion,
                'wcagLevel' => $wcagLevel,
                'wcagLabel' => $wcagLabel,
                'impact' => strtolower(trim((string)($item['impact'] ?? ''))),
                'issuesTotal' => max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0)),
                'affectedPagesTotal' => max(0, (int)($item['affectedPagesTotal'] ?? $item['affected_pages_total'] ?? 0)),
                'help' => trim((string)($item['help'] ?? '')),
                'title' => $guidanceTitle,
                'displayTitle' => $guidanceTitle ?? (trim((string)($item['help'] ?? '')) !== '' ? trim((string)($item['help'] ?? '')) : $ruleId),
                'shortFix' => $shortFix,
                'quickWin' => $quickWin,
                'reason' => trim((string)($item['reason'] ?? '')),
                'guidanceWhyItMatters' => $whyItMatters,
                'guidanceHowToFix' => $howToFix ?? $shortFix ?? self::GUIDANCE_FALLBACK_TEXT,
                'guidanceDetail' => $howToFix,
                'guidanceHasApiText' => $guidanceTitle !== null || $shortFix !== null || $whyItMatters !== null || $howToFix !== null,
                'guidanceIsFallback' => $howToFix === null && $shortFix === null,
                'whoShouldFix' => $whoShouldFix,
                'whoShouldFixLabel' => $this->formatBadgeLabel($whoShouldFix),
                'fixType' => $fixType,
                'fixTypeLabel' => $this->formatBadgeLabel($fixType),
                'confidence' => $confidence,
                'confidenceLabel' => $this->formatBadgeLabel($confidence),
            ];
            $rank++;
        }

        return array_slice($normalized, 0, self::PRIORITY_FIXES_LIMIT);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWcagSummary(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $criterion = trim((string)($row['criterion'] ?? $row['wcagCriterion'] ?? ''));
            if ($criterion === '') {
                continue;
            }

            $level = strtoupper(trim((string)($row['level'] ?? '')));
            $topRules = [];
            $rawTopRules = is_array($row['topRules'] ?? null) ? $row['topRules'] : [];
            foreach ($rawTopRules as $rule) {
                if (is_array($rule)) {
                    $ruleId = trim((string)($rule['ruleId'] ?? $rule['rule_id'] ?? ''));
                    if ($ruleId === '') {
                        continue;
                    }
                    $topRules[] = [
                        'ruleId' => $ruleId,
                        'impact' => strtolower(trim((string)($rule['impact'] ?? ''))),
                        'issuesTotal' => max(0, (int)($rule['issuesTotal'] ?? $rule['issues_total'] ?? 0)),
                    ];
                    continue;
                }

                $ruleId = trim((string)$rule);
                if ($ruleId !== '') {
                    $topRules[] = [
                        'ruleId' => $ruleId,
                        'impact' => '',
                        'issuesTotal' => 0,
                    ];
                }
            }

            $normalized[] = [
                'criterion' => $criterion,
                'level' => $level,
                'label' => trim((string)($row['label'] ?? '')),
                'levelClass' => strtolower($level),
                'issuesTotal' => max(0, (int)($row['issuesTotal'] ?? $row['issues_total'] ?? 0)),
                'affectedPagesTotal' => max(0, (int)($row['affectedPagesTotal'] ?? $row['affected_pages_total'] ?? 0)),
                'topRules' => $topRules,
            ];
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    }


    private function normalizeColorValue(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return null;
        }

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $normalized)) {
            return substr($normalized, 0, 32);
        }

        return strtolower($normalized);
    }

    private function normalizeMachineValue(mixed $value): string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return '';
        }

        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? '';
        $normalized = substr(trim($normalized, '_-'), 0, 50);

        if (in_array($normalized, ['unknown', 'n_a', 'na', 'none', 'not_set', 'undefined'], true)) {
            return '';
        }

        return $normalized;
    }

    private function formatBadgeLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    /**
     * @param array<string, mixed> $reportSummary
     * @return array<string, mixed>
     */
    private function normalizeReportSummary(array $reportSummary): array
    {
        $overallImpact = strtolower(trim((string)($reportSummary['overallImpact'] ?? $reportSummary['overall_impact'] ?? '')));
        $topRecommendation = $this->normalizeNullableString($reportSummary['topRecommendation'] ?? $reportSummary['top_recommendation'] ?? null);
        $automatedCheckNotice = $this->normalizeNullableString($reportSummary['automatedCheckNotice'] ?? $reportSummary['automated_check_notice'] ?? null);
        $manualReviewNotice = $this->normalizeNullableString($reportSummary['manualReviewNotice'] ?? $reportSummary['manual_review_notice'] ?? null);

        if ($overallImpact === '' && $topRecommendation === null && $automatedCheckNotice === null && $manualReviewNotice === null) {
            return [];
        }

        return [
            'overallImpact' => $overallImpact,
            'overallImpactLabel' => $this->formatBadgeLabel($overallImpact),
            'issuesTotal' => max(0, (int)($reportSummary['issuesTotal'] ?? $reportSummary['issues_total'] ?? 0)),
            'affectedPagesTotal' => max(0, (int)($reportSummary['affectedPagesTotal'] ?? $reportSummary['affected_pages_total'] ?? 0)),
            'wcagCriteriaTotal' => max(0, (int)($reportSummary['wcagCriteriaTotal'] ?? $reportSummary['wcag_criteria_total'] ?? 0)),
            'priorityFixesTotal' => max(0, (int)($reportSummary['priorityFixesTotal'] ?? $reportSummary['priority_fixes_total'] ?? 0)),
            'topRecommendation' => $topRecommendation,
            'automatedCheckNotice' => $automatedCheckNotice,
            'manualReviewNotice' => $manualReviewNotice,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeManualReviewChecklist(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $this->normalizeMachineValue($item['id'] ?? null);
            $title = $this->normalizeNullableString($item['title'] ?? null);
            if ($id === '' && $title === null) {
                continue;
            }

            $owner = $this->normalizeMachineValue($item['recommendedOwner'] ?? $item['recommended_owner'] ?? null);
            $status = $this->normalizeMachineValue($item['status'] ?? null);
            if ($status === '') {
                $status = 'needs_review';
            }

            $normalized[] = [
                'id' => $id !== '' ? $id : $this->normalizeMachineValue($title),
                'title' => $title ?? $this->formatBadgeLabel($id),
                'description' => $this->normalizeNullableString($item['description'] ?? null),
                'category' => $this->normalizeMachineValue($item['category'] ?? null),
                'categoryLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($item['category'] ?? null)),
                'recommendedOwner' => $owner,
                'recommendedOwnerLabel' => $this->formatBadgeLabel($owner),
                'status' => $status,
                'statusLabel' => $status === 'needs_review' ? 'Needs review' : $this->formatBadgeLabel($status),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $reportingGroups
     * @return array<string, mixed>
     */
    private function normalizeReportingGroups(array $reportingGroups): array
    {
        $rawGroups = is_array($reportingGroups['groups'] ?? null) ? $reportingGroups['groups'] : [];
        if ($rawGroups === [] && $this->normalizeNullableString($reportingGroups['disclaimer'] ?? null) === null) {
            return [];
        }

        $groups = [];
        foreach ($rawGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupId = $this->normalizeMachineValue($group['groupId'] ?? $group['group_id'] ?? null);
            $title = $this->normalizeNullableString($group['title'] ?? null) ?? $this->formatBadgeLabel($groupId);
            if ($title === '') {
                continue;
            }

            $criteria = [];
            foreach ((array)($group['wcagCriteria'] ?? $group['wcag_criteria'] ?? []) as $criterion) {
                $criterion = $this->normalizeNullableString($criterion);
                if ($criterion !== null) {
                    $criteria[] = $criterion;
                }
            }

            $issuesTotal = max(0, (int)($group['automatedIssuesTotal'] ?? $group['automated_issues_total'] ?? 0));
            $manualReviewRequired = (bool)($group['manualReviewRequired'] ?? $group['manual_review_required'] ?? false);

            $groups[] = [
                'groupId' => $groupId,
                'title' => $title,
                'automatedIssuesTotal' => $issuesTotal,
                'automatedIssuesLabel' => $issuesTotal === 1 ? '1 mapped issue' : $issuesTotal . ' mapped issues',
                'wcagCriteria' => $criteria,
                'wcagCriteriaText' => $criteria !== [] ? implode(', ', $criteria) : 'None mapped',
                'manualReviewRequired' => $manualReviewRequired,
                'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : 'Manual review not flagged',
            ];
        }

        $automatedIssuesTotal = max(0, (int)($reportingGroups['automatedIssuesTotal'] ?? $reportingGroups['automated_issues_total'] ?? 0));
        $standard = $this->normalizeMachineValue($reportingGroups['standard'] ?? null);

        return [
            'standard' => $standard,
            'standardLabel' => $standard !== '' ? strtoupper($standard) : 'WCAG',
            'title' => 'WCAG / BFSG / BITV reporting aid',
            'disclaimer' => $this->normalizeNullableString($reportingGroups['disclaimer'] ?? null),
            'manualReviewRequired' => (bool)($reportingGroups['manualReviewRequired'] ?? $reportingGroups['manual_review_required'] ?? false),
            'manualReviewLabel' => (bool)($reportingGroups['manualReviewRequired'] ?? $reportingGroups['manual_review_required'] ?? false) ? 'Manual review required' : '',
            'automatedIssuesTotal' => $automatedIssuesTotal,
            'automatedIssuesLabel' => $automatedIssuesTotal === 1 ? '1 WCAG-mapped automated finding' : $automatedIssuesTotal . ' WCAG-mapped automated findings',
            'groups' => $groups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildPriorityFixes(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $ruleId = trim((string)($row['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            if (!isset($rules[$ruleId])) {
                $wcag = $this->resolveWcagByRuleId($ruleId);
                $rules[$ruleId] = [
                    'rank' => 0,
                    'ruleId' => $ruleId,
                    'wcagCriterion' => $wcag['criterion'] ?? null,
                    'wcagLevel' => $wcag['level'] ?? null,
                    'wcagLabel' => $wcag['label'] ?? null,
                    'impact' => '',
                    'impactWeight' => 0,
                    'issuesTotal' => 0,
                    'affectedPagesTotal' => 0,
                    'affectedPages' => [],
                    'help' => '',
                    'reason' => '',
                    'guidanceWhyItMatters' => null,
                    'guidanceHowToFix' => null,
                    'guidanceHasApiText' => false,
                    'guidanceIsFallback' => true,
                    'whoShouldFix' => '',
                    'whoShouldFixLabel' => '',
                    'fixType' => '',
                    'fixTypeLabel' => '',
                    'confidence' => '',
                    'confidenceLabel' => '',
                ];
            }

            $impact = strtolower(trim((string)($row['impact'] ?? '')));
            $impactWeight = $this->impactWeight($impact);
            if ($impactWeight > (int)$rules[$ruleId]['impactWeight']) {
                $rules[$ruleId]['impact'] = $impact;
                $rules[$ruleId]['impactWeight'] = $impactWeight;
            }

            $help = trim((string)($row['help'] ?? ''));
            if ($help !== '' && (string)$rules[$ruleId]['help'] === '') {
                $rules[$ruleId]['help'] = $help;
            }

            $whyItMatters = $this->normalizeNullableString($row['guidance_why_it_matters'] ?? null);
            if ($whyItMatters !== null && $rules[$ruleId]['guidanceWhyItMatters'] === null) {
                $rules[$ruleId]['guidanceWhyItMatters'] = $whyItMatters;
            }

            $howToFix = $this->normalizeNullableString($row['guidance_how_to_fix'] ?? null);
            if ($howToFix !== null && $rules[$ruleId]['guidanceHowToFix'] === null) {
                $rules[$ruleId]['guidanceHowToFix'] = $howToFix;
            }

            foreach (['whoShouldFix' => 'who_should_fix', 'fixType' => 'fix_type', 'confidence' => 'confidence'] as $targetKey => $sourceKey) {
                $value = $this->normalizeMachineValue($row[$sourceKey] ?? null);
                if ($value !== '' && (string)$rules[$ruleId][$targetKey] === '') {
                    $rules[$ruleId][$targetKey] = $value;
                    $rules[$ruleId][$targetKey . 'Label'] = $this->formatBadgeLabel($value);
                }
            }

            $rules[$ruleId]['issuesTotal'] += max(1, (int)($row['nodes_count'] ?? 1));

            $pageUid = (int)($row['remote_scan_page'] ?? $row['remote_scan_page_uid'] ?? 0);
            if ($pageUid > 0) {
                $rules[$ruleId]['affectedPages'][$pageUid] = true;
            }
        }

        foreach ($rules as &$rule) {
            $rule['affectedPagesTotal'] = count($rule['affectedPages']);
            if ((int)$rule['affectedPagesTotal'] === 0 && (int)$rule['issuesTotal'] > 0) {
                $rule['affectedPagesTotal'] = 1;
            }
            unset($rule['affectedPages']);
            $rule['reason'] = $this->buildPriorityReason($rule);
            $rule['guidanceHasApiText'] = $rule['guidanceWhyItMatters'] !== null || $rule['guidanceHowToFix'] !== null;
            $rule['guidanceIsFallback'] = $rule['guidanceHowToFix'] === null;
            $rule['guidanceHowToFix'] = $rule['guidanceHowToFix'] ?? self::GUIDANCE_FALLBACK_TEXT;
        }
        unset($rule);

        uasort(
            $rules,
            static fn(array $a, array $b): int => [
                (int)$b['impactWeight'],
                (int)$b['issuesTotal'],
                (int)$b['affectedPagesTotal'],
                (string)$a['ruleId'],
            ] <=> [
                (int)$a['impactWeight'],
                (int)$a['issuesTotal'],
                (int)$a['affectedPagesTotal'],
                (string)$b['ruleId'],
            ]
        );

        $items = array_slice(array_values($rules), 0, self::PRIORITY_FIXES_LIMIT);
        $rank = 1;
        foreach ($items as &$item) {
            $item['rank'] = $rank++;
            unset($item['impactWeight']);
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildWcagSummary(array $rows): array
    {
        $criteria = [];

        foreach ($rows as $row) {
            $ruleId = trim((string)($row['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $wcag = $this->resolveWcagByRuleId($ruleId);
            $criterion = (string)($wcag['criterion'] ?? '');
            if ($criterion === '') {
                continue;
            }

            if (!isset($criteria[$criterion])) {
                $criteria[$criterion] = [
                    'criterion' => $criterion,
                    'level' => (string)($wcag['level'] ?? ''),
                    'label' => (string)($wcag['label'] ?? ''),
                    'levelClass' => strtolower((string)($wcag['level'] ?? '')),
                    'issuesTotal' => 0,
                    'affectedPagesTotal' => 0,
                    'affectedPages' => [],
                    'topRules' => [],
                ];
            }

            $issuesTotal = max(1, (int)($row['nodes_count'] ?? 1));
            $criteria[$criterion]['issuesTotal'] += $issuesTotal;

            $pageUid = (int)($row['remote_scan_page'] ?? $row['remote_scan_page_uid'] ?? 0);
            if ($pageUid > 0) {
                $criteria[$criterion]['affectedPages'][$pageUid] = true;
            }

            if (!isset($criteria[$criterion]['topRules'][$ruleId])) {
                $criteria[$criterion]['topRules'][$ruleId] = [
                    'ruleId' => $ruleId,
                    'impact' => strtolower(trim((string)($row['impact'] ?? ''))),
                    'impactWeight' => $this->impactWeight((string)($row['impact'] ?? '')),
                    'issuesTotal' => 0,
                ];
            }

            $criteria[$criterion]['topRules'][$ruleId]['issuesTotal'] += $issuesTotal;
            $impact = strtolower(trim((string)($row['impact'] ?? '')));
            $impactWeight = $this->impactWeight($impact);
            if ($impactWeight > (int)$criteria[$criterion]['topRules'][$ruleId]['impactWeight']) {
                $criteria[$criterion]['topRules'][$ruleId]['impact'] = $impact;
                $criteria[$criterion]['topRules'][$ruleId]['impactWeight'] = $impactWeight;
            }
        }

        foreach ($criteria as &$criterion) {
            $criterion['affectedPagesTotal'] = count($criterion['affectedPages']);
            if ((int)$criterion['affectedPagesTotal'] === 0 && (int)$criterion['issuesTotal'] > 0) {
                $criterion['affectedPagesTotal'] = 1;
            }
            unset($criterion['affectedPages']);

            $topRules = array_values($criterion['topRules']);
            usort(
                $topRules,
                static fn(array $a, array $b): int => [
                    (int)$b['issuesTotal'],
                    (int)$b['impactWeight'],
                    (string)$a['ruleId'],
                ] <=> [
                    (int)$a['issuesTotal'],
                    (int)$a['impactWeight'],
                    (string)$b['ruleId'],
                ]
            );

            $criterion['topRules'] = array_map(
                static fn(array $rule): array => [
                    'ruleId' => (string)$rule['ruleId'],
                    'impact' => (string)$rule['impact'],
                    'issuesTotal' => (int)$rule['issuesTotal'],
                ],
                array_slice($topRules, 0, 5)
            );
        }
        unset($criterion);

        usort(
            $criteria,
            static fn(array $a, array $b): int => [
                (int)$b['issuesTotal'],
                (int)$b['affectedPagesTotal'],
                (string)$a['criterion'],
            ] <=> [
                (int)$a['issuesTotal'],
                (int)$a['affectedPagesTotal'],
                (string)$b['criterion'],
            ]
        );

        return array_values($criteria);
    }

    /**
     * @return array{criterion:string, level:string, label:string}|null
     */
    private function resolveWcagByRuleId(string $ruleId): ?array
    {
        $normalized = strtolower(trim($ruleId));

        $map = [
            'area-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'image-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'input-image-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'object-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'svg-img-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'video-caption' => ['1.2.2', 'A', 'Captions (Prerecorded)'],
            'audio-caption' => ['1.2.2', 'A', 'Captions (Prerecorded)'],
            'label' => ['1.3.1', 'A', 'Info and Relationships'],
            'form-field-multiple-labels' => ['1.3.1', 'A', 'Info and Relationships'],
            'list' => ['1.3.1', 'A', 'Info and Relationships'],
            'listitem' => ['1.3.1', 'A', 'Info and Relationships'],
            'table-duplicate-name' => ['1.3.1', 'A', 'Info and Relationships'],
            'td-headers-attr' => ['1.3.1', 'A', 'Info and Relationships'],
            'th-has-data-cells' => ['1.3.1', 'A', 'Info and Relationships'],
            'color-contrast' => ['1.4.3', 'AA', 'Contrast (Minimum)'],
            'link-in-text-block' => ['1.4.1', 'A', 'Use of Color'],
            'meta-viewport' => ['1.4.4', 'AA', 'Resize Text'],
            'accesskeys' => ['2.1.1', 'A', 'Keyboard'],
            'marquee' => ['2.2.2', 'A', 'Pause, Stop, Hide'],
            'scrollable-region-focusable' => ['2.1.1', 'A', 'Keyboard'],
            'bypass' => ['2.4.1', 'A', 'Bypass Blocks'],
            'document-title' => ['2.4.2', 'A', 'Page Titled'],
            'link-name' => ['2.4.4', 'A', 'Link Purpose (In Context)'],
            'html-has-lang' => ['3.1.1', 'A', 'Language of Page'],
            'html-lang-valid' => ['3.1.1', 'A', 'Language of Page'],
            'valid-lang' => ['3.1.2', 'AA', 'Language of Parts'],
            'aria-allowed-attr' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-allowed-role' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-command-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-hidden-focus' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-input-field-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-meter-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-progressbar-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-required-attr' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-required-children' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-required-parent' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-roles' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-toggle-field-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-tooltip-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'aria-treeitem-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'button-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'definition-list' => ['4.1.2', 'A', 'Name, Role, Value'],
            'dlitem' => ['4.1.2', 'A', 'Name, Role, Value'],
            'duplicate-id' => ['4.1.1', 'A', 'Parsing'],
            'frame-title' => ['4.1.2', 'A', 'Name, Role, Value'],
            'frame-title-unique' => ['4.1.2', 'A', 'Name, Role, Value'],
            'input-button-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'select-name' => ['4.1.2', 'A', 'Name, Role, Value'],
        ];

        if (!isset($map[$normalized])) {
            return null;
        }

        [$criterion, $level, $label] = $map[$normalized];

        return [
            'criterion' => $criterion,
            'level' => $level,
            'label' => $label,
        ];
    }

    private function impactWeight(string $impact): int
    {
        return match (strtolower(trim($impact))) {
            'critical' => 4,
            'serious' => 3,
            'moderate' => 2,
            'minor' => 1,
            default => 0,
        };
    }

    private function countLabel(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function buildPriorityReason(array $rule): string
    {
        $impact = strtolower(trim((string)($rule['impact'] ?? '')));
        $criterion = trim((string)($rule['wcagCriterion'] ?? ''));
        $level = trim((string)($rule['wcagLevel'] ?? ''));
        $issuesTotal = (int)($rule['issuesTotal'] ?? 0);
        $affectedPagesTotal = (int)($rule['affectedPagesTotal'] ?? 0);

        $impactLabel = $impact !== '' ? ucfirst($impact) : 'Accessibility';
        $scope = $affectedPagesTotal === 1 ? '1 page' : $affectedPagesTotal . ' pages';
        $issues = $issuesTotal === 1 ? '1 occurrence' : $issuesTotal . ' occurrences';

        if ($criterion !== '') {
            $wcagPart = trim('WCAG ' . $level . ' ' . $criterion);
            return sprintf('%s %s issue with %s across %s.', $impactLabel, $wcagPart, $issues, $scope);
        }

        return sprintf('%s issue with %s across %s.', $impactLabel, $issues, $scope);
    }
}
