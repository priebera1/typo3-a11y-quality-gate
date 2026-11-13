// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';

// Regression (2.0.2, AQG-202-10): the editor summary said "5 Criticals". Since the Atlas pass (audit F-09) the counts
// are the shared severity badges ("1 Critical", "3 Warnings"), with singular/plural keys translated through rte.badge*.

const issues = (severity, count) => Array.from({length: count}, (_, index) => ({severity, ruleId: 'rte.x', fingerprint: `${severity}-${index}`}));

const mountPlainHtml = (name, labels = {}) => {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <textarea name="${name}"><p>Text</p></textarea>
        <div class="js-aqg-plain-html-a11y" data-record-uid="7" data-field-name="bodytext" data-input-name="${name}"
             data-labels='${JSON.stringify(labels)}'>
            <div class="ck-a11y-summary"></div>
            <div class="aqg-plain-html-a11y__issues"></div>
        </div>`;
    document.body.append(wrapper);
    document.dispatchEvent(new Event('typo3:formengine:field-added'));

    return wrapper.querySelector('.js-aqg-plain-html-a11y');
};

const countsText = (container) => [...container.querySelectorAll('.ck-a11y-summary__count')]
    .map((element) => element.textContent.replace(/\s+/g, ' ').trim());

describe('editor summary severity counts', () => {
    beforeAll(async () => {
        // The validator schedules a live check on mount; keep it from running, only the summary is under test.
        vi.useFakeTimers();
        globalThis.fetch = vi.fn(() => new Promise(() => {}));
        await import('../../Resources/Public/JavaScript/plain-html-a11y.js');
    });

    afterEach(() => {
        vi.clearAllTimers();
        document.body.innerHTML = '';
    });

    it('uses natural singular and plural units in the plain HTML summary', () => {
        const container = mountPlainHtml('data[tt_content][7][bodytext]');
        const validator = container.__aqgPlainHtmlValidator;

        validator.renderSummary([...issues('critical', 5), ...issues('warning', 1), ...issues('needs_review', 2), ...issues('info', 1)]);
        expect(countsText(container)).toEqual(['5 Critical', '1 Warning', '2 Needs review', '1 Info']);

        validator.renderSummary([...issues('critical', 1), ...issues('warning', 3), ...issues('info', 2)]);
        expect(countsText(container)).toEqual(['1 Critical', '3 Warnings', '2 Info']);
        expect(container.textContent).not.toContain('Criticals');
        // A badge, not a monospace count pill: icon element plus one text run.
        expect(container.querySelector('.ck-a11y-summary__count .ck-a11y-summary__count-dot')).not.toBeNull();
        expect(container.querySelector('.ck-a11y-summary__count-num')).toBeNull();
    });

    it('uses the translated units of the wizard labels', () => {
        const container = mountPlainHtml('data[tt_content][8][bodytext]', {
            badgeCritical: '%d Kritisch',
            badgeWarningOne: '%d Warnung',
            badgeWarningOther: '%d Warnungen',
        });

        container.__aqgPlainHtmlValidator.renderSummary([...issues('critical', 5), ...issues('warning', 2)]);
        expect(countsText(container)).toEqual(['5 Kritisch', '2 Warnungen']);

        container.__aqgPlainHtmlValidator.renderSummary([...issues('warning', 1)]);
        expect(countsText(container)).toEqual(['1 Warnung']);
    });
});
