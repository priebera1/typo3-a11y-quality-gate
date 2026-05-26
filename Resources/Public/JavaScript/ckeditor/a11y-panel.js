import { View } from '@ckeditor/ckeditor5-ui';

export default class A11yPanelView extends View {
    constructor(locale) {
        super(locale);

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
                                    text: 'Accessibility issue',
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
                                    text: bind.to('issueData', (issueData) => issueData?.issueCount > 1 ? `${issueData.issueCount} issues on this element` : ''),
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
                                    text: 'How to fix',
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
                                    text: bind.to('issueData', (issueData) => issueData?.hint || 'No editor guidance is available for this rule yet. Review the highlighted content and the rule details below.'),
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
                        this._detailsRow('Rule', bind.to('issueData', (issueData) => issueData?.ruleId ?? '')),
                        this._detailsRow('Location', bind.to('issueData', (issueData) => issueData?.contextPath ?? '')),
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
                                    text: bind.to('issueData', (issueData) => issueData?.issueCount > 1 ? '' : 'Ignore this issue'),
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
                                    text: 'Show details',
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
                return 'Critical';
            case 'info':
                return 'Info';
            case 'needs_review':
            case 'needs-review':
                return 'Needs review';
            case 'warning':
            default:
                return 'Warning';
        }
    }
}
