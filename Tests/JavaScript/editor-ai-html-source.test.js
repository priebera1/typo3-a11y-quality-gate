// @vitest-environment jsdom
import {describe, expect, it} from 'vitest';
import {
    buildChange,
    locateTarget,
    locateTargetAt,
    sourceDiff,
    tokenizeHtml,
    verifyChange,
} from '@priebera/a11y-quality-gate/editor-ai/html-source.js';

const linkTarget = (occurrence, total = 3) => ({
    ruleId: 'rte.non_descriptive_link',
    tag: 'a',
    occurrence,
    total,
    operation: 'text',
    attribute: '',
    element: {tag: 'a', attributes: {href: '/kontakt'}, text: 'more', hasChildElements: false},
});

const teasers = [
    '<ul class="teasers">',
    '  <li><h3>Moving house?</h3>',
    '  <p>Register your new address online. <a href="/kontakt">more</a></p></li>',
    '  <li><h3>Meter reading</h3>',
    '  <p>Send us your reading by 15 January. <a href="/kontakt">more</a></p></li>',
    '  <li><h3>Billing questions</h3>',
    '  <p>We explain every line of your bill. <a href="/kontakt">more</a></p></li>',
    '</ul>',
].join('\n');

describe('plain HTML exact targeting', () => {
    it('distinguishes identical duplicates by their occurrence', () => {
        const second = locateTarget(teasers, linkTarget(1));
        expect(second.ok).toBe(true);
        expect(second.line).toBe(5);

        const change = buildChange(teasers, second, linkTarget(1), 'Send your meter reading');
        const result = teasers.slice(0, change.from) + change.insert + teasers.slice(change.to);
        expect(result.match(/>more</g)).toHaveLength(2);
        expect(result.split('\n')[4]).toContain('<a href="/kontakt">Send your meter reading</a>');
        expect(result.split('\n')[2]).toContain('>more</a>');
        expect(result.split('\n')[6]).toContain('>more</a>');
    });

    it('refuses when the number of elements differs from the checked content', () => {
        const edited = teasers.replace('</ul>', '<p><a href="/kontakt">more</a></p></ul>');
        expect(locateTarget(edited, linkTarget(1)).reason).toBe('count_mismatch');
    });

    it('refuses when the element at the occurrence no longer matches', () => {
        const edited = teasers.replace('January. <a href="/kontakt">more</a>', 'January. <a href="/kontakt">more help</a>');
        expect(locateTarget(edited, linkTarget(1)).reason).toBe('signature_mismatch');
    });

    it('follows a tracked position after edits elsewhere and detects edits of the target', () => {
        const original = locateTarget(teasers, linkTarget(2));
        const prefix = '<p>New intro</p>\n';
        const moved = prefix + teasers;
        expect(locateTargetAt(moved, original.element.start + prefix.length, linkTarget(2)).ok).toBe(true);
        expect(locateTargetAt(moved, original.element.start, linkTarget(2)).ok).toBe(false);
    });

    it('turns AI fixes off for unparseable source', () => {
        const broken = '<p>Opening hours <a href="/kontakt>more</a></p>';
        const located = locateTarget(broken, linkTarget(0, 1));
        expect(located.ok).toBe(false);
        expect(located.reason).toBe('source_unparseable');
    });

    it('skips comments and raw text when counting elements', () => {
        const source = '<!-- <a href="/kontakt">more</a> --><script>const s = "<a href=x>";</script><p><a href="/kontakt">more</a></p>';
        expect(tokenizeHtml(source).elements.filter((element) => element.tag === 'a')).toHaveLength(1);
        expect(locateTarget(source, linkTarget(0, 1)).ok).toBe(true);
    });

    it('adds one escaped attribute and passes the range check', () => {
        const source = '<form action="/newsletter">\n<input type="email" name="email"> <button type="submit"><svg aria-hidden="true"></svg></button>\n</form>';
        const target = {
            tag: 'button', occurrence: 0, total: 1, operation: 'attribute', attribute: 'aria-label',
            element: {tag: 'button', attributes: {type: 'submit'}, text: '', hasChildElements: true},
        };
        const located = locateTarget(source, target);
        expect(located.ok).toBe(true);
        const value = 'Subscribe "now" <b>';
        const change = buildChange(source, located, target, value);
        expect(change.insert).toBe(' aria-label="Subscribe &quot;now&quot; &lt;b&gt;"');
        const check = verifyChange(source, change, located, target, value);
        expect(check.ok).toBe(true);
        expect(check.result).toContain('<button type="submit" aria-label="Subscribe &quot;now&quot; &lt;b&gt;"><svg');
        expect(sourceDiff(source, change, located).line).toBe(2);
    });

    it('replaces an existing attribute value only', () => {
        const source = '<img src="/a.png" alt=\'a.png\' width="480">';
        const target = {
            tag: 'img', occurrence: 0, total: 1, operation: 'attribute', attribute: 'alt',
            element: {tag: 'img', attributes: {src: '/a.png', alt: 'a.png', width: '480'}, text: '', hasChildElements: false},
        };
        const located = locateTarget(source, target);
        const change = buildChange(source, located, target, "Bar chart: Water's price");
        const check = verifyChange(source, change, located, target, "Bar chart: Water's price");
        expect(check.ok).toBe(true);
        expect(check.result).toBe('<img src="/a.png" alt=\'Bar chart: Water&#39;s price\' width="480">');
    });

    it('offers no text replacement for a link with inner markup', () => {
        const source = '<p><a href="/kontakt"><strong>more</strong></a></p>';
        const target = {
            tag: 'a', occurrence: 0, total: 1, operation: 'text', attribute: '',
            element: {tag: 'a', attributes: {href: '/kontakt'}, text: 'more', hasChildElements: true},
        };
        expect(locateTarget(source, target).reason).toBe('nested_markup');
    });

    it('keeps template placeholders manual', () => {
        const source = '<a href="{tariff.pdfUrl}">{tariff.linkLabel}</a>';
        const target = {
            tag: 'a', occurrence: 0, total: 1, operation: 'text', attribute: '',
            element: {tag: 'a', attributes: {href: '{tariff.pdfUrl}'}, text: '{tariff.linkLabel}', hasChildElements: false},
        };
        expect(locateTarget(source, target).reason).toBe('template_placeholder');
    });

    it('rejects a change that would alter structure', () => {
        const source = '<p><a href="/kontakt">more</a></p>';
        const target = linkTarget(0, 1);
        const located = locateTarget(source, target);
        const forged = {from: located.content.from, to: located.content.to, insert: '<b>x</b>'};
        expect(verifyChange(source, forged, located, target, 'x').ok).toBe(false);
    });
});
