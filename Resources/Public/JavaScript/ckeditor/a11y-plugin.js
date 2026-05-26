import { Plugin } from '@ckeditor/ckeditor5-core';
import { ContextualBalloon } from '@ckeditor/ckeditor5-ui';
import A11yPanelView from '@priebera/a11y-quality-gate/ckeditor/a11y-panel.js';

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

export default class A11yPlugin extends Plugin {
    static get pluginName() {
        return 'A11yQualityGate';
    }

    static get requires() {
        return [ContextualBalloon];
    }

    init() {
        const editor = this.editor;

        this._balloon = editor.plugins.get(ContextualBalloon);
        this._panelView = null;
        this._summaryElement = null;
        this._activeElementTarget = null;
        this._markerMeta = new Map();
        this._elementIssueMeta = new Map();
        this._editableClickBound = false;
        this._hidePanelTimer = null;
        this._panelHoverBound = false;
        this._activeHighlightTarget = null;
        this._panelPinned = false;
        this._currentIssues = [];
        this._rawIssues = [];
        this._cachedHighlightTimer = null;
        this._validationRefreshTimer = null;
        this._decorationObserver = null;
        this._ignoreDomMutationsUntil = 0;
        this._lastFetchedData = '';
        this._liveValidationAbortController = null;
        this._liveValidationSequence = 0;

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
            this._scheduleCachedHighlightRefresh();
            this._scheduleValidationRefresh();
        });

        editor.on('ready', () => {
            this._installSummaryBar();
            this._bindEditableEvents();
            this._installDecorationObserver();
            this._fetchAndHighlight();
        });

        editor.on('a11y:refresh', () => {
            this._fetchAndHighlight();
        });
    }

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

        this._renderSummary('loading');

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

        this._rawIssues = issues;
        this._lastFetchedData = this.editor.getData();
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
                ? `${mergedIssueData.issueCount} issues · ${mergedIssueData.ruleId || 'multiple rules'}`
                : `${this._severityLabel(severity)} · ${mergedIssueData.ruleId || 'issue'}`;

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
                return `${index + 1}. ${prefix}${this._severityLabel(issue.severity)} · ${issue.message || issue.ruleId || 'Accessibility issue'}`;
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
        this._activeElementTarget = null;
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

    _onSelectionChange() {
        const markerName = this._getSelectedMarkerName();

        if (!markerName) {
            if (!this._activeElementTarget) {
                this._hidePanel();
            }
            return;
        }

        this._activeElementTarget = null;
        this._activeHighlightTarget = null;
        this._clearSelectedElementState();

        const issueData = this._markerMeta.get(markerName) ?? null;

        if (issueData) {
            this._showPanel(issueData);
            return;
        }

        this._hidePanel();
    }

    _getSelectedMarkerName() {
        const selection = this.editor.model.document.selection;
        const position = selection.getFirstPosition();

        if (!position) {
            return null;
        }

        for (const marker of this.editor.model.markers) {
            if (!marker.name.startsWith('a11yIssue:')) {
                continue;
            }

            if (marker.getRange().containsPosition(position)) {
                return marker.name;
            }
        }

        return null;
    }

    _showPanel(issueData, targetElement = null, options = {}) {
        this._cancelHidePanel();

        if (options.pinned !== undefined) {
            this._panelPinned = options.pinned;
        }

        if (!this._panelView) {
            this._panelView = new A11yPanelView(this.editor.locale);
            this._panelView.on('ignore', (event, issueData) => {
                this._postIgnore(issueData);
            });
            this._panelView.on('details', () => {
                this._togglePanelDetails();
            });
        }

        const previousFingerprint = this._panelView.issueData?.fingerprint ?? '';
        this._panelView.set('issueData', issueData);

        if (previousFingerprint !== (issueData?.fingerprint ?? '')) {
            this._collapsePanelDetails();
        }

        if (targetElement) {
            this._activeElementTarget = targetElement;
        }

        if (!this._balloon.hasView(this._panelView)) {
            this._balloon.add({
                view: this._panelView,
                position: this._balloonPosition(targetElement),
            });
            this._bindPanelHoverEvents();
            return;
        }

        this._balloon.updatePosition(this._balloonPosition(targetElement));
        this._bindPanelHoverEvents();
    }

    _scheduleHidePanel(delay = 120) {
        if (this._panelPinned) {
            return;
        }

        this._cancelHidePanel();
        this._hidePanelTimer = window.setTimeout(() => {
            this._hidePanel();
        }, delay);
    }

    _cancelHidePanel() {
        if (this._hidePanelTimer !== null) {
            window.clearTimeout(this._hidePanelTimer);
            this._hidePanelTimer = null;
        }
    }

    _bindPanelHoverEvents() {
        if (this._panelHoverBound || !this._panelView?.element) {
            return;
        }

        this._panelView.element.addEventListener('mouseenter', () => {
            this._cancelHidePanel();
        });

        this._panelView.element.addEventListener('mouseleave', () => {
            if (this._panelPinned) {
                return;
            }

            this._activeHighlightTarget?.classList.remove('is-hover', 'is-selected');
            this._activeHighlightTarget = null;
            this._scheduleHidePanel(180);
        });

        this._panelHoverBound = true;
    }

    _hidePanel() {
        this._cancelHidePanel();
        this._panelPinned = false;
        this._activeElementTarget = null;
        this._activeHighlightTarget = null;
        this._clearSelectedElementState();

        if (this._panelView && this._balloon.hasView(this._panelView)) {
            this._balloon.remove(this._panelView);
        }
    }

    _balloonPosition(targetElement = null) {
        if (targetElement) {
            return {
                target: targetElement,
            };
        }

        const view = this.editor.editing.view;
        const range = view.document.selection.getFirstRange();

        return {
            target: range ? () => view.domConverter.viewRangeToDom(range) : undefined,
        };
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
            if (event.target instanceof Element && event.target.closest('[data-a11y-refresh]')) {
                this.editor.fire('a11y:refresh');
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

        if (state === 'loading' || state === 'updating') {
            const title = state === 'updating' ? 'Updating accessibility status…' : 'Checking accessibility…';
            const help = state === 'updating'
                ? 'Refreshing highlights after your content change.'
                : 'Scanning the current draft for issues.';

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
                    <span class="ck-a11y-summary__title">Accessibility issues could not be loaded</span>
                    <span class="ck-a11y-summary__help">You can keep editing. We'll try again on save.</span>
                </span>
                <span class="ck-a11y-summary__right">
                    <button class="ck-a11y-summary__link" type="button" data-a11y-refresh="1">Retry</button>
                </span>
            `;
            return;
        }

        if (state === 'ok') {
            this._summaryElement.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--ok';
            this._summaryElement.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">Accessibility check passed</span>
                    <span class="ck-a11y-summary__help">No issues found in this field.</span>
                </span>
                <span class="ck-a11y-summary__right">Last checked just now</span>
            `;
            return;
        }

        const counts = this._countSeverities(issues);
        const total = counts.critical + counts.warning + counts.needs_review + counts.info;
        const variant = counts.critical > 0 ? 'critical' : 'issues';

        this._summaryElement.className = `ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--${variant}`;
        this._summaryElement.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${total} ${total === 1 ? 'issue' : 'issues'} found</span>
                <span class="ck-a11y-summary__counts">
                    ${this._summaryCount('critical', counts.critical)}
                    ${this._summaryCount('warning', counts.warning)}
                    ${this._summaryCount('needs_review', counts.needs_review)}
                    ${this._summaryCount('info', counts.info)}
                </span>
            </span>
            <span class="ck-a11y-summary__right">
                <span class="ck-a11y-summary__help">Select a highlight to see how to fix it.</span>
            </span>
        `;
    }

    _summaryCount(severity, count) {
        if (count <= 0) {
            return '';
        }

        const label = severity === 'info' || severity === 'needs_review'
            ? this._severityLabel(severity)
            : `${this._severityLabel(severity)}${count === 1 ? '' : 's'}`;

        return `
            <span class="ck-a11y-summary__count ck-a11y-summary__count--${severity}">
                <span class="ck-a11y-summary__count-dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__count-num">${count}</span> ${label}
            </span>
        `;
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

    async _fetchLiveValidation() {
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
        this._renderSummary('updating');

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

            if (!response.ok) {
                console.warn('[A11Y] Live RTE validation failed', response.status, response.url);
                this._fetchAndHighlight();
                return;
            }

            const data = await response.json();
            issues = Array.isArray(data.issues) ? data.issues : [];
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
        this._syncIssuesWithCurrentEditor();
    }


    _syncIssuesWithCurrentEditor() {
        const sourceIssues = Array.isArray(this._rawIssues) ? this._rawIssues : [];
        const visibleIssues = this._filterIssuesForCurrentEditor(sourceIssues);

        this._currentIssues = visibleIssues;
        this._applyHighlights(visibleIssues);
        this._applyElementDecorations(visibleIssues);

        if (visibleIssues.length === 0) {
            this._hidePanel();
        }

        this._renderSummary(visibleIssues.length > 0 ? 'issues' : 'ok', visibleIssues);
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
        this._scheduleCachedHighlightRefresh(250);

        if (this._validationRefreshTimer !== null) {
            window.clearTimeout(this._validationRefreshTimer);
        }

        this._validationRefreshTimer = window.setTimeout(() => {
            this._validationRefreshTimer = null;
            this._fetchLiveValidation();
        }, delay);
    }

    _bindEditableEvents() {
        if (this._editableClickBound) {
            return;
        }

        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        editable.addEventListener('mouseover', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.a11y-highlight, .ck-a11y-mark, .a11y-element-highlight, .ck-a11y-element')
                : null;

            if (!target) {
                if (this._activeHighlightTarget && !this._isPanelElement(event.relatedTarget)) {
                    this._scheduleHidePanel(120);
                }
                return;
            }

            this._cancelHidePanel();

            const issueData = this._issueDataForDomTarget(target);
            if (!issueData) {
                return;
            }

            this._activeHighlightTarget = target;
            target.classList.add('is-hover');
            this._clearSelectedElementState(target);
            target.classList.add('is-selected');
            this._showPanel(issueData, target, { pinned: false });
        });

        editable.addEventListener('mouseout', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.a11y-highlight, .ck-a11y-mark, .a11y-element-highlight, .ck-a11y-element')
                : null;

            if (!target) {
                return;
            }

            if (event.relatedTarget instanceof Node && (target.contains(event.relatedTarget) || this._isPanelElement(event.relatedTarget))) {
                return;
            }

            target.classList.remove('is-hover', 'is-selected');
            if (this._activeHighlightTarget === target) {
                this._activeHighlightTarget = null;
            }
            this._scheduleHidePanel(420);
        });

        editable.addEventListener('mouseleave', (event) => {
            if (this._isPanelElement(event.relatedTarget)) {
                return;
            }
            this._scheduleHidePanel(220);
        });

        editable.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.a11y-highlight, .ck-a11y-mark, .a11y-element-highlight, .ck-a11y-element')
                : null;

            if (!target) {
                this._panelPinned = false;
                this._scheduleHidePanel(80);
                return;
            }

            const issueData = this._issueDataForDomTarget(target);
            if (!issueData) {
                return;
            }

            this._clearSelectedElementState(target);
            target.classList.add('is-selected');
            this._activeHighlightTarget = target;

            if (this._isNativeEditorInteraction(event.target)) {
                // Keep native CKEditor behaviour for links/widgets. The AQG panel stays visible,
                // but we do not prevent the editor's own link/table/image UI from opening.
                this._showPanel(issueData, target, { pinned: false });
                this._scheduleCachedHighlightRefresh(180);
                return;
            }

            this._showPanel(issueData, target, { pinned: true });
            event.preventDefault();
            event.stopPropagation();
        });

        this._editableClickBound = true;
    }

    _isPanelElement(node) {
        return node instanceof Node && !!this._panelView?.element?.contains(node);
    }

    _collapsePanelDetails() {
        if (!this._panelView?.element) {
            return;
        }

        this._panelView.element.classList.remove('ck-a11y-panel--details-expanded');
        const button = this._panelView.element.querySelector('.ck-a11y-panel__btn--ghost');
        if (button) {
            button.textContent = 'Show details';
            button.setAttribute('aria-expanded', 'false');
        }
    }

    _togglePanelDetails() {
        if (!this._panelView?.element) {
            return;
        }

        const isOpen = this._panelView.element.classList.toggle('ck-a11y-panel--details-expanded');
        const button = this._panelView.element.querySelector('.ck-a11y-panel__btn--ghost');
        if (button) {
            button.textContent = isOpen ? 'Hide details' : 'Show details';
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
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

    _isNativeEditorInteraction(target) {
        if (!(target instanceof Element)) {
            return false;
        }

        return target.closest('a[href], button, input, textarea, select, [contenteditable="false"], .ck-widget') !== null;
    }

    _clearSelectedElementState(exceptElement = null) {
        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        for (const element of editable.querySelectorAll('.ck-a11y-element.is-selected, .a11y-element-highlight.is-selected, .ck-a11y-mark.is-selected, .a11y-highlight.is-selected')) {
            if (exceptElement && element === exceptElement) {
                continue;
            }
            element.classList.remove('is-selected');
        }
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
                reason: 'Ignored via editor',
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

            this.editor.fire('a11y:refresh');
            this._hidePanel();
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
            hint: issue.hint ?? '',
            snippet: issue.snippet ?? issue.contextSnippet ?? '',
            contextPath: issue.contextPath ?? '',
            status: issue.status ?? 0,
            relatedIssues: [],
            issueCount: 1,
            relatedSummary: '',
            isLive: issue.live === true || String(issue.fingerprint ?? '').startsWith('live:'),
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

    _severityLabel(severity) {
        switch (this._normalizeSeverity(severity)) {
            case 'critical':
                return 'Critical';
            case 'info':
                return 'Info';
            case 'needs_review':
                return 'Needs review';
            case 'warning':
            default:
                return 'Warning';
        }
    }
}
