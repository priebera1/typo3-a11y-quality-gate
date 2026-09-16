<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FormEngine;

use Priebera\A11yQualityGate\Utility\BackendLabelUtility;

/**
 * Labels of the in-editor accessibility feedback (CKEditor plugin and plain HTML wizard).
 *
 * Both run inside FormEngine, where AQG's backend-module label registration does not apply, so the
 * translated labels travel with the editor configuration / wizard markup. Keys map to "rte.<key>" in
 * locallang.xlf; the JavaScript keeps the same English fallbacks.
 */
final class EditorFeedbackLabels
{
    public const LABELS = [
        'accessibilityIssue' => 'Accessibility issue',
        'issueFallback' => 'issue',
        'multipleRules' => 'multiple rules',
        'elementIssues' => '%d issues',
        'issuesOnElement' => '%d issues on this element',
        'issuesOnLine' => '%d issues on this line',
        'checking' => 'Checking accessibility…',
        'checkingHelp' => 'Scanning the current draft for issues.',
        'updating' => 'Updating accessibility status…',
        'updatingHelp' => 'Refreshing highlights after your content change.',
        'loadFailed' => 'Accessibility issues could not be loaded',
        'loadFailedHelp' => "You can keep editing. We'll try again on save.",
        'retry' => 'Retry',
        'passed' => 'Accessibility check passed',
        'passedHelp' => 'No issues found in this field.',
        'lastChecked' => 'Last checked just now',
        'issuesFoundOne' => '%d issue found',
        'issuesFoundOther' => '%d issues found',
        'selectHighlight' => 'Select a highlight to see how to fix it.',
        'howToFix' => 'How to fix',
        'noGuidance' => 'No editor guidance is available for this rule yet. Review the highlighted content and the rule details below.',
        'rule' => 'Rule',
        'location' => 'Location',
        'ignoreIssue' => 'Ignore this issue',
        'ignoreFailed' => 'Ignore failed',
        'showDetails' => 'Show details',
        'hideDetails' => 'Hide details',
        'ignoredReason' => 'Ignored via editor',
        'ignoredReasonHtml' => 'Ignored via HTML editor',
        'severityCritical' => 'Critical',
        'severityCriticalPlural' => 'Criticals',
        'severityWarning' => 'Warning',
        'severityWarningPlural' => 'Warnings',
        'severityInfo' => 'Info',
        'severityNeedsReview' => 'Needs review',
        'plainChecking' => 'Checking HTML accessibility…',
        'plainCheckingHelp' => 'Live validation for this HTML element.',
        'plainUpdatingHelp' => 'Refreshing live issues for this HTML field.',
        'plainPassedHelp' => 'No issues found in this HTML field.',
        'plainLinesMarked' => 'Lines with issues are marked directly in the HTML editor.',
        'htmlSource' => 'HTML source',
        'htmlSourceIssue' => 'HTML source issue',
        'locateInHtml' => 'Locate in HTML',
        'locate' => 'Locate',
        'liveUnavailable' => 'Live validation endpoint is not available.',
        'htmlTooLarge' => 'HTML is too large for live validation. Save the record and run a page scan instead.',
        'liveFailedStatus' => 'Live validation failed (%s).',
        'liveFailed' => 'Live validation failed.',
        'liveFailedRetry' => 'Live validation failed. You can keep editing and try again after saving.',
    ];

    /**
     * @return array<string, string>
     */
    public static function translated(): array
    {
        $labels = [];
        foreach (self::LABELS as $key => $fallback) {
            $labels[$key] = BackendLabelUtility::translate('rte.' . $key, $fallback);
        }

        return $labels;
    }
}
