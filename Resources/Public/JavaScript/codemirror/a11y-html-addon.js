import { RangeSetBuilder, StateEffect, StateField } from '@codemirror/state';
import { Decoration, EditorView, ViewPlugin } from '@codemirror/view';

const setAqgIssuesEffect = StateEffect.define();
const clearAqgIssuesEffect = StateEffect.define();
const revealAqgLineEffect = StateEffect.define();
const clearRevealAqgLineEffect = StateEffect.define();

const normalizeSeverity = (severity) => {
    const value = String(severity || '').toLowerCase();
    return ['critical', 'warning', 'info', 'needs_review'].includes(value) ? value : 'info';
};

const severityRank = (severity) => ({ critical: 3, warning: 2, needs_review: 1.5, info: 1 }[normalizeSeverity(severity)] || 0);

const buildDecorations = (state, issues) => {
    const grouped = new Map();

    for (const issue of Array.isArray(issues) ? issues : []) {
        const lineNumber = Number.parseInt(String(issue.lineNumber || '0'), 10);
        if (!Number.isInteger(lineNumber) || lineNumber <= 0 || lineNumber > state.doc.lines) {
            continue;
        }

        if (!grouped.has(lineNumber)) {
            grouped.set(lineNumber, []);
        }
        grouped.get(lineNumber).push(issue);
    }

    const builder = new RangeSetBuilder();
    const lineNumbers = Array.from(grouped.keys()).sort((a, b) => a - b);

    for (const lineNumber of lineNumbers) {
        const line = state.doc.line(lineNumber);
        const lineIssues = grouped.get(lineNumber) || [];
        const strongestSeverity = lineIssues
            .map((issue) => normalizeSeverity(issue.severity))
            .sort((a, b) => severityRank(b) - severityRank(a))[0] || 'info';
        const indexes = lineIssues.map((issue) => String(issue.index)).join(',');
        const label = lineIssues.length === 1 ? 'AQG' : `AQG ${lineIssues.length}`;

        builder.add(line.from, line.from, Decoration.line({
            class: `aqg-cm-line aqg-cm-line--${strongestSeverity}`,
            attributes: {
                'data-aqg-issue-indexes': indexes,
                'data-aqg-label': label,
            },
        }));
    }

    return builder.finish();
};


const aqgRevealDecoration = StateField.define({
    create() {
        return Decoration.none;
    },
    update(decorations, transaction) {
        for (const effect of transaction.effects) {
            if (effect.is(revealAqgLineEffect)) {
                const lineNumber = Number.parseInt(String(effect.value || '0'), 10);
                if (!Number.isInteger(lineNumber) || lineNumber <= 0 || lineNumber > transaction.state.doc.lines) {
                    return Decoration.none;
                }
                const line = transaction.state.doc.line(lineNumber);
                return Decoration.set([
                    Decoration.line({ class: 'aqg-cm-line-reveal' }).range(line.from),
                ]);
            }
            if (effect.is(clearRevealAqgLineEffect)) {
                return Decoration.none;
            }
        }

        if (transaction.docChanged) {
            return decorations.map(transaction.changes);
        }

        return decorations;
    },
    provide: (field) => EditorView.decorations.from(field),
});

const aqgIssueDecorations = StateField.define({
    create() {
        return Decoration.none;
    },
    update(decorations, transaction) {
        for (const effect of transaction.effects) {
            if (effect.is(setAqgIssuesEffect)) {
                return buildDecorations(transaction.state, effect.value);
            }
            if (effect.is(clearAqgIssuesEffect)) {
                return Decoration.none;
            }
        }

        if (transaction.docChanged) {
            return decorations.map(transaction.changes);
        }

        return decorations;
    },
    provide: (field) => EditorView.decorations.from(field),
});

class AqgHtmlMarkersBridge {
    constructor(view) {
        this.view = view;
        this.updateIssues = this.updateIssues.bind(this);
        this.clearIssues = this.clearIssues.bind(this);
        this.revealLine = this.revealLine.bind(this);

        view.dom.dataset.aqgCodeMirrorAddon = '1';
        view.dom.addEventListener('aqg:updateIssues', this.updateIssues);
        view.dom.addEventListener('aqg:clearIssues', this.clearIssues);
        view.dom.addEventListener('aqg:revealLine', this.revealLine);
    }

    updateIssues(event) {
        event.stopPropagation();
        this.view.dispatch({
            effects: setAqgIssuesEffect.of(Array.isArray(event.detail?.issues) ? event.detail.issues : []),
        });
    }

    clearIssues(event) {
        event.stopPropagation();
        this.view.dispatch({
            effects: clearAqgIssuesEffect.of(null),
        });
    }

    revealLine(event) {
        event.stopPropagation();

        const lineNumber = Number.parseInt(String(event.detail?.lineNumber || '0'), 10);
        if (!Number.isInteger(lineNumber) || lineNumber <= 0 || lineNumber > this.view.state.doc.lines) {
            return;
        }

        const line = this.view.state.doc.line(lineNumber);
        this.view.dispatch({
            selection: { anchor: line.from, head: line.to },
            effects: [
                revealAqgLineEffect.of(lineNumber),
                EditorView.scrollIntoView(line.from, { y: 'center' }),
            ],
        });
        window.clearTimeout(this.clearRevealTimer);
        this.clearRevealTimer = window.setTimeout(() => {
            this.view.dispatch({ effects: clearRevealAqgLineEffect.of(null) });
        }, 1800);
        this.view.focus();
    }

    destroy() {
        window.clearTimeout(this.clearRevealTimer);
        delete this.view.dom.dataset.aqgCodeMirrorAddon;
        this.view.dom.removeEventListener('aqg:updateIssues', this.updateIssues);
        this.view.dom.removeEventListener('aqg:clearIssues', this.clearIssues);
        this.view.dom.removeEventListener('aqg:revealLine', this.revealLine);
    }
}

const aqgHtmlMarkersTheme = EditorView.baseTheme({
    '& .aqg-cm-line': {
        position: 'relative',
    },
});

export function a11yHtmlAddon() {
    return [
        aqgIssueDecorations,
        aqgRevealDecoration,
        ViewPlugin.fromClass(AqgHtmlMarkersBridge),
        aqgHtmlMarkersTheme,
    ];
}

export default a11yHtmlAddon;
