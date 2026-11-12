import { Plugin } from '@ckeditor/ckeditor5-core';
import { EditorAiSession } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';
import { StagedRemediation, reportInserted } from '@priebera/a11y-quality-gate/remediation/editor-staging.js';
import { EditorAiOutcome } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-outcome.js';
import { EditorAiReviewDialog } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-review-dialog.js';
import { EditorAiWriting } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-writing.js';
import { SHEET_BREAKPOINT, computePopoverPlacement, sheetScrollDelta } from '@priebera/a11y-quality-gate/editor-ai/popover-placement.js';
import {
    applyCkeditorChange,
    canChangeHeadingLevel,
    currentSignature,
    isOnUndoStack,
    isStale as isCkeditorTargetStale,
    matchesSignature,
    locateCkeditorTarget,
    releaseTarget,
} from '@priebera/a11y-quality-gate/editor-ai/ckeditor-target.js';

const MAX_SNIPPET = 120;
const MAX_LIVE_VALIDATION_HTML_SIZE = 500000;

const ELEMENT_FALLBACK_RULES = [
    'rte.link_new_window_no_warning',
    'rte.table_missing_header',
    'rte.table_th_missing_scope',
    'rte.table_missing_caption',
    'rte.img_alt_missing',
    'rte.img_alt_is_filename',
    'rte.svg_missing_title',
    'rte.button_label_missing',
    'rte.duplicate_id',
    'rte.non_descriptive_link',
    'rte.empty_link',
    'rte.link_to_document',
    'rte.link_to_document_missing_notice',
    'rte.empty_heading',
    'rte.heading_hierarchy_jump',
];


/**
 * TYPO3 refuses a route that inherits access from the Accessibility module with an empty 403. AQG's own 403 (record
 * access) carries a JSON answer and stays a visible failure.
 */
async function isModuleAccessDenial(response) {
    if (response.status !== 403) {
        return false;
    }
    try {
        return (await response.clone().text()).trim() === '';
    } catch {
        return false;
    }
}

export default class A11yPlugin extends Plugin {
    static get pluginName() {
        return 'A11yQualityGate';
    }

    init() {
        const editor = this.editor;

        this._summaryElement = null;
        this._markerMeta = new Map();
        this._elementIssueMeta = new Map();
        // Indicator gutter and details panel: nothing opens on caret movement, selection or typing.
        this._gutter = null;
        this._gutterHost = null;
        this._gutterFrame = null;
        this._chipEntries = new WeakMap();
        this._chipBlocks = new WeakMap();
        this._popover = null;
        this._popoverAnchor = null;
        this._popoverEntries = [];
        this._popoverScope = 'block';
        this._outsidePointer = null;
        this._escapeListener = null;
        this._currentIssues = [];
        this._rawIssues = [];
        this._cachedHighlightTimer = null;
        this._validationRefreshTimer = null;
        this._decorationObserver = null;
        this._ignoreDomMutationsUntil = 0;
        this._lastFetchedData = '';
        this._liveValidationAbortController = null;
        this._liveValidationSequence = 0;
        // Whether the shown result is the live check of the current text or, when that is unavailable, the
        // stored result of the last content scan.
        this._resultSource = 'live';
        this._ready = false;
        // TYPO3 refused AQG's endpoints for this user (403, no access to the Accessibility module): the feedback
        // stays off for this editor instance instead of showing an error and asking again after every pause.
        this._accessDenied = false;
        // Editor AI: one session per finding (kept while the popover closes and reopens), the outcome of applied
        // changes, and a live region for announcements.
        this._aiSessions = new Map();
        // Applied, unsaved changes by finding key: the issue and the adapter that holds the locked element.
        this._aiApplied = new Map();
        this._aiOutcome = null;
        this._aiLiveRegion = null;
        this._aiLockSequence = 0;
        // A change approved in AQG's AI fixes queue for exactly this record and field (?aqgRemediation=<item>).
        const stagingConfig = editor.config.get('a11yQualityGate') ?? {};
        this._staged = new StagedRemediation({
            table: 'tt_content',
            uid: stagingConfig.recordUid,
            field: stagingConfig.fieldName,
            label: (key, fallback) => this._label(key, fallback),
            announce: (text) => this._announce(text),
        });
        const aiConfig = (editor.config.get('a11yQualityGate') ?? {}).ai ?? {};
        this._aiWriting = aiConfig.state === 'ready' && aiConfig.writing === true
            ? new EditorAiWriting({
                editor,
                config: aiConfig,
                label: (key, fallback) => this._label(key, fallback),
                announce: (text) => this._announce(text),
                recordUid: (editor.config.get('a11yQualityGate') ?? {}).recordUid,
                fieldName: (editor.config.get('a11yQualityGate') ?? {}).fieldName,
            })
            : null;

        editor.conversion.for('editingDowncast').markerToHighlight({
            model: 'a11yIssue',
            view: ({ markerName }) => {
                const meta = this._markerMeta.get(markerName) ?? {};
                const severity = this._normalizeSeverity(meta.severity);

                return {
                    name: 'mark',
                    classes: [
                        'a11y-highlight',
                        `a11y-${severity}`,
                        'ck-a11y-mark',
                        `ck-a11y-mark--${severity}`,
                    ],
                    attributes: {
                        'data-a11y-marker': markerName,
                        'data-a11y-rule': meta.ruleId ?? '',
                        'data-a11y-fp': meta.fingerprint ?? '',
                        'data-a11y-label': meta.message ?? '',
                    },
                    priority: 7,
                };
            },
        });

        editor.model.document.selection.on('change:range', () => {
            this._onSelectionChange();
        });

        editor.model.document.on('change:data', () => {
            // CKEditor loads the initial content as a data change before the editor is ready. That is not an
            // edit: the first result is the live check started on ready.
            if (!this._ready || this._accessDenied) {
                return;
            }
            this._scheduleCachedHighlightRefresh();
            this._scheduleValidationRefresh();
            this._aiSessions.forEach((session) => session.checkStale());
        });

        editor.on('ready', () => {
            this._installSummaryBar();
            this._installGutter();
            this._installDecorationObserver();
            this._installAiOutcome();
            this._installIssueKeystrokes();
            this._ready = true;
            this._fetchLiveValidation({ initial: true });
        });

        editor.on('destroy', () => {
            this._closePopover({ returnFocus: false });
            if (this._gutterFrame !== null) {
                window.cancelAnimationFrame(this._gutterFrame);
            }
            window.clearTimeout(this._validationRefreshTimer ?? undefined);
            window.clearTimeout(this._cachedHighlightTimer ?? undefined);
            this._liveValidationAbortController?.abort();
            this._aiSessions.forEach((session) => session.destroy());
            this._aiSessions.clear();
        });

        editor.on('a11y:refresh', () => {
            if (this._accessDenied) {
                return;
            }
            this._fetchLiveValidation({ initial: true });
        });
    }

    /**
     * The stored findings of the last content scan for this field: only a fallback while the live check of the
     * current text is unavailable, and shown as such.
     */
    async _fetchAndHighlight() {
        const cfg = this.editor.config.get('a11yQualityGate') ?? {};
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_issues || ajaxUrls['a11y_issues'];

        if (!cfg.recordUid || !cfg.fieldName || !endpoint) {
            console.warn('[A11Y] Missing record configuration', {
                ...cfg,
                endpoint,
            });
            this._renderSummary('error');
            return;
        }

        // A live check started meanwhile (an edit, Retry) is newer than this fallback.
        const validationSequence = this._liveValidationSequence;
        let issues = [];

        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('recordUid', String(cfg.recordUid));
            url.searchParams.set('fieldName', String(cfg.fieldName));
            if (cfg.pageUid) {
                url.searchParams.set('pageUid', String(cfg.pageUid));
            }

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (await isModuleAccessDenial(response)) {
                this._disableForMissingAccess();
                return;
            }

            if (!response.ok) {
                console.warn('[A11Y] Issues fetch failed', response.status, response.url);
                this._renderSummary('error');
                return;
            }

            const data = await response.json();
            issues = Array.isArray(data.issues) ? data.issues : [];
        } catch (error) {
            console.warn('[A11Y] Issues fetch error', error);
            this._renderSummary('error');
            return;
        }

        if (validationSequence !== this._liveValidationSequence) {
            return;
        }

