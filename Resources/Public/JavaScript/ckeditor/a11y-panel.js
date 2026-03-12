import { View } from '@ckeditor/ckeditor5-ui';

export default class A11yPanelView extends View {
    constructor(locale) {
        super(locale);

        this.set('issueData', null);

        const bind = this.bindTemplate;

        this.setTemplate({
            tag: 'div',
            attributes: {
                class: ['ck', 'ck-a11y-panel'],
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
                                    text: bind.to(
                                        'issueData',
                                        (issueData) => issueData?.severity?.toUpperCase() ?? ''
                                    ),
                                },
                            ],
                        },
                        {
                            tag: 'code',
                            attributes: {
                                class: 'ck-a11y-panel__rule',
                            },
                            children: [
                                {
                                    text: bind.to('issueData', (issueData) => issueData?.ruleId ?? ''),
                                },
                            ],
                        },
                    ],
                },
                {
                    tag: 'p',
                    attributes: {
                        class: 'ck-a11y-panel__message',
                    },
                    children: [
                        {
                            text: bind.to('issueData', (issueData) => issueData?.message ?? ''),
                        },
                    ],
                },
                {
                    tag: 'button',
                    attributes: {
                        class: ['ck', 'ck-button', 'ck-a11y-panel__ignore'],
                        type: 'button',
                    },
                    children: [
                        {
                            text: 'Ignore issue',
                        },
                    ],
                    on: {
                        click: bind.to(() => {
                            this.fire('ignore', this.issueData?.fingerprint);
                        }),
                    },
                },
            ],
        });
    }
}