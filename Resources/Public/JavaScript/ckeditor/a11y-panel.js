import { View } from '@ckeditor/ckeditor5-ui';

export default class A11yPanelView extends View {
    constructor(locale, labels = {}) {
        super(locale);

        this._labels = labels && typeof labels === 'object' ? labels : {};
        this.set('issueData', null);

        const bind = this.bindTemplate;

        this.setTemplate({
            tag: 'div',
            attributes: {
                class: bind.to('issueData', (issueData) => [
                    'ck-a11y-panel',
                    `ck-a11y-panel--${issueData?.severity ?? 'warning'}`,
                ].join(' ')),
            },
            children: [
                {
                    tag: 'div',
                    attributes: {
                        class: 'ck-a11y-panel__header',
                    },
                    children: [
                        {
                            tag: 'span',
                            attributes: {
                                class: bind.to('issueData', (issueData) => [
                                    'ck-a11y-panel__severity',
                                    `ck-a11y-panel__severity--${issueData?.severity ?? 'warning'}`,
                                ].join(' ')),
                            },
                            children: [
                                {
                                    tag: 'span',
                                    attributes: {
                                        class: 'ck-a11y-panel__severity-dot',
                                    },
                                },
                                {
                                    text: bind.to('issueData', (issueData) => this._severityLabel(issueData?.severity)),
                                },
                            ],
                        },
                        {
                            tag: 'span',
                            attributes: {
                                class: 'ck-a11y-panel__kicker',
                            },
                            children: [
                                {
                                    text: this._label('accessibilityIssue', 'Accessibility issue'),
                                },
                            ],
                        },
                    ],
                },
                {
                    tag: 'h4',
                    attributes: {
                        class: 'ck-a11y-panel__title',
                    },
                    children: [
                        {
                            text: bind.to('issueData', (issueData) => issueData?.message ?? ''),
                        },
                    ],
                },
                {
                    tag: 'div',
                    attributes: {
                        class: bind.to('issueData', (issueData) => [
                            'ck-a11y-panel__related',
                            (issueData?.relatedSummary ?? '') === '' ? 'ck-a11y-panel__related--empty' : '',
                        ].join(' ')),
                    },
                    children: [
                        {
                            tag: 'span',
                            attributes: {
                                class: 'ck-a11y-panel__related-label',
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.issueCount > 1
                                        ? this._label('issuesOnElement', '%d issues on this element').replace('%d', String(issueData.issueCount))
                                        : ''),
                                },
                            ],
                        },
                        {
                            tag: 'p',
                            attributes: {
                                class: 'ck-a11y-panel__related-text',
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.relatedSummary ?? ''),
                                },
                            ],
                        },
                    ],
                },
                {
                    tag: 'div',
                    attributes: {
                        class: bind.to('issueData', (issueData) => [
                            'ck-a11y-panel__hint',
                            (issueData?.hint ?? '') === '' ? 'ck-a11y-panel__hint--empty' : '',
                        ].join(' ')),
                    },
                    children: [
                        {
                            tag: 'span',
                            attributes: {
                                class: 'ck-a11y-panel__hint-label',
                            },
                            children: [
                                {
                                    text: this._label('howToFix', 'How to fix'),
                                },
                            ],
                        },
                        {
                            tag: 'p',
                            attributes: {
                                class: 'ck-a11y-panel__hint-text',
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.hint || this._label('noGuidance', 'No editor guidance is available for this rule yet. Review the highlighted content and the rule details below.')),
                                },
                            ],
                        },
                    ],
                },
                {
                    tag: 'div',
                    attributes: {
                        class: 'ck-a11y-panel__details',
                    },
                    children: [
                        this._detailsRow(this._label('rule', 'Rule'), bind.to('issueData', (issueData) => issueData?.ruleId ?? '')),
                        this._detailsRow(this._label('location', 'Location'), bind.to('issueData', (issueData) => issueData?.contextPath ?? '')),
                        {
                            tag: 'code',
                            attributes: {
                                class: bind.to('issueData', (issueData) => [
                                    'ck-a11y-panel__snippet',
                                    (issueData?.snippet ?? '') === '' ? 'ck-a11y-panel__snippet--empty' : '',
                                ].join(' ')),
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.snippet ?? ''),
                                },
                            ],
                        },
                    ],
                },
                {
                    tag: 'div',
                    attributes: {
                        class: 'ck-a11y-panel__actions',
                    },
                    children: [
                        {
                            tag: 'button',
                            attributes: {
                                class: bind.to('issueData', (issueData) => [
                                    'ck-a11y-panel__btn',
                                    'ck-a11y-panel__btn--ignore',
                                    issueData?.issueCount > 1 ? 'ck-a11y-panel__btn--hidden' : '',
                                ].join(' ')),
                                type: 'button',
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.issueCount > 1 ? '' : this._label('ignoreIssue', 'Ignore this issue')),
                                },
                            ],
                            on: {
                                click: bind.to(() => {
                                    if (this.issueData?.issueCount > 1) {
                                        return;
                                    }
                                    this.fire('ignore', this.issueData);
                                }),
                            },
                        },
                        {
                            tag: 'button',
                            attributes: {
                                class: ['ck-a11y-panel__btn', 'ck-a11y-panel__btn--ghost'],
                                type: 'button',
                                'aria-expanded': 'false',
                            },
                            children: [
                                {
                                    text: this._label('showDetails', 'Show details'),
                                },
                            ],
                            on: {
                                click: bind.to(() => {
                                    this.fire('details');
                                }),
                            },
                        },
                    ],
                },
                {
                    tag: 'span',
                    attributes: {
                        class: 'ck-a11y-panel__tail',
                    },
                },
            ],
        });
    }

    _detailsRow(label, valueBinding) {
        return {
            tag: 'div',
            attributes: {
                class: 'ck-a11y-panel__details-row',
            },
            children: [
                {
                    tag: 'span',
                    attributes: {
                        class: 'ck-a11y-panel__details-key',
                    },
                    children: [
                        {
                            text: label,
                        },
                    ],
                },
                {
                    tag: 'span',
                    attributes: {
                        class: 'ck-a11y-panel__details-val',
                    },
                    children: [
                        {
                            text: valueBinding,
                        },
                    ],
                },
            ],
        };
    }

    _severityLabel(severity) {
        switch (severity) {
            case 'critical':
                return this._label('severityCritical', 'Critical');
            case 'info':
                return this._label('severityInfo', 'Info');
            case 'needs_review':
            case 'needs-review':
                return this._label('severityNeedsReview', 'Needs review');
            case 'warning':
            default:
                return this._label('severityWarning', 'Warning');
        }
    }

    _label(key, fallback) {
        const value = this._labels?.[key];
        return typeof value === 'string' && value !== '' ? value : fallback;
    }
}
