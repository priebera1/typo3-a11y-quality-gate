import { Plugin } from '@ckeditor/ckeditor5-core';
import { ContextualBalloon } from '@ckeditor/ckeditor5-ui';
import A11yPanelView from '@priebera/a11y-quality-gate/ckeditor/a11y-panel.js';

const MAX_SNIPPET = 120;
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
        this._markerMeta = new Map();

        editor.conversion.for('editingDowncast').markerToHighlight({
            model: 'a11yIssue',
            view: ({ markerName }) => {
                const meta = this._markerMeta.get(markerName) ?? {};

                return {
                    name: 'mark',
                    classes: [
                        'a11y-highlight',
                        `a11y-${meta.severity ?? 'warning'}`,
                    ],
                    attributes: {
                        'data-a11y-marker': markerName,
                        'data-a11y-rule': meta.ruleId ?? '',
                        'data-a11y-fp': meta.fingerprint ?? '',
                        title: meta.message ?? '',
                    },
                    priority: 7,
                };
            },
        });

        editor.model.document.selection.on('change:range', () => {
            this._onSelectionChange();
        });

        editor.on('ready', () => {
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
            return;
        }

        let issues = [];

        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('recordUid', String(cfg.recordUid));
            url.searchParams.set('fieldName', String(cfg.fieldName));

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                console.warn('[A11Y] Issues fetch failed', response.status, response.url);
                return;
            }

            const data = await response.json();
            issues = Array.isArray(data.issues) ? data.issues : [];
            console.log(issues, 'issues')
        } catch (error) {
            console.warn('[A11Y] Issues fetch error', error);
            return;
        }

        this._applyHighlights(issues);
        this._applyElementDecorations(issues);
    }

    _applyHighlights(issues) {
        const model = this.editor.model;

        model.change((writer) => {
            this._clearAllMarkers(writer);

            let markerIndex = 0;

            for (const issue of issues) {
                if (this._supportsElementFallback(issue.ruleId ?? '')) {
                    continue;
                }

                const text = this._plainText(issue.snippet ?? issue.contextSnippet ?? '');

                if (!text) {
                    continue;
                }

                const ranges = this._findAll(model, text);

                if (ranges.length === 0) {
                    console.warn('[A11Y] No text range found for issue', issue);
                    continue;
                }

                for (const range of ranges) {
                    const markerName = `a11yIssue:${markerIndex++}`;

                    this._markerMeta.set(markerName, {
                        fingerprint: issue.fingerprint ?? '',
                        ruleId: issue.ruleId ?? '',
                        severity: issue.severity ?? 'warning',
                        message: issue.message ?? '',
                        hint: issue.hint ?? '',
                        snippet: issue.snippet ?? issue.contextSnippet ?? '',
                        contextPath: issue.contextPath ?? '',
                        status: issue.status ?? 0,
                    });

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
        this._clearElementDecorations();

        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        for (const issue of issues) {
            const targets = this._findDomTargetsForIssue(editable, issue);

            for (const target of targets) {
                target.classList.add('a11y-element-highlight');
                target.classList.add(`a11y-element-${issue.severity ?? 'warning'}`);
                target.setAttribute('data-a11y-rule', issue.ruleId ?? '');
                target.setAttribute('title', issue.message ?? '');
            }
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

            case 'rte.table_missing_header':
            case 'rte.table_missing_caption':
                return Array.from(editable.querySelectorAll('table'));

            case 'rte.table_th_missing_scope':
                return Array.from(editable.querySelectorAll('th:not([scope])'));

            case 'rte.img_alt_missing':
            case 'rte.img_alt_is_filename':
                return Array.from(editable.querySelectorAll('img, figure.image'));

            case 'rte.svg_missing_title':
                return Array.from(editable.querySelectorAll('svg'));

            case 'rte.button_label_missing':
                return Array.from(editable.querySelectorAll('button'));

            case 'rte.duplicate_id':
                return this._findDuplicateIdTargets(editable);

            default:
                return [];
        }
    }

    _findLinkTargets(editable, issue) {
        const snippet = String(issue.snippet ?? issue.contextSnippet ?? '');
        const hrefMatch = snippet.match(/href="([^"]+)"/i);
        const href = hrefMatch?.[1] ?? '';

        if (href !== '') {
            return Array.from(
                editable.querySelectorAll(`a[href="${CSS.escape(href)}"][target="_blank"]`)
            );
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
                return element.hasAttribute('href');
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

    _clearElementDecorations() {
        const editable = this.editor.ui.getEditableElement();
        if (!editable) {
            return;
        }

        for (const element of editable.querySelectorAll('.a11y-element-highlight')) {
            element.classList.remove(
                'a11y-element-highlight',
                'a11y-element-critical',
                'a11y-element-warning',
                'a11y-element-info'
            );
            element.removeAttribute('data-a11y-rule');
            element.removeAttribute('title');
        }
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
        const lowerSearchText = searchText.toLowerCase();

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
            this._hidePanel();
            return;
        }

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

    _showPanel(issueData) {
        if (!this._panelView) {
            this._panelView = new A11yPanelView(this.editor.locale);
            this._panelView.on('ignore', (event, fingerprint) => {
                this._postIgnore(fingerprint);
            });
        }

        this._panelView.set('issueData', issueData);

        if (!this._balloon.hasView(this._panelView)) {
            this._balloon.add({
                view: this._panelView,
                position: this._balloonPosition(),
            });
        }
    }

    _hidePanel() {
        if (this._panelView && this._balloon.hasView(this._panelView)) {
            this._balloon.remove(this._panelView);
        }
    }

    _balloonPosition() {
        const view = this.editor.editing.view;
        const range = view.document.selection.getFirstRange();

        return {
            target: range ? () => view.domConverter.viewRangeToDom(range) : undefined,
        };
    }

    async _postIgnore(fingerprint) {
        if (!fingerprint) {
            return;
        }

        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_ignore || ajaxUrls['a11y_ignore'];

        if (!endpoint) {
            console.warn('[A11Y] Missing TYPO3.settings.ajaxUrls.a11y_ignore');
            return;
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    fingerprint,
                    reason: 'Ignored via editor',
                }),
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
}