        this._rawIssues = issues;
        this._lastFetchedData = this.editor.getData();
        this._resultSource = 'stored';
        this._syncIssuesWithCurrentEditor();
    }

    _applyHighlights(issues) {
        const model = this.editor.model;

        model.change((writer) => {
            this._clearAllMarkers(writer);

            let markerIndex = 0;
            const editable = this.editor.ui.getEditableElement();

            for (const issue of issues) {
                // Element-based rules are normally decorated directly on matching DOM nodes.
                // If no DOM target exists (for example when CKEditor contains escaped HTML
                // snippets such as "<img ...>" as visible text), fall back to text markers so
                // live issues returned by the server are still visible and not shown as "passed".
                if (this._supportsElementFallback(issue.ruleId ?? '')
                    && editable
                    && this._findDomTargetsForIssue(editable, issue).length > 0
                ) {
                    continue;
                }

                const ranges = this._findRangesForIssue(issue);

                if (ranges.length === 0) {
                    console.warn('[A11Y] No text range found for issue', issue);
                    continue;
                }

                for (const range of ranges) {
                    const markerName = `a11yIssue:${markerIndex++}`;

                    this._markerMeta.set(markerName, this._normalizeIssueData(issue));

                    writer.addMarker(markerName, {
                        range,
                        usingOperation: false,
                        affectsData: false,
                    });
                }
            }
        });
    }

    _supportsElementFallback(ruleId) {
        return ELEMENT_FALLBACK_RULES.includes(ruleId);
    }

    _applyElementDecorations(issues) {
        this._ignoreDomMutationsUntil = Date.now() + 180;
        this._clearElementDecorations();

        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        const groupedIssues = new Map();

        for (const issue of issues) {
            const targets = this._findDomTargetsForIssue(editable, issue);
            const issueData = this._normalizeIssueData(issue);

            for (const target of targets) {
                if (!groupedIssues.has(target)) {
                    groupedIssues.set(target, []);
                }

                groupedIssues.get(target).push(issueData);
            }
        }

        let index = 0;
        for (const [target, targetIssues] of groupedIssues.entries()) {
            const mergedIssueData = this._mergeElementIssueData(targetIssues);
            const elementId = `a11y-element:${index++}`;
            const severity = this._normalizeSeverity(mergedIssueData.severity);
            const label = mergedIssueData.issueCount > 1
                ? `${this._label('elementIssues', '%d issues').replace('%d', String(mergedIssueData.issueCount))} · ${mergedIssueData.ruleId || this._label('multipleRules', 'multiple rules')}`
                : `${this._severityLabel(severity)} · ${mergedIssueData.ruleId || this._label('issueFallback', 'issue')}`;

            this._elementIssueMeta.set(elementId, mergedIssueData);

            target.classList.add('a11y-element-highlight');
            target.classList.add(`a11y-element-${severity}`);
            target.classList.add('ck-a11y-element');
            target.classList.add(`ck-a11y-element--${severity}`);
            target.classList.add(mergedIssueData.issueCount > 1 ? 'ck-a11y-element--multiple' : 'ck-a11y-element--single');
            target.setAttribute('data-a11y-rule', mergedIssueData.ruleId ?? '');
            target.setAttribute('data-a11y-fp', elementId);
            target.setAttribute('data-a11y-label', label);
        }
    }


    _mergeElementIssueData(issues) {
        const normalizedIssues = issues.filter(Boolean);
        const primaryIssue = normalizedIssues
            .slice()
            .sort((a, b) => this._severityRank(b.severity) - this._severityRank(a.severity))[0]
            ?? this._normalizeIssueData({});

        return {
            ...primaryIssue,
            fingerprint: primaryIssue.fingerprint || normalizedIssues.map((issue) => issue.fingerprint || issue.ruleId).join('|'),
            relatedIssues: normalizedIssues,
            issueCount: normalizedIssues.length,
            relatedSummary: this._relatedIssuesSummary(normalizedIssues),
        };
    }

    _relatedIssuesSummary(issues) {
        if (issues.length <= 1) {
            return '';
        }

        const grouped = new Map();
        for (const issue of issues) {
            const key = [
                this._normalizeSeverity(issue.severity),
                issue.ruleId || '',
                issue.message || '',
            ].join('|');

            if (!grouped.has(key)) {
                grouped.set(key, {
                    ...issue,
                    count: 0,
                });
            }

            grouped.get(key).count++;
        }

        return Array.from(grouped.values())
            .map((issue, index) => {
                const prefix = issue.count > 1 ? `${issue.count}× ` : '';
                return `${index + 1}. ${prefix}${this._severityLabel(issue.severity)} · ${issue.message || issue.ruleId || this._label('accessibilityIssue', 'Accessibility issue')}`;
            })
            .join('\n');
    }

    _severityRank(severity) {
        switch (this._normalizeSeverity(severity)) {
            case 'critical':
                return 3;
            case 'warning':
                return 2;
            case 'needs_review':
                return 1.5;
            case 'info':
                return 1;
            default:
                return 0;
        }
    }

    _findDomTargetsForIssue(editable, issue) {
        const ruleId = String(issue.ruleId ?? '');
        const exact = this._exactDomTarget(editable, issue);
        if (exact) {
            return [exact];
        }

        switch (ruleId) {
            case 'rte.link_new_window_no_warning':
                return this._findLinkTargets(editable, issue);

            case 'rte.non_descriptive_link':
                return this._findNonDescriptiveLinkTargets(editable, issue);

            case 'rte.empty_link':
                return this._findEmptyLinkTargets(editable, issue);

            case 'rte.link_to_document':
            case 'rte.link_to_document_missing_notice':
                return this._findDocumentLinkTargets(editable, issue);

            case 'rte.table_missing_header':
                return this._findTablesMissingHeaders(editable, issue);

            case 'rte.table_missing_caption':
                return this._findTablesMissingAccessibleName(editable, issue);

            case 'rte.table_th_missing_scope':
                return Array.from(editable.querySelectorAll('th:not([scope])'));

            case 'rte.img_alt_missing':
                return this._findImagesMissingAlt(editable, issue);

            case 'rte.img_alt_is_filename':
                return this._findImagesWithFilenameAlt(editable, issue);

            case 'rte.svg_missing_title':
                return Array.from(editable.querySelectorAll('svg'));

            case 'rte.button_label_missing':
                return Array.from(editable.querySelectorAll('button'));

            case 'rte.duplicate_id':
                return this._findDuplicateIdTargets(editable);

            case 'rte.empty_heading':
                return this._findEmptyHeadingTargets(editable, issue);

            case 'rte.heading_hierarchy_jump':
                return this._findHeadingTargets(editable, issue);

            default:
                return [];
        }
    }

    /**
     * The finding's own element when the live check identified it (aiTarget: tag, occurrence, total) and the editing
     * view shows exactly that many elements of the tag; identical duplicates then get their own marker.
     */
    _exactDomTarget(editable, issue) {
        const target = issue?.aiTarget;
        if (!target || !target.tag) {
            return null;
        }
        const candidates = Array.from(editable.querySelectorAll(target.tag))
            .filter((element) => !element.closest('.ck-widget__type-around, .ck-widget__resizer, .ck-fake-selection-container'));
        return candidates.length === target.total ? candidates[target.occurrence] ?? null : null;
    }

    _findTablesMissingHeaders(editable, issue) {
        return Array.from(editable.querySelectorAll('table')).filter((table) => {
            return table.querySelector('th') === null;
        });
    }

    _findTablesMissingAccessibleName(editable, issue) {
        return Array.from(editable.querySelectorAll('table')).filter((table) => {
            const caption = table.querySelector('caption');
            const ariaLabel = (table.getAttribute('aria-label') ?? '').trim();
            const ariaLabelledBy = (table.getAttribute('aria-labelledby') ?? '').trim();
            const title = (table.getAttribute('title') ?? '').trim();

            return !caption && ariaLabel === '' && ariaLabelledBy === '' && title === '';
        });
    }

    _findImagesMissingAlt(editable, issue) {
        return Array.from(editable.querySelectorAll('img')).filter((image) => {
            return !image.hasAttribute('alt') || (image.getAttribute('alt') ?? '').trim() === '';
        });
    }

    _findImagesWithFilenameAlt(editable, issue) {
        return Array.from(editable.querySelectorAll('img')).filter((image) => {
            const alt = (image.getAttribute('alt') ?? '').trim().toLowerCase();
            if (alt === '') {
                return false;
            }

            return /\.(jpg|jpeg|png|gif|webp|svg|avif)$/i.test(alt)
                || /^[a-z0-9_-]+\.(jpg|jpeg|png|gif|webp|svg|avif)$/i.test(alt);
        });
    }

    _findLinkTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const hrefMatch = snippet.match(/href="([^"]+)"/i);
        const href = hrefMatch?.[1] ?? '';

        if (href !== '') {
            const matches = Array.from(
                editable.querySelectorAll(`a[href="${CSS.escape(href)}"][target="_blank"]`)
            );
            const snippetText = this._plainText(snippet).toLowerCase();

            if (snippetText !== '') {
                return matches.filter((element) => {
                    const text = (element.textContent ?? '')
                        .replace(/ /g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim()
                        .toLowerCase();

                    return text === snippetText;
                });
            }

            return matches;
        }

        return Array.from(editable.querySelectorAll('a[target="_blank"]'));
    }

    _findNonDescriptiveLinkTargets(editable, issue) {
        const snippetText = this._plainText(issue.snippet ?? issue.contextSnippet ?? '').toLowerCase();

        if (snippetText !== '') {
            const exactMatches = Array.from(editable.querySelectorAll('a')).filter((element) => {
                const text = (element.textContent ?? '')
                    .replace(/\u00a0/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase();

                return text === snippetText;
            });

            if (exactMatches.length > 0) {
                return exactMatches;
            }
        }

        return Array.from(editable.querySelectorAll('a'));
    }

    _findDocumentLinkTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const hrefMatch = snippet.match(/href="([^"]+)"/i) ?? snippet.match(/href='([^']+)'/i);
        const href = hrefMatch?.[1] ?? '';

        if (href !== '') {
            const matches = Array.from(editable.querySelectorAll('a[href]')).filter((element) => {
                return (element.getAttribute('href') ?? '') === href;
            });

            if (matches.length > 0) {
                return matches;
            }
        }

        return Array.from(editable.querySelectorAll('a[href]')).filter((element) => {
            const hrefValue = (element.getAttribute('href') ?? '').split(/[?#]/, 1)[0].toLowerCase();
            return /\.(pdf|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp|rtf|csv)$/.test(hrefValue);
        });
    }

    _findEmptyLinkTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const hrefMatch = snippet.match(/href="([^"]+)"/i);
        const href = hrefMatch?.[1] ?? '';

        if (href !== '') {
            const matches = Array.from(
                editable.querySelectorAll(`a[href="${CSS.escape(href)}"]`)
            );

            if (matches.length > 0) {
                return matches;
            }
        }

        return Array.from(editable.querySelectorAll('a, button')).filter((element) => {
            const text = (element.textContent ?? '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (text !== '') {
                return false;
            }

            if (element.tagName.toLowerCase() === 'a') {
                return true;
            }

            return element.tagName.toLowerCase() === 'button';
        });
    }

    _findDuplicateIdTargets(editable) {
        const seen = new Map();
        const duplicates = [];

        for (const element of editable.querySelectorAll('[id]')) {
            const id = element.getAttribute('id');

            if (!id) {
                continue;
            }

            if (seen.has(id)) {
                duplicates.push(element);
                duplicates.push(seen.get(id));
                continue;
            }

            seen.set(id, element);
        }

        return Array.from(new Set(duplicates));
    }


    _findEmptyHeadingTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const tagName = this._headingTagFromSnippetOrPath(snippet, issue.contextPath ?? '');
        const selector = tagName ? tagName : 'h1,h2,h3,h4,h5,h6';

        return Array.from(editable.querySelectorAll(selector)).filter((element) => {
            const text = (element.textContent ?? '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            return text === '';
        });
    }

    _findHeadingTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const contextPath = String(issue.contextPath ?? '');
        const tagName = this._headingTagFromSnippetOrPath(snippet, contextPath);
        const selector = tagName ? tagName : 'h1,h2,h3,h4,h5,h6';
        const candidates = Array.from(editable.querySelectorAll(selector));
        const snippetText = this._plainText(snippet).toLowerCase();

        if (snippetText !== '') {
            const exactMatches = candidates.filter((element) => {
                const text = (element.textContent ?? '')
                    .replace(/\u00a0/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase();

                return text === snippetText;
            });

            if (exactMatches.length > 0) {
                return exactMatches;
            }
        }

        const pathIndexMatch = contextPath.match(/h[1-6]\[(\d+)]/i);
        if (pathIndexMatch && candidates.length > 0) {
            const index = Math.max(0, parseInt(pathIndexMatch[1], 10) - 1);
            return candidates[index] ? [candidates[index]] : [];
        }

        return candidates;
    }

    _headingTagFromSnippetOrPath(snippet, contextPath) {
        const snippetMatch = String(snippet).match(/<\s*(h[1-6])\b/i);
        if (snippetMatch) {
            return snippetMatch[1].toLowerCase();
        }

        const pathMatch = String(contextPath).match(/\b(h[1-6])(?:\[\d+])?\b/i);
        if (pathMatch) {
            return pathMatch[1].toLowerCase();
        }

        return '';
    }

    _clearElementDecorations() {
        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        for (const element of editable.querySelectorAll('.a11y-element-highlight, .ck-a11y-element')) {
            element.classList.remove(
                'a11y-element-highlight',
                'a11y-element-critical',
                'a11y-element-warning',
                'a11y-element-info',
                'ck-a11y-element',
                'ck-a11y-element--critical',
                'ck-a11y-element--warning',
                'ck-a11y-element--info',
                'ck-a11y-element--multiple',
                'ck-a11y-element--single',
                'is-hover',
                'is-selected'
            );
            element.removeAttribute('data-a11y-rule');
            element.removeAttribute('data-a11y-fp');
            element.removeAttribute('data-a11y-label');
            element.removeAttribute('title');
        }

        this._elementIssueMeta.clear();
    }

    _clearAllMarkers(writer) {
        const markersToRemove = [];

        for (const marker of this.editor.model.markers) {
            if (marker.name.startsWith('a11yIssue:')) {
                markersToRemove.push(marker.name);
            }
        }

        for (const markerName of markersToRemove) {
            writer.removeMarker(markerName);
            this._markerMeta.delete(markerName);
        }
    }

    _findAll(model, searchText) {
        const root = model.document.getRoot();
        const ranges = [];
        const normalizedSearchText = String(searchText ?? '').replace(/\s+/g, ' ').trim();

        if (!normalizedSearchText) {
            return ranges;
        }

        const lowerSearchText = normalizedSearchText.toLowerCase();

        for (const block of root.getChildren()) {
            let blockText = '';
            const positions = [];

            const walker = model.createRangeIn(block).getWalker({ ignoreElementEnd: true });

            for (const { item, previousPosition } of walker) {
                if (!item.is('$textProxy')) {
                    continue;
                }

                for (let i = 0; i < item.data.length; i++) {
                    positions.push(
                        model.createPositionAt(previousPosition.parent, previousPosition.offset + i)
                    );
                    blockText += item.data[i];
                }
            }

            let index = blockText.toLowerCase().indexOf(lowerSearchText);

            while (index !== -1) {
                const startPosition = positions[index];
                const lastPosition = positions[index + lowerSearchText.length - 1];

                if (startPosition && lastPosition) {
                    ranges.push(
                        model.createRange(
                            startPosition,
                            model.createPositionAt(lastPosition.parent, lastPosition.offset + 1)
                        )
                    );
                }

                index = blockText.toLowerCase().indexOf(lowerSearchText, index + 1);
            }
        }

        return ranges;
    }

    _findRangesForIssue(issue) {
        const model = this.editor.model;
        const ranges = [];

        for (const text of this._issueTextCandidates(issue)) {
            for (const range of this._findAll(model, text)) {
                ranges.push(range);
            }

            if (ranges.length > 0) {
                break;
            }
        }

        return ranges;
    }

    _issueTextCandidates(issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '').trim();
        const candidates = [];
        const addCandidate = (candidate) => {
            const value = String(candidate ?? '')
                .replace(/\s+/g, ' ')
                .trim()
                .substring(0, MAX_SNIPPET);

            if (value && !candidates.includes(value)) {
                candidates.push(value);
            }
        };

        addCandidate(this._plainText(snippet));

        const markupText = this._decodeHtmlEntities(snippet)
            .replace(/\s+/g, ' ')
            .trim();

        addCandidate(markupText);

        // DOMDocument serializes void tags as <img ...>, while CKEditor source-mode text
        // may still be visible as <img ... />. Try both variants for escaped-code snippets.
        addCandidate(markupText.replace(/<\s*(img|br|hr|input)([^>]*)>/gi, (match, tagName, attributes) => {
            const cleanedAttributes = String(attributes ?? '').replace(/\s*\/$/, '').trimEnd();
            return `<${tagName}${cleanedAttributes} />`;
        }));

        return candidates;
    }

    _decodeHtmlEntities(value) {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = String(value ?? '');

        return textarea.value;
    }

    _plainText(html) {
        const container = document.createElement('div');
        container.innerHTML = html;

        return (container.textContent ?? '')
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .substring(0, MAX_SNIPPET);
    }

    /**
     * The caret only marks the indicator of its line; nothing opens while the editor types, selects or moves.
     */
    _onSelectionChange() {
        this._markCurrentChip();
    }

    _markCurrentChip() {
        if (!this._gutter) {
            return;
        }

        const block = this._blockAtSelection();
        for (const chip of this._gutter.querySelectorAll('.ck-a11y-chip')) {
            chip.classList.toggle('is-current', block !== null && this._chipBlocks.get(chip) === block);
        }
    }

    _blockAtSelection() {
        const editable = this.editor.ui.getEditableElement();
        const view = this.editor.editing.view;
        const position = view.document.selection.getFirstPosition();
        if (!editable || !position) {
            return null;
        }

        try {
            const domPosition = view.domConverter.viewPositionToDom(position);
            const node = domPosition?.parent ?? null;
            return node instanceof Node ? this._topLevelBlock(editable, node) : null;
        } catch {
            return null;
        }
    }

    _topLevelBlock(editable, node) {
        let block = node instanceof Element ? node : node?.parentElement ?? null;
        if (!block || !editable.contains(block) || block === editable) {
            return null;
        }
        while (block.parentElement && block.parentElement !== editable) {
            block = block.parentElement;
        }

        return block;
    }

    /**
     * A narrow gutter on the right edge of the editing area: one small marker per block with issues (severity icon and
     * count). The marker never covers text (the editable keeps a right padding while it is shown), never takes focus
     * on its own, and opens the details only when it is pressed.
     */
    _installGutter() {
        if (this._gutter) {
            return;
        }

        const editorElement = this.editor.ui.view.element;
        const main = editorElement?.querySelector('.ck-editor__main') ?? null;
        if (!main) {
            return;
        }

        const gutter = document.createElement('div');
        gutter.className = 'ck-a11y-gutter';
        gutter.hidden = true;
        gutter.addEventListener('mousedown', (event) => {
            // Keep the editor's focus and selection on a pointer press; the click opens the details.
            if (event.target instanceof Element && event.target.closest('.ck-a11y-chip')) {
                event.preventDefault();
            }
        });
        gutter.addEventListener('click', (event) => {
            const chip = event.target instanceof Element ? event.target.closest('.ck-a11y-chip') : null;
            if (!chip) {
                return;
            }
            const entries = this._chipEntries.get(chip) ?? [];
            if (this._popover && this._popoverAnchor === chip) {
                this._closePopover({ returnFocus: 'editor' });
                return;
            }
            this._openPopover(entries, chip);
        });
        main.classList.add('ck-a11y-gutter-host');
        main.append(gutter);
        this._gutter = gutter;
        this._gutterHost = main;

        const editable = this.editor.ui.getEditableElement();
        editable?.addEventListener('scroll', () => this._scheduleGutterRender(), { passive: true });
        // A resize keeps the open panel (and its suggestion) and places it again for the new window.
        window.addEventListener('resize', () => {
            this._scheduleGutterRender();
            if (this._popover) {
                window.requestAnimationFrame(() => this._positionPopover());
            }
        }, { passive: true });
        this.editor.ui.on('update', () => this._scheduleGutterRender());

        if (this.editor.plugins.has('SourceEditing')) {
            this.editor.plugins.get('SourceEditing').on('change:isSourceEditingMode', () => {
                this._closePopover({ returnFocus: false });
                this._scheduleGutterRender();
            });
        }
    }

    _isSourceEditingMode() {
        return this.editor.plugins.has('SourceEditing') && this.editor.plugins.get('SourceEditing').isSourceEditingMode === true;
    }

    _scheduleGutterRender() {
        if (this._gutterFrame !== null) {
            return;
        }
        this._gutterFrame = window.requestAnimationFrame(() => {
            this._gutterFrame = null;
            this._renderGutter();
        });
    }

    /**
     * Issues of the editing area grouped by top-level block, each with the element that shows it.
     *
     * @returns {Array<{block: Element, top: number, entries: Array<{issue: object, target: Element, markerName: string}>}>}
     */
    _collectGutterGroups(editable) {
        const groups = new Map();
        const add = (target, issue, markerName = '') => {
            const block = this._topLevelBlock(editable, target);
            if (!block || !issue) {
                return;
            }
            if (!groups.has(block)) {
                groups.set(block, { block, target, entries: [], keys: new Set() });
            }
            const group = groups.get(block);
            const key = markerName !== '' ? `marker:${markerName.replace(/:\d+$/, '')}:${issue.fingerprint}` : `issue:${issue.fingerprint}:${issue.ruleId}`;
            if (group.keys.has(key)) {
                return;
            }
            group.keys.add(key);
            group.entries.push({ issue, target, markerName });
        };

        for (const mark of editable.querySelectorAll('[data-a11y-marker]')) {
            const markerName = mark.getAttribute('data-a11y-marker') ?? '';
            add(mark, this._markerMeta.get(markerName), markerName);
        }
        for (const element of editable.querySelectorAll('.ck-a11y-element[data-a11y-fp]')) {
            const merged = this._elementIssueMeta.get(element.getAttribute('data-a11y-fp') ?? '');
            const issues = merged?.relatedIssues?.length ? merged.relatedIssues : (merged ? [merged] : []);
            issues.forEach((issue) => add(element, issue));
        }

        const editableTop = editable.getBoundingClientRect().top;
        return Array.from(groups.values())
            .map((group) => ({ ...group, top: group.target.getBoundingClientRect().top - editableTop + editable.scrollTop }))
            .sort((a, b) => a.top - b.top);
    }

    _renderGutter() {
        const gutter = this._gutter;
        const editable = this.editor.ui.getEditableElement();
        const editorElement = this.editor.ui.view.element;
        if (!gutter || !editable || !editorElement) {
            return;
        }

        const issueGroups = this._isSourceEditingMode() || this._currentIssues.length === 0 ? [] : this._collectGutterGroups(editable);
        const groups = this._gutterGroupsWithApplied(editable, issueGroups);
        editorElement.classList.toggle('ck-a11y-has-gutter', groups.length > 0);
        gutter.hidden = groups.length === 0;

        // The gutter covers the editing area (not the toolbar) and scrolls with it.
        const hostRect = this._gutterHost.getBoundingClientRect();
        const editableRect = editable.getBoundingClientRect();
        gutter.style.top = `${Math.round(editableRect.top - hostRect.top)}px`;
        gutter.style.height = `${Math.round(editable.clientHeight || editableRect.height)}px`;

        const openKeys = this._popover && this._popoverAnchor instanceof HTMLElement && gutter.contains(this._popoverAnchor)
            ? this._popoverAnchor.dataset.a11yBlockKey ?? ''
            : '';
        gutter.replaceChildren();
        this._chipEntries = new WeakMap();
        this._chipBlocks = new WeakMap();

        let lastTop = -Infinity;
        groups.forEach((group, index) => {
            const entries = group.entries;
            const open = entries.filter((entry) => !entry.applied);
            const severity = open
                .map((entry) => this._normalizeSeverity(entry.issue.severity))
                .sort((a, b) => this._severityRank(b) - this._severityRank(a))[0] ?? 'warning';
            const top = Math.max(group.top - editable.scrollTop + 2, lastTop + 24);
            lastTop = top;

            const chip = document.createElement('button');
            chip.type = 'button';
            chip.tabIndex = -1;
            chip.className = `ck-a11y-chip ck-a11y-chip--${severity}`;
            chip.dataset.a11yBlockKey = String(index);
            chip.style.top = `${Math.round(top)}px`;
            chip.setAttribute('aria-haspopup', 'dialog');
            chip.setAttribute('aria-expanded', 'false');
            const first = (open[0] ?? entries[0]).issue;
            chip.setAttribute('aria-label', entries.length === 1
                ? this._label('chipOne', 'Accessibility: %s. Show how to fix it').replace('%s', `${this._severityLabel(first.severity)}, ${String(first.message || first.ruleId).replace(/[.!?]+$/, '')}`)
                : this._label('chipMany', 'Accessibility: %d issues here. Show how to fix them').replace('%d', String(entries.length)));
            chip.title = entries.length === 1
                ? `${this._severityLabel(first.severity)} · ${first.message || first.ruleId}`
                : this._label('issuesOnElement', '%d issues on this element').replace('%d', String(entries.length));
            const aiEligible = open.some((entry) => EditorAiSession.offersFix(entry.issue, this._aiConfig()));
            if (aiEligible) {
                chip.classList.add('ck-a11y-chip--ai');
                chip.setAttribute('aria-label', `${chip.getAttribute('aria-label')}. ${this._label('aiFixAvailable', 'AI fix available')}`);
            }
            chip.innerHTML = `<span class="ck-a11y-chip__icon" aria-hidden="true"></span>${entries.length > 1 ? `<span class="ck-a11y-chip__count" aria-hidden="true">${entries.length}</span>` : ''}${aiEligible ? '<span class="ck-a11y-chip__wand" aria-hidden="true"></span>' : ''}`;
            // D2 "In text, unsaved": a dashed blue marker; with other issues in the block, a dashed border on theirs.
            if (open.length === 0) {
                chip.className = 'ck-a11y-chip ck-a11y-chip--inserted';
                chip.setAttribute('aria-label', this._label('aiChipInserted', 'AI change applied, not saved. Show details.'));
                chip.title = this._label('aiApplied', 'Applied to the text — not saved yet');
                chip.innerHTML = '<span class="ck-a11y-chip__icon" aria-hidden="true"></span>';
            } else if (open.length < entries.length) {
                chip.classList.add('ck-a11y-chip--has-inserted');
                chip.setAttribute('aria-label', `${chip.getAttribute('aria-label')}. ${this._label('aiChipInserted', 'AI change applied, not saved. Show details.')}`);
            }
            this._chipEntries.set(chip, entries);
            this._chipBlocks.set(chip, group.block);
            gutter.append(chip);
        });

        this._markCurrentChip();
        this._refreshOpenPopover(openKeys);
    }

    /**
     * Keeps an open details panel in step with a new validation result: its issues are shown again when they still
     * exist, otherwise it closes. Focus moves only when it was inside the panel.
     */
    _refreshOpenPopover(blockKey) {
        if (!this._popover) {
            return;
        }
        // The gutter was rebuilt: the panel follows the new marker of its block, also while it is pinned below.
        const renderedChip = blockKey !== '' ? this._gutter?.querySelector(`.ck-a11y-chip[data-a11y-block-key="${CSS.escape(blockKey)}"]`) : null;
        if (renderedChip && this._popoverScope !== 'all') {
            this._popoverAnchor = renderedChip;
            renderedChip.setAttribute('aria-expanded', 'true');
        }
        // An applied AI change keeps its panel (outcome, Undo) until the editor closes it, although the live check no
        // longer reports the finding.
        if (this._popover.querySelector('.aqg-ai[data-state="applied"], .aqg-ai[data-state="generating"], .aqg-ai[data-state="explaining"], .aqg-ai[data-state="stale"]')) {
            return;
        }

        if (this._popoverScope === 'all') {
            if (this._currentIssues.length === 0) {
                this._closePopover({ returnFocus: 'invoker' });
                return;
            }
            this._fillPopover(this._allEntries());
            return;
        }

        const chip = blockKey !== '' ? this._gutter?.querySelector(`.ck-a11y-chip[data-a11y-block-key="${CSS.escape(blockKey)}"]`) : null;
        const fingerprints = new Set(this._popoverEntries.map((entry) => entry.issue.fingerprint));
        const entries = chip ? (this._chipEntries.get(chip) ?? []) : [];
        if (!chip || !entries.some((entry) => fingerprints.has(entry.issue.fingerprint))) {
            this._closePopover({ returnFocus: 'editor' });
            return;
        }
        this._popoverAnchor = chip;
        chip.setAttribute('aria-expanded', 'true');
        this._fillPopover(entries);
    }

    _allEntries() {
        const editable = this.editor.ui.getEditableElement();
        const entries = editable ? this._collectGutterGroups(editable).flatMap((group) => group.entries) : [];
        const seen = new Set(entries.map((entry) => entry.issue.fingerprint));
        // Issues without a place in the text (the server found them, the editor shows no element) are listed too.
        for (const issue of this._currentIssues) {
            const normalized = this._normalizeIssueData(issue);
            if (!seen.has(normalized.fingerprint)) {
                seen.add(normalized.fingerprint);
                entries.push({ issue: normalized, target: null, markerName: '' });
            }
        }

        return entries;
    }

    /**
     * Details of one marker (or of all issues from the summary), on explicit request only: a non-modal panel inside
     * the editor frame, closed by Escape, its close button or a click elsewhere. Focus moves into it and returns to
     * the editor (or the summary button) when it closes.
     */
    _openPopover(entries, anchor, scope = 'block') {
        if (!Array.isArray(entries) || entries.length === 0 || !this._gutterHost) {
            return;
        }

        this._closePopover({ returnFocus: false });

        const popover = document.createElement('div');
        const titleId = `ck-a11y-popover-title-${Math.random().toString(36).slice(2, 8)}`;
        popover.className = 'ck-a11y-popover';
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-modal', 'false');
        popover.setAttribute('aria-labelledby', titleId);
        popover.dataset.titleId = titleId;
        popover.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this._closePopover({ returnFocus: 'invoker' });
            } else if (event.key === 'F8' && !event.altKey && !event.ctrlKey && !event.metaKey) {
                // The opened panel holds the focus: F8 / Shift+F8 keep moving through the markers from here.
                event.preventDefault();
                event.stopPropagation();
                this._openAdjacentIssue(event.shiftKey ? -1 : 1);
            }
        });
        popover.addEventListener('click', (event) => this._onPopoverClick(event));
        popover.addEventListener('aqg-ai:rendered', () => this._positionPopover());

        this._popover = popover;
        this._popoverAnchor = anchor;
        this._popoverScope = scope;
        this._gutterHost.append(popover);
        anchor?.setAttribute('aria-expanded', 'true');
        this._fillPopover(entries);
        this._positionPopover();

        this._outsidePointer = (event) => {
            const target = event.target;
            if (target instanceof Node && (popover.contains(target) || anchor?.contains(target))) {
                return;
            }
            this._closePopover({ returnFocus: false });
        };
        document.addEventListener('mousedown', this._outsidePointer, true);
        // Escape closes the panel wherever focus is, except inside another dialog of the page.
        this._escapeListener = (event) => {
            if (event.key !== 'Escape' || !this._popover) {
                return;
            }
            const active = document.activeElement;
            if (active instanceof Element && !this._popover.contains(active) && active.closest('[role="dialog"], .modal')) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this._closePopover({ returnFocus: this._popover.contains(active) || active === document.body ? 'invoker' : false });
            if (active === document.body || active === null) {
                this.editor.editing.view.focus();
            }
        };
        document.addEventListener('keydown', this._escapeListener, true);

        popover.querySelector('.ck-a11y-popover__title')?.focus({ preventScroll: true });
    }

    _entriesKey(entries) {
        return entries.map((entry) => `${entry.issue.fingerprint}|${entry.issue.ruleId}|${entry.issue.message}|${entry.markerName}`).join('\n');
    }

    _fillPopover(entries) {
        const popover = this._popover;
        if (!popover) {
            return;
        }

        // Editor focus and layout changes re-render the gutter: unchanged issues keep the panel (and its focus) as is.
        const key = this._entriesKey(entries);
        if (popover.dataset.entriesKey === key && popover.childElementCount > 0) {
            this._popoverEntries = entries;
            return;
        }
        const focusWasInside = popover.contains(document.activeElement);
        popover.dataset.entriesKey = key;
        this._popoverEntries = entries;
        const titleId = popover.dataset.titleId ?? '';
        // Atlas 02 · A: one finding opens with its severity badge, the "n of m" pager and the plain rule name as
        // the title; a block or "Show issues" with several findings keeps the count as its title.
        const single = entries.length === 1 && this._popoverScope !== 'all';
        const title = single
            ? this._issueTitle(entries[0].issue)
            : (entries.length === 1
                ? this._label('issuesFoundOne', '%d issue found')
                : this._label('issuesFoundOther', '%d issues found')).replace('%d', String(entries.length));
        const severity = entries
            .map((entry) => this._normalizeSeverity(entry.issue.severity))
            .sort((a, b) => this._severityRank(b) - this._severityRank(a))[0] ?? 'warning';
        const close = `<button type="button" class="ck-a11y-popover__close" data-a11y-popover-close="1" aria-label="${this._labelHtml('close', 'Close')}"><span aria-hidden="true">×</span></button>`;
        const heading = `<h3 class="ck-a11y-popover__title" id="${titleId}" tabindex="-1">${this._escapeHtml(title)}</h3>`;

        // The placement classes belong to _positionPopover(); a refill keeps them.
        const placement = Array.from(popover.classList).filter((name) => /^ck-a11y-popover--(narrow|sheet|above|clamped)$/.test(name));
        popover.className = ['ck-a11y-popover', `ck-a11y-popover--${severity}`, ...placement].join(' ');
        popover.innerHTML = `
            <div class="ck-a11y-popover__head${single ? ' ck-a11y-popover__head--single' : ''}">
                ${single ? this._severityBadgeHtml(severity) : heading}
                ${this._popoverPagerHtml()}
                ${close}
            </div>
            ${single ? heading : ''}
            <ol class="ck-a11y-popover__list">
                ${entries.map((entry, index) => this._popoverItemHtml(entry, index, single)).join('')}
            </ol>
        `;
        this._mountAiSessions(popover, entries);
        if (focusWasInside) {
            popover.querySelector('.ck-a11y-popover__title')?.focus({ preventScroll: true });
        }
    }

    /** The plain rule name of a finding (`rule.<id>.title` via the live check), else its message. */
    _issueTitle(issue) {
        return issue.ruleTitle || issue.message || issue.ruleId || this._label('accessibilityIssue', 'Accessibility issue');
    }

    _severityBadgeHtml(severity) {
        return `<span class="ck-a11y-panel__severity ck-a11y-panel__severity--${severity}"><span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${this._escapeHtml(this._severityLabel(severity))}</span>`;
    }

    /** "2 of 4" with previous/next: the markers of this field, in the order F8 steps through them. */
    _popoverPagerHtml() {
        if (this._popoverScope === 'all' || !this._gutter) {
            return '';
        }
        const chips = Array.from(this._gutter.querySelectorAll('.ck-a11y-chip'));
        const index = chips.indexOf(this._popoverAnchor);
        if (chips.length < 2 || index < 0) {
            return '';
        }
        const position = this._label('popoverPosition', '%1$d of %2$d').replace('%1$d', String(index + 1)).replace('%2$d', String(chips.length));

        return `<span class="ck-a11y-popover__pager">
                <button type="button" class="ck-a11y-popover__step ck-a11y-popover__step--prev" data-a11y-popover-step="-1" aria-label="${this._labelHtml('previousIssue', 'Previous issue')}" aria-keyshortcuts="Shift+F8"><span aria-hidden="true"></span></button>
                <span class="ck-a11y-popover__position">${this._escapeHtml(position)}</span>
                <button type="button" class="ck-a11y-popover__step ck-a11y-popover__step--next" data-a11y-popover-step="1" aria-label="${this._labelHtml('nextIssue', 'Next issue')}" aria-keyshortcuts="F8"><span aria-hidden="true"></span></button>
            </span>`;
    }

    _popoverItemHtml(entry, index, single = false) {
        const issue = entry.issue;
        const severity = this._normalizeSeverity(issue.severity);
        const canIgnore = (issue.fingerprint ?? '') !== '' && !(issue.issueCount > 1);
        const canSelect = entry.target instanceof Element;
        const details = [
            issue.ruleId ? `<div class="ck-a11y-panel__details-row"><span class="ck-a11y-panel__details-key">${this._labelHtml('rule', 'Rule')}</span><span class="ck-a11y-panel__details-val">${this._escapeHtml(issue.ruleId)}</span></div>` : '',
            issue.contextPath ? `<div class="ck-a11y-panel__details-row"><span class="ck-a11y-panel__details-key">${this._labelHtml('location', 'Location')}</span><span class="ck-a11y-panel__details-val">${this._escapeHtml(issue.contextPath)}</span></div>` : '',
            issue.snippet ? `<code class="ck-a11y-panel__snippet">${this._escapeHtml(issue.snippet)}</code>` : '',
        ].join('');

        return `
            <li class="ck-a11y-popover__issue">
                ${single ? '' : `${this._severityBadgeHtml(severity)}<p class="ck-a11y-popover__rule">${this._escapeHtml(this._issueTitle(issue))}</p>`}
                ${issue.message && issue.message !== this._issueTitle(issue) ? `<p class="ck-a11y-popover__message">${this._escapeHtml(issue.message)}</p>` : ''}
                <p class="ck-a11y-popover__fix"><strong>${this._labelHtml('howToFix', 'How to fix')}:</strong> ${this._escapeHtml(issue.hint || this._label('noGuidance', 'No editor guidance is available for this rule yet. Review the highlighted content and the rule details below.'))}</p>
                ${details !== '' ? `<details class="ck-a11y-popover__details"><summary>${this._labelHtml('showDetails', 'Show details')}</summary><div class="ck-a11y-panel__details">${details}</div></details>` : ''}
                ${this._aiSlotHtml(issue, index)}
                <div class="ck-a11y-popover__actions">
                    ${canSelect ? `<button type="button" class="ck-a11y-panel__btn" data-a11y-select="${index}">${this._labelHtml('selectInText', 'Select in text')}</button>` : ''}
                    ${canIgnore ? `<button type="button" class="ck-a11y-panel__btn ck-a11y-panel__btn--ghost" data-a11y-ignore="${index}">${this._labelHtml('ignoreIssue', 'Ignore this issue')}</button>` : ''}
                </div>
            </li>
        `;
    }

    _onPopoverClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        if (target.closest('[data-a11y-popover-close]')) {
            this._closePopover({ returnFocus: 'invoker' });
            return;
        }

        const step = target.closest('[data-a11y-popover-step]');
        if (step) {
            this._openAdjacentIssue(Number(step.getAttribute('data-a11y-popover-step')) < 0 ? -1 : 1);
            this._popover?.querySelector(`[data-a11y-popover-step="${step.getAttribute('data-a11y-popover-step')}"]`)?.focus({ preventScroll: true });
            return;
        }

        const selectButton = target.closest('[data-a11y-select]');
        if (selectButton) {
            const entry = this._popoverEntries[Number.parseInt(selectButton.getAttribute('data-a11y-select') ?? '-1', 10)];
            this._closePopover({ returnFocus: false });
            this._selectEntryInEditor(entry);
            return;
        }

        const ignoreButton = target.closest('[data-a11y-ignore]');
        if (ignoreButton) {
            const entry = this._popoverEntries[Number.parseInt(ignoreButton.getAttribute('data-a11y-ignore') ?? '-1', 10)];
            if (entry) {
                ignoreButton.disabled = true;
                this._postIgnore(entry.issue);
            }
        }
    }

    /** Places the editor's selection on the issue's text or element and gives the editor focus back. */
    _selectEntryInEditor(entry) {
        const editor = this.editor;
        if (!entry) {
            editor.editing.view.focus();
            return;
        }

        try {
            const marker = entry.markerName !== '' ? editor.model.markers.get(entry.markerName) : null;
            if (marker) {
                editor.model.change((writer) => writer.setSelection(marker.getRange()));
            } else if (entry.target instanceof Element) {
                const view = editor.editing.view;
                const viewElement = view.domConverter.mapDomToView(entry.target);
                const modelElement = viewElement ? editor.editing.mapper.toModelElement(viewElement) : null;
                if (modelElement) {
                    editor.model.change((writer) => writer.setSelection(modelElement, 'on'));
                } else {
                    const viewPosition = view.domConverter.domPositionToView(entry.target, 0);
                    const modelPosition = viewPosition ? editor.editing.mapper.toModelPosition(viewPosition) : null;
                    if (modelPosition) {
                        editor.model.change((writer) => writer.setSelection(modelPosition));
                    }
                }
            }
        } catch (error) {
            console.warn('[A11Y] Could not select the issue in the editor', error);
        }

        editor.editing.view.focus();
        editor.editing.view.scrollToTheSelection();
    }

    /**
     * Places the open panel so that all of it, above all its action row, is in view (Atlas 02 · A, audit F-03):
     * below the marker, above it, or shifted into the window; a narrow editor docks it as a bottom sheet with the
     * marked text scrolled into view above it (02 · F, F-38). Called on open, when its content changes (a
     * suggestion is taller than the two buttons it replaces) and on window resize.
     */
    _positionPopover() {
        const popover = this._popover;
        const host = this._gutterHost;
        if (!popover || !host) {
            return;
        }

        const hostRect = host.getBoundingClientRect();
        const anchor = this._popoverAnchor instanceof Element && this._popoverAnchor.isConnected ? this._popoverAnchor : null;
        const sheet = hostRect.width < SHEET_BREAKPOINT;
        popover.classList.toggle('ck-a11y-popover--sheet', sheet);
        popover.classList.toggle('ck-a11y-popover--narrow', sheet);
        popover.classList.remove('ck-a11y-popover--clamped', 'ck-a11y-popover--above');
        popover.style.maxHeight = '';
        if (!sheet && !anchor && this._popoverScope !== 'all' && popover.style.top !== '') {
            // The marker went away (an applied change): the panel stays where it is.
            return;
        }

        const placement = computePopoverPlacement({
            anchorRect: (anchor ?? host).getBoundingClientRect(),
            hostRect,
            popoverHeight: popover.scrollHeight,
            viewportHeight: window.innerHeight,
            topLimit: this._stickyHeaderBottom(),
            fromSummary: this._popoverScope === 'all',
        });
        popover.dataset.placement = placement.mode;
        popover.style.top = placement.top === null ? '' : `${placement.top}px`;
        if (placement.maxHeight !== null && (sheet || popover.scrollHeight > placement.maxHeight)) {
            popover.style.maxHeight = `${placement.maxHeight}px`;
            popover.classList.add('ck-a11y-popover--clamped');
        }
        popover.classList.toggle('ck-a11y-popover--above', placement.mode === 'above');
        if (sheet) {
            this._keepTargetAboveSheet(popover, anchor);
        } else {
            this._removeSheetSpacer();
        }
    }

    /**
     * Room below the editor while the bottom sheet is open: without it a finding near the end of the form could not
     * be scrolled above the sheet. Removed when the sheet closes.
     */
    _ensureSheetSpacer(height) {
        const editorElement = this.editor.ui.view.element;
        if (!editorElement?.parentElement) {
            return;
        }
        if (!this._sheetSpacer) {
            this._sheetSpacer = document.createElement('div');
            this._sheetSpacer.className = 'ck-a11y-sheet-spacer';
            this._sheetSpacer.setAttribute('aria-hidden', 'true');
        }
        if (this._sheetSpacer.previousElementSibling !== editorElement) {
            editorElement.after(this._sheetSpacer);
        }
        this._sheetSpacer.style.height = `${Math.round(height)}px`;
    }

    _removeSheetSpacer() {
        this._sheetSpacer?.remove();
    }

    /**
     * Top of the visible part of the form: below TYPO3's document header (Save, Close), whether it is sticky or the
     * form scrolls in its own container under it. The panel never hides behind it.
     */
    _stickyHeaderBottom() {
        let top = 0;
        const header = document.querySelector('.module-docheader');
        if (header instanceof HTMLElement && ['sticky', 'fixed'].includes(window.getComputedStyle(header).position)) {
            top = header.getBoundingClientRect().bottom;
        }
        for (let element = this._gutterHost?.parentElement ?? null; element && element !== document.body; element = element.parentElement) {
            if (/(auto|scroll)/.test(window.getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight) {
                top = Math.max(top, element.getBoundingClientRect().top);
                break;
            }
        }
        return Math.max(0, top);
    }

    /** The marked text stays visible above the bottom sheet: its block is scrolled into the space above it. */
    _keepTargetAboveSheet(popover, anchor) {
        const block = (anchor && this._chipBlocks?.get(anchor)) || null;
        if (!(block instanceof HTMLElement)) {
            return;
        }
        const sheetHeight = popover.getBoundingClientRect().height;
        const topLimit = this._stickyHeaderBottom();
        this._ensureSheetSpacer(sheetHeight);
        const delta = sheetScrollDelta({ targetRect: block.getBoundingClientRect(), viewportHeight: window.innerHeight, sheetHeight, topLimit });
        if (delta === 0) {
            return;
        }
        // scroll-margin makes every scroll container (editable, FormEngine, document) leave room for the sheet.
        block.style.scrollMarginBottom = `${Math.round(sheetHeight + 12)}px`;
        block.style.scrollMarginTop = `${Math.round(topLimit + 12)}px`;
        block.scrollIntoView({ block: 'nearest' });
        block.style.scrollMarginBottom = '';
        block.style.scrollMarginTop = '';
    }

    /**
     * @param {{returnFocus: 'invoker'|'editor'|false}} options where focus goes when it was inside the panel
     */
    _closePopover({ returnFocus = 'invoker' } = {}) {
        const popover = this._popover;
        if (!popover) {
            return;
        }

        const focusWasInside = popover.contains(document.activeElement);
        const anchor = this._popoverAnchor;
        document.removeEventListener('mousedown', this._outsidePointer, true);
        document.removeEventListener('keydown', this._escapeListener, true);
        this._outsidePointer = null;
        this._escapeListener = null;
        popover.remove();
        this._removeSheetSpacer();
        this._popover = null;
        this._popoverAnchor = null;
        this._popoverEntries = [];
        anchor?.setAttribute('aria-expanded', 'false');

        if (!focusWasInside || returnFocus === false) {
            return;
        }

        // A marker is not in the tab order: going back to it would strand keyboard focus, so the editor takes it.
        if (returnFocus === 'invoker' && anchor instanceof HTMLElement && anchor.isConnected && !anchor.classList.contains('ck-a11y-chip')) {
            anchor.focus();
            return;
        }
        this.editor.editing.view.focus();
    }

    _escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    _installSummaryBar() {
        if (this._summaryElement) {
            return;
        }

        const toolbarElement = this.editor.ui.view.toolbar?.element ?? null;
        const editable = this.editor.ui.getEditableElement();
        const editorElement = toolbarElement?.closest('.ck-editor')
            ?? editable?.closest('.ck-editor')
            ?? editable?.parentElement
            ?? toolbarElement?.parentElement
            ?? null;

        if (!editorElement || !editorElement.parentElement) {
            return;
        }

        const summary = document.createElement('div');
        summary.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--loading';
        summary.setAttribute('role', 'status');
        summary.setAttribute('aria-live', 'polite');
        summary.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            if (this._aiOutcome?.handleClick(event.target)) {
                return;
            }
            if (event.target.closest('[data-a11y-refresh]')) {
                this.editor.fire('a11y:refresh');
                return;
            }
            if (event.target.closest('[data-a11y-next-issue]')) {
                this._openAdjacentIssue(1);
                return;
            }
            const writingButton = event.target.closest('[data-a11y-writing]');
            if (writingButton && this._aiWriting) {
                this._aiWriting.toggleMenu(writingButton);
                return;
            }
            const reviewButton = event.target.closest('[data-a11y-review-fixes]');
            if (reviewButton) {
                this._openReviewDialog(reviewButton);
                return;
            }
            const showButton = event.target.closest('[data-a11y-show-issues]');
            if (showButton) {
                if (this._popover && this._popoverAnchor === showButton) {
                    this._closePopover({ returnFocus: 'invoker' });
                    return;
                }
                this._openPopover(this._allEntries(), showButton, 'all');
            }
        });

        editorElement.parentElement.insertBefore(summary, editorElement);
        this._summaryElement = summary;
        this._renderSummary('loading');
    }

    _renderSummary(state, issues = []) {
        if (!this._summaryElement) {
            return;
        }

        // A screen reader hears the result, not every "Updating…" while the editor types.
        this._summaryElement.setAttribute('aria-busy', state === 'loading' || state === 'updating' ? 'true' : 'false');
        const summaryHadFocus = this._summaryElement.contains(document.activeElement);
        const showIssuesWasOpen = this._popover !== null && this._popoverScope === 'all';
        this._summaryState = state;
        this._summaryIssues = issues;
        this._renderSummaryContent(state, issues);
        this._renderSummaryOutcome();
        const showButton = this._summaryElement.querySelector('[data-a11y-show-issues]');
        if (showIssuesWasOpen && this._popover) {
            if (showButton) {
                this._popoverAnchor = showButton;
                showButton.setAttribute('aria-expanded', 'true');
            }
        }
        if (summaryHadFocus) {
            (showButton ?? this._summaryElement.querySelector('button'))?.focus();
        }
    }

    /**
     * The outcome of applied AI changes lives inside the summary bar ("1 unsaved", "Checking the saved content…",
     * "1 resolved"), never in a block of its own: the editor keeps its height. A settled outcome's reason replaces
     * the bar's generic hint.
     */
    _renderSummaryOutcome() {
        const html = this._aiOutcome?.barHtml() ?? '';
        if (html === '') {
            return;
        }
        // The live check's own failure notes stay readable; only the generic hints give way.
        if (this._aiOutcome.hasHelp() && !['error', 'stored', 'storedOk'].includes(this._summaryState)) {
            this._summaryElement.querySelectorAll('.ck-a11y-summary__help').forEach((help) => help.remove());
        }
        const left = this._summaryElement.querySelector('.ck-a11y-summary__left') ?? this._summaryElement;
        const title = left.querySelector('.ck-a11y-summary__title');
        if (title) {
            // Atlas 02 · A: "1 unsaved" / "1 resolved" takes the title's place, so the bar keeps one line. The
            // count stays for screen readers (the outcome label is announced through the live region).
            title.classList.add('ck-a11y-summary__title--replaced');
            this._summaryElement.classList.add('ck-a11y-summary--has-outcome');
            title.insertAdjacentHTML('afterend', html);
        } else {
            left.insertAdjacentHTML('beforeend', html);
        }
    }

    _renderSummaryContent(state, issues = []) {

        if (state === 'loading' || state === 'updating') {
            const title = state === 'updating'
                ? this._labelHtml('updating', 'Updating accessibility status…')
                : this._labelHtml('checking', 'Checking accessibility…');
            const help = state === 'updating'
                ? this._labelHtml('updatingHelp', 'Refreshing highlights after your content change.')
                : this._labelHtml('checkingHelp', 'Scanning the current draft for issues.');

            this._summaryElement.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--loading';
            this._summaryElement.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__spin" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">${title}</span>
                    <span class="ck-a11y-summary__help">${help}</span>
                </span>
            `;
            return;
        }

        if (state === 'error') {
            this._summaryElement.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--error';
            this._summaryElement.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">${this._labelHtml('loadFailed', 'Accessibility issues could not be loaded')}</span>
                    <span class="ck-a11y-summary__help">${this._labelHtml('loadFailedHelp', "You can keep editing. We'll try again on save.")}</span>
                </span>
                <span class="ck-a11y-summary__right">
                    <button class="ck-a11y-summary__link" type="button" data-a11y-refresh="1">${this._labelHtml('retry', 'Retry')}</button>
                </span>
            `;
            return;
        }

        const retryButton = `<button class="ck-a11y-summary__link" type="button" data-a11y-refresh="1">${this._labelHtml('retry', 'Retry')}</button>`;

        if (state === 'storedOk') {
            this._summaryElement.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--error ck-a11y-summary--stored';
            this._summaryElement.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">${this._labelHtml('storedNoIssues', 'No issues in the last content scan')}</span>
                    <span class="ck-a11y-summary__help">${this._labelHtml('storedNoIssuesHelp', 'Live check unavailable: the current text was not checked.')}</span>
                </span>
                <span class="ck-a11y-summary__right">${retryButton}</span>
            `;
            return;
        }

        if (state === 'ok') {
            this._summaryElement.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--ok';
            this._summaryElement.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">${this._labelHtml('passed', 'Accessibility check passed')}</span>
                    <span class="ck-a11y-summary__help">${this._labelHtml('passedHelp', 'No issues found in this field.')}</span>
                </span>
                <span class="ck-a11y-summary__right">${this._labelHtml('lastChecked', 'Last checked just now')}${this._aiWriting?.buttonHtml() ?? ''}</span>
            `;
            return;
        }

        const counts = this._countSeverities(issues);
        const total = counts.critical + counts.warning + counts.needs_review + counts.info;
        const variant = counts.critical > 0 ? 'critical' : 'issues';
        const stored = state === 'stored';

        this._summaryElement.className = `ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--${variant}${stored ? ' ck-a11y-summary--stored' : ''}`;
        this._summaryElement.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${(total === 1
                    ? this._labelHtml('issuesFoundOne', '%d issue found')
                    : this._labelHtml('issuesFoundOther', '%d issues found')).replace('%d', String(total))}</span>
                <span class="ck-a11y-summary__counts">
                    ${this._summaryCount('critical', counts.critical)}
                    ${this._summaryCount('warning', counts.warning)}
                    ${this._summaryCount('needs_review', counts.needs_review)}
                    ${this._summaryCount('info', counts.info)}
                </span>
            </span>
            <span class="ck-a11y-summary__right">
                <span class="ck-a11y-summary__help">${stored
                    ? this._labelHtml('storedResultsHelp', 'Live check unavailable: these results are from the last content scan and may not match the current text.')
                    : this._labelHtml('selectHighlight', 'Open a marker at the right edge of the text to see how to fix it.')}</span>
                <button class="ck-a11y-summary__link" type="button" data-a11y-show-issues="1" aria-haspopup="dialog" aria-expanded="false">${this._labelHtml('showIssues', 'Show issues')}</button>
                <button class="ck-a11y-summary__link ck-a11y-summary__next" type="button" data-a11y-next-issue="1" aria-keyshortcuts="F8" title="F8 / Shift+F8"><span class="ck-a11y-summary__next-label">${this._labelHtml('nextIssue', 'Next issue')}</span> <kbd>F8</kbd></button>
                ${!stored && this._reviewableIssues(issues).length >= 2 ? `<button class="ck-a11y-summary__link" type="button" data-a11y-review-fixes="1" aria-haspopup="dialog">${this._labelHtml('aiReviewFixes', 'Review fixes (%d)').replace('%d', String(this._reviewableIssues(issues).length))}</button>` : ''}
                ${this._aiWriting?.buttonHtml() ?? ''}
                ${stored ? retryButton : ''}
            </span>
        `;
    }

    _summaryCount(severity, count) {
        if (count <= 0) {
            return '';
        }

        return `<span class="ck-a11y-summary__count ck-a11y-summary__count--${severity}"><span class="ck-a11y-summary__count-dot" aria-hidden="true"></span>${this._escapeHtml(this._severityCountLabel(severity, count))}</span>`;
    }

    _countSeverities(issues) {
        const counts = {
            critical: 0,
            warning: 0,
            info: 0,
            needs_review: 0,
        };

        for (const issue of issues) {
            counts[this._normalizeSeverity(issue.severity)]++;
        }

        return counts;
    }


    _collectLiveValidationHtml(dataHtml) {
        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return dataHtml;
        }

        const domHtml = this._cleanEditableHtmlForValidation(editable);
        if (!domHtml) {
            return dataHtml;
        }

        const dataRelevantCount = this._a11yRelevantElementCount(dataHtml);
        const domRelevantCount = this._a11yRelevantElementCount(domHtml);

        // Prefer editor.getData(), because CKEditor editable DOM may contain widget/helper
        // markup that is not persisted. Use editable DOM only as a strict fallback for cases
        // where getData() contains no accessibility-relevant elements but the visible editor DOM does.
        return dataRelevantCount === 0 && domRelevantCount > 0
            ? domHtml
            : dataHtml;
    }

    _cleanEditableHtmlForValidation(editable) {
        const clone = editable.cloneNode(true);

        clone.querySelectorAll('.ck-widget__selection-handle, .ck-fake-selection-container').forEach((element) => element.remove());
        clone.querySelectorAll('.ck-a11y-mark, .a11y-highlight, .ck-a11y-element, .a11y-element-highlight').forEach((element) => {
            element.classList.remove(
                'ck-a11y-mark',
                'a11y-highlight',
                'ck-a11y-element',
                'a11y-element-highlight',
                'a11y-element-critical',
                'a11y-element-warning',
                'a11y-element-info',
                'ck-a11y-element--critical',
                'ck-a11y-element--warning',
                'ck-a11y-element--info',
                'ck-a11y-element--multiple',
                'ck-a11y-element--single',
                'is-hover',
                'is-selected'
            );
            element.removeAttribute('data-a11y-rule');
            element.removeAttribute('data-a11y-fp');
            element.removeAttribute('data-a11y-label');
        });

        return clone.innerHTML ?? '';
    }

    _a11yRelevantElementCount(html) {
        return (String(html).match(/<\s*(a|img|table|th|td|button|iframe|svg|h[1-6])\b/gi) ?? []).length;
    }

    /**
     * Live check of the current text. `initial` (editor opened, Retry, after Ignore) shows "Checking accessibility…";
     * after an edit the status says it is updating. Without a usable live result the stored findings are shown.
     */
    async _fetchLiveValidation({ initial = false } = {}) {
        if (this._accessDenied) {
            return;
        }
        const cfg = this.editor.config.get('a11yQualityGate') ?? {};
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_rte_validate || ajaxUrls['a11y_rte_validate'];

        if (!endpoint) {
            this._fetchAndHighlight();
            return;
        }

        if (!cfg.recordUid || !cfg.fieldName) {
            this._renderSummary('error');
            return;
        }

        const currentData = this.editor.getData();
        const validationHtml = this._collectLiveValidationHtml(currentData);
        if (validationHtml.length > MAX_LIVE_VALIDATION_HTML_SIZE) {
            console.warn('[A11Y] Live RTE validation skipped because HTML is too large');
            this._renderSummary('error');
            return;
        }

        const validationSequence = ++this._liveValidationSequence;
        this._renderSummary(initial ? 'loading' : 'updating');

        if (this._liveValidationAbortController) {
            this._liveValidationAbortController.abort();
        }

        const abortController = new AbortController();
        this._liveValidationAbortController = abortController;

        let issues = [];

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                signal: abortController.signal,
                body: JSON.stringify({
                    recordUid: cfg.recordUid,
                    fieldName: cfg.fieldName,
                    pageUid: cfg.pageUid ?? 0,
                    html: validationHtml,
                    dataHtml: currentData,
                }),
            });

            if (validationSequence !== this._liveValidationSequence || currentData !== this.editor.getData()) {
                return;
            }

            if (await isModuleAccessDenial(response)) {
                this._disableForMissingAccess();
                return;
            }

            if (!response.ok) {
                console.warn('[A11Y] Live RTE validation failed', response.status, response.url);
                this._fetchAndHighlight();
                return;
            }

            const data = await response.json();
            issues = Array.isArray(data.issues) ? data.issues : [];
            this._serverTime = Number(data.serverTime) || this._serverTime || 0;
            if (validationHtml !== currentData) {
                // The check ran on the editing DOM fallback, not on editor.getData(): its element positions do not
                // describe the editor data, so no AI target can be proven.
                issues = issues.map((issue) => ({ ...issue, aiTarget: null }));
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            if (validationSequence !== this._liveValidationSequence) {
                return;
            }

            console.warn('[A11Y] Live RTE validation error', error);
            this._fetchAndHighlight();
            return;
        } finally {
            if (this._liveValidationAbortController === abortController) {
                this._liveValidationAbortController = null;
            }
        }

        if (validationSequence !== this._liveValidationSequence || currentData !== this.editor.getData()) {
            return;
        }

        this._rawIssues = issues;
        this._lastFetchedData = currentData;
        this._resultSource = 'live';
        this._syncIssuesWithCurrentEditor();
    }


    _syncIssuesWithCurrentEditor() {
        const sourceIssues = Array.isArray(this._rawIssues) ? this._rawIssues : [];
        const visibleIssues = this._filterIssuesForCurrentEditor(sourceIssues);

        this._currentIssues = visibleIssues;
        this._syncAiSessions(visibleIssues);
        this._applyHighlights(visibleIssues);
        this._applyElementDecorations(visibleIssues);
        const stored = this._resultSource === 'stored';
        this._renderSummary(visibleIssues.length > 0 ? (stored ? 'stored' : 'issues') : (stored ? 'storedOk' : 'ok'), visibleIssues);
        this._scheduleGutterRender();
        if (!stored && this._staged?.active && !this._isSourceEditingMode()) {
            this._adoptStagedRemediation(visibleIssues);
        }
    }

    /** Opens the finding of a staged queue change with that change as its suggestion (applied only by Apply). */
    _adoptStagedRemediation(visibleIssues) {
        const issues = visibleIssues.map((issue) => this._normalizeIssueData(issue));
        this._staged.adopt(issues, (issue) => this._aiSessionFor(issue), (issue) => {
            this._renderGutter();
            const key = EditorAiSession.keyFor(issue);
            const chips = Array.from(this._gutter?.querySelectorAll('.ck-a11y-chip') ?? []);
            const chip = chips.find((candidate) => (this._chipEntries.get(candidate) ?? []).some((entry) => EditorAiSession.keyFor(entry.issue) === key));
            if (chip) {
                this._chipBlocks.get(chip)?.scrollIntoView({ block: 'center' });
                this._openPopover(this._chipEntries.get(chip) ?? [], chip);
            }
        });
    }

    _filterIssuesForCurrentEditor(issues) {
        if (!Array.isArray(issues) || issues.length === 0) {
            return [];
        }

        const editable = this.editor.ui.getEditableElement();
        const model = this.editor.model;

        return issues.filter((issue) => {
            if (this._supportsElementFallback(issue.ruleId ?? '')
                && editable
                && this._findDomTargetsForIssue(editable, issue).length > 0
            ) {
                return true;
            }

            const textCandidates = this._issueTextCandidates(issue);
            if (textCandidates.length === 0) {
                return true;
            }

            return textCandidates.some((text) => this._findAll(model, text).length > 0);
        });
    }

    _installDecorationObserver() {
        const editable = this.editor.ui.getEditableElement();
        if (!editable || this._decorationObserver) {
            return;
        }

        this._decorationObserver = new MutationObserver((mutations) => {
            if (Date.now() < this._ignoreDomMutationsUntil || this._currentIssues.length === 0) {
                return;
            }

            const shouldRefresh = mutations.some((mutation) => {
                if (mutation.type === 'childList') {
                    return mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0;
                }

                if (mutation.type === 'attributes') {
                    const target = mutation.target;
                    return target instanceof Element
                        && !target.classList.contains('ck-a11y-element')
                        && !target.classList.contains('a11y-element-highlight')
                        && !target.classList.contains('ck-a11y-mark')
                        && !target.classList.contains('a11y-highlight');
                }

                return false;
            });

            if (shouldRefresh) {
                this._scheduleCachedHighlightRefresh(120);
            }
        });

        this._decorationObserver.observe(editable, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'href', 'target', 'src', 'alt', 'role', 'aria-label', 'aria-labelledby'],
        });
    }

    _scheduleCachedHighlightRefresh(delay = 250) {
        if (this._currentIssues.length === 0) {
            return;
        }

        if (this._cachedHighlightTimer !== null) {
            window.clearTimeout(this._cachedHighlightTimer);
        }

        this._cachedHighlightTimer = window.setTimeout(() => {
            this._cachedHighlightTimer = null;
            this._refreshHighlightsFromCache();
        }, delay);
    }

    _refreshHighlightsFromCache() {
        if (this._rawIssues.length === 0 && this._currentIssues.length === 0) {
            return;
        }

        this._syncIssuesWithCurrentEditor();
    }

    _scheduleValidationRefresh(delay = 1600) {
        if (this._accessDenied) {
            return;
        }
        this._scheduleCachedHighlightRefresh(250);

        if (this._validationRefreshTimer !== null) {
            window.clearTimeout(this._validationRefreshTimer);
        }

        this._validationRefreshTimer = window.setTimeout(() => {
            this._validationRefreshTimer = null;
            this._fetchLiveValidation();
        }, delay);
    }

    /**
     * TYPO3 answers AQG's routes with 403 when the user lacks the Accessibility module (the routes inherit its
     * access). That is no failure of the check: the summary bar and the gutter are removed, pending checks are
     * cancelled, and nothing is requested again for this editor. Editing is unaffected.
     */
    _disableForMissingAccess() {
        this._accessDenied = true;
        window.clearTimeout(this._validationRefreshTimer ?? undefined);
        window.clearTimeout(this._cachedHighlightTimer ?? undefined);
        this._validationRefreshTimer = null;
        this._cachedHighlightTimer = null;
        this._liveValidationAbortController?.abort();
        this._liveValidationAbortController = null;
        this._closePopover({ returnFocus: false });
        this._summaryElement?.remove();
        this._summaryElement = null;
        this._gutter?.remove();
        this._gutter = null;
    }

    _issueDataForDomTarget(target) {
        const elementIssue = this._issueDataForElement(target);
        if (elementIssue) {
            return elementIssue;
        }

        const markerName = target.getAttribute('data-a11y-marker') ?? '';
        if (markerName && this._markerMeta.has(markerName)) {
            return this._markerMeta.get(markerName);
        }

        return null;
    }

    _issueDataForElement(target) {
        const key = target.getAttribute('data-a11y-fp') ?? '';
        return this._elementIssueMeta.get(key) ?? null;
    }

    async _postIgnore(issueData) {
        const fingerprint = issueData?.fingerprint ?? '';
        if (!fingerprint || issueData?.issueCount > 1) {
            return;
        }

        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_ignore || ajaxUrls['a11y_ignore'];
        const cfg = this.editor.config.get('a11yQualityGate') ?? {};

        if (!endpoint) {
            console.warn('[A11Y] Missing TYPO3.settings.ajaxUrls.a11y_ignore');
            return;
        }

        try {
            const payload = {
                fingerprint,
                persistedFingerprint: issueData?.persistedFingerprint ?? '',
                ruleId: issueData?.ruleId ?? '',
                reason: this._label('ignoredReason', 'Ignored via editor'),
                recordUid: cfg.recordUid ?? 0,
                fieldName: cfg.fieldName ?? 'bodytext',
                pageUid: cfg.pageUid ?? 0,
            };

            if (issueData?.isLive) {
                payload.html = this.editor.getData();
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                console.warn('[A11Y] Ignore failed', response.status);
                return;
            }

            this._closePopover({ returnFocus: 'editor' });
            this.editor.fire('a11y:refresh');
        } catch (error) {
            console.warn('[A11Y] Ignore error', error);
        }
    }

    _normalizeIssueData(issue) {
        return {
            fingerprint: issue.fingerprint ?? '',
            persistedFingerprint: issue.persistedFingerprint ?? '',
            ruleId: issue.ruleId ?? '',
            severity: this._normalizeSeverity(issue.severity),
            message: issue.message ?? '',
            ruleTitle: issue.ruleTitle ?? '',
            hint: issue.hint ?? '',
            snippet: issue.snippet ?? issue.contextSnippet ?? '',
            contextPath: issue.contextPath ?? '',
            status: issue.status ?? 0,
            relatedIssues: [],
            issueCount: 1,
            relatedSummary: '',
            isLive: issue.live === true || String(issue.fingerprint ?? '').startsWith('live:'),
            aiTarget: issue.aiTarget && typeof issue.aiTarget === 'object' ? issue.aiTarget : null,
        };
    }

    _normalizeSeverity(severity) {
        const value = String(severity ?? '').toLowerCase();

        if (value === 'critical' || value === 'error' || value === 'serious') {
            return 'critical';
        }

        if (value === 'needs_review' || value === 'needs-review' || value === 'needsreview' || value === 'review') {
            return 'needs_review';
        }

        if (value === 'info' || value === 'notice') {
            return 'info';
        }

        return 'warning';
    }

    // Severity badge text of the summary bar (Atlas 02 · A: "1 Critical", "3 Warnings"), singular and plural keys.
    _severityCountLabel(severity, count) {
        const labels = {
            critical: ['badgeCritical', '%d Critical', 'badgeCritical', '%d Critical'],
            warning: ['badgeWarningOne', '%d Warning', 'badgeWarningOther', '%d Warnings'],
            needs_review: ['badgeNeedsReview', '%d Needs review', 'badgeNeedsReview', '%d Needs review'],
            info: ['badgeInfoOne', '%d Info', 'badgeInfoOther', '%d Info'],
        };
        const [oneKey, oneFallback, otherKey, otherFallback] = labels[this._normalizeSeverity(severity)] || labels.warning;

        return (count === 1 ? this._label(oneKey, oneFallback) : this._label(otherKey, otherFallback)).replace('%d', String(count));
    }

    _severityLabel(severity) {
        switch (this._normalizeSeverity(severity)) {
            case 'critical':
                return this._label('severityCritical', 'Critical');
            case 'info':
                return this._label('severityInfo', 'Info');
            case 'needs_review':
                return this._label('severityNeedsReview', 'Needs review');
            case 'warning':
            default:
                return this._label('severityWarning', 'Warning');
        }
    }

    _aiConfig() {
        const ai = (this.editor.config.get('a11yQualityGate') ?? {}).ai;
        return ai && typeof ai === 'object' ? ai : { state: 'not_configured', rules: [] };
    }

    _isAiEligible(issue) {
        return EditorAiSession.isEligible(issue, this._aiConfig())
            || this._aiSessions.get(EditorAiSession.keyFor(issue))?.hasStaged === true;
    }

    /**
     * The AI part of a finding in the popover: a slot for its session when an AI or rule-based action exists, or one
     * quiet line saying why AI is not offered. Unsupported findings get nothing (no wand, no disabled button).
     */
    _aiSlotHtml(issue, index) {
        const config = this._aiConfig();
        const key = EditorAiSession.keyFor(issue);
        if (this._aiOutcome?.isApplied(key) && this._aiSessions.get(key)?.state !== 'applied') {
            // Applied from the review dialog: no session of this popover holds it, the editor's Ctrl+Z undoes it.
            return `<div class="aqg-ai__applied"><strong>${this._labelHtml('aiApplied', 'Applied to the text — not saved yet')}</strong><p>${this._labelHtml('aiAppliedHelp', 'Use TYPO3 Save to keep it. Ctrl+Z / ⌘Z undoes it.')}</p></div>`;
        }
        if (this._isAiEligible(issue)) {
            return `<div class="aqg-ai-slot" data-aqg-ai-slot="${index}"></div>`;
        }
        if (!issue.aiTarget || !(config.rules ?? []).includes(issue.ruleId)) {
            return '';
        }
        const quiet = EditorAiSession.quietLine(config);
        const line = quiet ? this._label(...quiet) : '';
        return line !== '' ? `<p class="aqg-ai-quiet">${this._escapeHtml(line)}</p>` : '';
    }

    _mountAiSessions(popover, entries) {
        popover.querySelectorAll('[data-aqg-ai-slot]').forEach((slot) => {
            const entry = entries[Number.parseInt(slot.getAttribute('data-aqg-ai-slot') ?? '-1', 10)];
            if (entry) {
                this._aiSessionFor(entry.issue).mount(slot);
            }
        });
    }

    _aiSessionFor(issue) {
        const key = EditorAiSession.keyFor(issue);
        let session = this._aiSessions.get(key);
        if (session) {
            session.updateIssue(issue);
            return session;
        }
        session = new EditorAiSession({
            issue,
            adapter: this._aiAdapter(issue),
            config: this._aiConfig(),
            label: (labelKey, fallback) => this._label(labelKey, fallback),
            announce: (text) => this._announce(text),
            onApplied: (result) => this._onAiApplied(key, result),
            onUndone: () => {
                this._aiApplied.delete(key);
                this._aiOutcome?.removed(key);
            },
        });
        this._aiSessions.set(key, session);
        return session;
    }

    /** Sessions of findings that disappeared are dropped, except applied ones (their Undo stays reachable). */
    _syncAiSessions(issues) {
        const keys = new Set(issues.map((issue) => EditorAiSession.keyFor(this._normalizeIssueData(issue))));
        for (const [key, session] of this._aiSessions.entries()) {
            if (!keys.has(key) && !session.isPinned) {
                session.destroy();
                this._aiSessions.delete(key);
            } else if (keys.has(key)) {
                const issue = issues.find((candidate) => EditorAiSession.keyFor(this._normalizeIssueData(candidate)) === key);
                session.updateIssue(this._normalizeIssueData(issue));
            }
        }
    }

    /** Editor-specific operations of an AI session: exact model target, apply in one batch, undo that batch. */
    _aiAdapter(issue) {
        const editor = this.editor;
        const cfg = editor.config.get('a11yQualityGate') ?? {};
        let lock = null;
        let lockedTarget = null;
        let appliedBatch = null;
        let appliedExpected = null;
        const release = () => {
            if (lock) {
                releaseTarget(editor, lock.markerName);
            }
            lock = null;
        };

        return {
            type: 'ckeditor',
            requestPayload: (current) => ({
                table: 'tt_content',
                recordUid: cfg.recordUid,
                fieldName: cfg.fieldName,
                html: editor.getData(),
                ruleId: current.ruleId,
                contextPath: current.aiTarget?.contextPath ?? '',
                occurrence: current.aiTarget?.occurrence ?? -1,
            }),
            lock: (current) => {
                release();
                if (this._isSourceEditingMode()) {
                    return { ok: false, reason: 'not_provable' };
                }
                const result = locateCkeditorTarget(editor, current.aiTarget, `${++this._aiLockSequence}`);
                if (result.ok) {
                    lock = result;
                    lockedTarget = current.aiTarget;
                }
                return result;
            },
            isStale: () => !lock || isCkeditorTargetStale(editor, lock.markerName, lockedTarget.tag, lock.snapshot),
            preview: (value) => ({ kind: 'text', before: lockedTarget?.currentValue ?? issue.aiTarget?.currentValue ?? '', after: value }),
            apply: (value) => {
                if (!lock || this._isSourceEditingMode()) {
                    return { ok: false, reason: 'stale' };
                }
                const result = applyCkeditorChange(editor, lock.markerName, lockedTarget, lock.snapshot, value);
                if (result.ok) {
                    appliedBatch = result.batch;
                    appliedExpected = result.expected;
                }
                return result;
            },
            undo: () => {
                // Only this change, only while it is still on the undo stack and its element still shows it:
                // otherwise CKEditor's undo would revert some other change.
                const current = lock && appliedExpected ? currentSignature(editor, lock.markerName, appliedExpected.tag) : null;
                if (!appliedBatch || !isOnUndoStack(editor, appliedBatch) || current === null || !matchesSignature(current, appliedExpected)) {
                    appliedBatch = null;
                    this._announce(this._label('aiUndoUseEditorText', 'The text changed after applying. Use Ctrl+Z in the editor.'));
                    return false;
                }
                editor.execute('undo', appliedBatch);
                appliedBatch = null;
                // The text is back to the checked state: lock the same element again for a second Apply.
                const relocked = locateCkeditorTarget(editor, lockedTarget, `${++this._aiLockSequence}`);
                release();
                lock = relocked.ok ? relocked : null;
                return true;
            },
            release,
            // The editing DOM of the top-level block that holds the applied change (for its "unsaved" marker).
            appliedElement: () => {
                const marker = appliedExpected && lock ? editor.model.markers.get(lock.markerName) : null;
                const root = editor.model.document.getRoot?.();
                const block = marker && root ? root.getChild(marker.getStart().path[0]) : null;
                const view = block ? editor.editing.mapper.toViewElement(block) : null;
                return view ? editor.editing.view.domConverter.mapViewToDom(view) ?? null : null;
            },
            canRuleBasedFix: () => canChangeHeadingLevel(editor, issue.aiTarget?.targetHeadingLevel ?? 0),
            describeTarget: (current) => this._describeAiTarget(current.aiTarget),
            focusEditor: () => editor.editing.view.focus(),
        };
    }

    /** Findings with an actionable AI or rule-based fix, in document order. */
    _reviewableIssues(issues) {
        return (issues ?? [])
            .map((issue) => this._normalizeIssueData(issue))
            .filter((issue) => EditorAiSession.offersFix(issue, this._aiConfig()));
    }

    _openReviewDialog(invoker) {
        this._closePopover({ returnFocus: false });
        const editable = this.editor.ui.getEditableElement();
        const ordered = editable ? this._collectGutterGroups(editable).flatMap((group) => group.entries.map((entry) => entry.issue)) : [];
        const reviewable = this._reviewableIssues(this._currentIssues);
        const keyOf = (issue) => EditorAiSession.keyFor(issue);
        const position = new Map(ordered.map((issue, index) => [keyOf(issue), index]));
        reviewable.sort((a, b) => (position.get(keyOf(a)) ?? 999) - (position.get(keyOf(b)) ?? 999));
        new EditorAiReviewDialog({
            entries: reviewable.map((issue) => ({ issue, adapter: this._aiAdapter(issue) })),
            config: this._aiConfig(),
            label: (labelKey, fallback) => this._label(labelKey, fallback),
            announce: (text) => this._announce(text),
            editorType: 'ckeditor',
            onApplied: (result) => this._onAiApplied(keyOf(result.issue), result),
            returnFocus: () => (invoker.isConnected ? invoker : null)
                // The re-rendered "Review fixes" first (a selector list matches in document order, not list order).
                ?? this._summaryElement?.querySelector('[data-a11y-review-fixes]')
                ?? this._summaryElement?.querySelector('[data-a11y-show-issues]')
                ?? (this.editor.editing.view.focus(), null),
        }).open();
    }

    _describeAiTarget(target) {
        if (!target) {
            return '';
        }
        switch (target.kind) {
            case 'link_text':
                return this._label('aiTargetLink', 'This link: “%s”').replace('%s', target.currentValue || target.element?.attributes?.href || '');
            case 'image_alt':
                return this._label('aiTargetImage', 'This image: %s').replace('%s', String(target.element?.attributes?.src ?? '').split(/[?#]/)[0].split('/').pop());
            case 'button_label':
                return this._label('aiTargetButton', 'This button');
            case 'iframe_title':
                return this._label('aiTargetIframe', 'This embedded content: %s').replace('%s', String(target.element?.attributes?.src ?? '').split(/[?#]/)[0]);
            case 'heading_text':
                return this._label('aiTargetHeading', 'This empty heading (H%d)').replace('%d', String(target.headingLevel));
            default:
                return '';
        }
    }

    _onAiApplied(key, result) {
        const stagedItem = Number(result.suggestion?.stagedItem ?? 0);
        if (stagedItem > 0) {
            reportInserted(stagedItem);
        }
        const adapter = result.adapter ?? this._aiSessions.get(key)?.adapter ?? null;
        this._aiApplied.set(key, { issue: result.issue, adapter });
        if (this._aiOutcome) {
            this._aiOutcome.onUndoLast = () => {
                const session = this._aiSessions.get(key);
                if (session?.state === 'applied') {
                    session.undo();
                } else if (adapter?.undo()) {
                    this._aiApplied.delete(key);
                    this._aiOutcome?.removed(key);
                    this._announce(this._label('aiAnnounceUndone', 'Change undone.'));
                }
            };
        }
        this._aiOutcome?.added({
            id: key,
            ruleId: result.issue.ruleId,
            expected: result.expected,
            // Rule-based changes have no suggestion: the server time of the last live check dates them.
            suggestedAt: Number(result.suggestion?.serverTime ?? this._serverTime ?? 0),
        });
    }

    _installAiOutcome() {
        const cfg = this.editor.config.get('a11yQualityGate') ?? {};
        if (!this._summaryElement || !cfg.recordUid || !cfg.fieldName) {
            return;
        }
        // Visually hidden (absolutely positioned): announcements only, no layout of its own.
        const live = document.createElement('span');
        live.className = 'aqg-ai-live';
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('role', 'status');
        this._summaryElement.insertAdjacentElement('afterend', live);
        this._aiLiveRegion = live;
        this._aiOutcome = new EditorAiOutcome({
            recordUid: cfg.recordUid,
            fieldName: cfg.fieldName,
            label: (labelKey, fallback) => this._label(labelKey, fallback),
            announce: (text) => this._announce(text),
            editorType: 'ckeditor',
            onChange: () => {
                this._renderSummary(this._summaryState ?? 'loading', this._summaryIssues ?? []);
                this._renderGutter();
            },
        });
        this._aiOutcome.bindForm(this._summaryElement.closest('form'));
        this._aiOutcome.verifyOnLoad();
    }

    /**
     * Markers of applied AI changes ("In text, unsaved": dashed blue). The live check no longer reports their finding,
     * so they are placed at the block of the locked element; the popover keeps the session with its Undo.
     *
     * @returns {Array<{block: Element, entry: {issue: object, target: null, markerName: string, applied: true}, key: string}>}
     */
    _appliedGutterEntries(editable) {
        if (!this._aiOutcome || this._isSourceEditingMode()) {
            return [];
        }
        const entries = [];
        for (const [key, applied] of this._aiApplied.entries()) {
            if (!this._aiOutcome.isApplied(key)) {
                continue;
            }
            let element = null;
            try {
                element = applied.adapter?.appliedElement?.() ?? null;
            } catch {
                element = null;
            }
            const block = element ? this._topLevelBlock(editable, element) : null;
            if (block) {
                entries.push({ block, key, entry: { issue: applied.issue, target: null, markerName: '', applied: true } });
            }
        }
        return entries;
    }

    /** Issue groups plus applied changes: an applied finding replaces its own (not yet re-checked) issue entry. */
    _gutterGroupsWithApplied(editable, groups) {
        const applied = this._appliedGutterEntries(editable);
        if (applied.length === 0) {
            return groups;
        }
        const appliedKeys = new Set(applied.map((item) => item.key));
        const byBlock = new Map();
        for (const group of groups) {
            const entries = group.entries.filter((entry) => !appliedKeys.has(EditorAiSession.keyFor(entry.issue)));
            if (entries.length > 0) {
                byBlock.set(group.block, { ...group, entries });
            }
        }
        const editableTop = editable.getBoundingClientRect().top;
        for (const item of applied) {
            if (!byBlock.has(item.block)) {
                byBlock.set(item.block, {
                    block: item.block,
                    target: item.block,
                    entries: [],
                    top: item.block.getBoundingClientRect().top - editableTop + editable.scrollTop,
                });
            }
            byBlock.get(item.block).entries.push(item.entry);
        }
        return Array.from(byBlock.values()).sort((a, b) => a.top - b.top);
    }

    _announce(text) {
        if (!this._aiLiveRegion || !text) {
            return;
        }
        this._aiLiveRegion.textContent = '';
        window.setTimeout(() => {
            if (this._aiLiveRegion) {
                this._aiLiveRegion.textContent = text;
            }
        }, 30);
    }

    /** F8 / Shift+F8 open the next / previous marker's popover (markers are not in the tab order). */
    _installIssueKeystrokes() {
        if (!this.editor.keystrokes) {
            return;
        }
        this.editor.keystrokes.set('F8', (data, cancel) => {
            this._openAdjacentIssue(1);
            cancel();
        });
        this.editor.keystrokes.set('Shift+F8', (data, cancel) => {
            this._openAdjacentIssue(-1);
            cancel();
        });
    }

    _openAdjacentIssue(direction) {
        this._renderGutter();
        const chips = Array.from(this._gutter?.querySelectorAll('.ck-a11y-chip') ?? []);
        if (chips.length === 0) {
            this._announce(this._label('passed', 'Accessibility check passed'));
            return;
        }
        const openIndex = this._popover ? chips.indexOf(this._popoverAnchor) : -1;
        let index;
        if (openIndex >= 0) {
            index = (openIndex + direction + chips.length) % chips.length;
        } else {
            const block = this._blockAtSelection();
            const current = chips.findIndex((chip) => this._chipBlocks.get(chip) === block);
            if (current >= 0) {
                index = direction > 0 ? current : (current - 1 + chips.length) % chips.length;
            } else {
                const editable = this.editor.ui.getEditableElement();
                const selectionTop = block?.getBoundingClientRect().top ?? -Infinity;
                index = direction > 0
                    ? Math.max(0, chips.findIndex((chip) => (this._chipBlocks.get(chip)?.getBoundingClientRect().top ?? 0) > selectionTop))
                    : chips.length - 1;
                if (!editable) {
                    index = 0;
                }
            }
        }
        const chip = chips[index];
        this._chipBlocks.get(chip)?.scrollIntoView({ block: 'nearest' });
        this._openPopover(this._chipEntries.get(chip) ?? [], chip);
    }

    /**
     * Translated labels from the editor configuration (RteConfigurationListener).
     */
    _labels() {
        const labels = (this.editor.config.get('a11yQualityGate') ?? {}).labels;
        return labels && typeof labels === 'object' ? labels : {};
    }

    _label(key, fallback) {
        const value = this._labels()[key];
        return typeof value === 'string' && value !== '' ? value : fallback;
    }

    _labelHtml(key, fallback) {
        return String(this._label(key, fallback))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}
