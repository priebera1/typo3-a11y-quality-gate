// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {SearchRequestSequencer, syncPreservingForm} from '../../Resources/Public/JavaScript/backend/core/overview-search.js';

// AQG-300-UX-01: an Overview search update replaced the field being typed in, so focus fell to <body> and the next
// keys were lost; an older answer could also overwrite a newer one.
const render = (query, rows, count) => `
    <div class="a11y-overview" data-state="${query}">
        <p class="count">${count} pages</p>
        <section class="aqg-block">
            <div class="aqg-block__head">
                <div class="aqg-block__heading"><h2>Pages</h2><p class="sub">${count} · sorted</p></div>
                <form role="search"><input type="hidden" name="id" value="1"><input type="search" id="q" name="localQuery" value="${query}"></form>
            </div>
            <table><tbody>${rows.map((row) => `<tr><td>${row}</td></tr>`).join('')}</tbody></table>
            <nav><a href="?localQuery=${query}&localPage=2">2</a></nav>
        </section>
        <section class="other"><a class="other-link" href="?remotePage=2&localQuery=${query}">next</a></section>
    </div>`;

const parse = (html) => new DOMParser().parseFromString(html, 'text/html').querySelector('.a11y-overview');

describe('Overview search update', () => {
    it('keeps the search field, its focus and caret, and takes everything else from the new render', () => {
        document.body.innerHTML = render('', ['Home', 'About', 'Contact'], 3);
        const input = document.getElementById('q');
        input.focus();
        input.value = 'abo';
        input.setSelectionRange(3, 3);

        const root = document.querySelector('.a11y-overview');
        const ok = syncPreservingForm(root, parse(render('abo', ['About'], 1)), input.closest('form'));

        expect(ok).toBe(true);
        expect(document.getElementById('q')).toBe(input);
        expect(document.activeElement).toBe(input);
        expect(input.selectionStart).toBe(3);
        expect(input.value).toBe('abo');
        expect([...document.querySelectorAll('tbody td')].map((td) => td.textContent)).toEqual(['About']);
        expect(document.querySelector('.count').textContent).toBe('1 pages');
        expect(document.querySelector('.sub').textContent).toBe('1 · sorted');
        expect(document.querySelector('nav a').getAttribute('href')).toBe('?localQuery=abo&localPage=2');
        expect(document.querySelector('.other-link').getAttribute('href')).toBe('?remotePage=2&localQuery=abo');
        expect(root.dataset.state).toBe('');
        expect(document.querySelectorAll('form').length).toBe(1);
    });

    it('keeps a client-selected panel visible although the server renders it hidden', () => {
        const panel = (query, hidden) => `<div class="a11y-overview"><div class="panel" data-source="remote"${hidden ? ' hidden' : ''}>
            <form><input id="r" name="remoteQuery" value="${query}"></form><ul><li>${query || 'all'}</li></ul></div></div>`;
        document.body.innerHTML = panel('', false);
        const input = document.getElementById('r');
        input.focus();

        expect(syncPreservingForm(document.querySelector('.a11y-overview'), parse(panel('te', true)), input.closest('form'))).toBe(true);
        expect(document.querySelector('.panel').hidden).toBe(false);
        expect(document.activeElement).toBe(input);
        expect(document.querySelector('li').textContent).toBe('te');
    });

    it('follows the form through a render whose sibling counts changed (pagination added)', () => {
        document.body.innerHTML = render('', ['Home'], 1).replace('<nav>', '<!-- --><nav hidden>');
        const input = document.getElementById('q');
        input.focus();
        const next = parse(render('ho', ['Home'], 1).replace('<table>', '<p class="filtered">Filtered</p><table>'));

        expect(syncPreservingForm(document.querySelector('.a11y-overview'), next, input.closest('form'))).toBe(true);
        expect(document.activeElement).toBe(input);
        expect(document.querySelector('.filtered')).not.toBeNull();
    });

    it('reports a structural mismatch instead of guessing', () => {
        document.body.innerHTML = render('', ['Home'], 1);
        const root = document.querySelector('.a11y-overview');
        const next = parse('<div class="a11y-overview"><p>Locked</p></div>');

        expect(syncPreservingForm(root, next, document.querySelector('form'))).toBe(false);
        expect(document.querySelector('tbody td').textContent).toBe('Home');
    });
});

describe('SearchRequestSequencer', () => {
    it('aborts the previous request of the same field and marks it stale', () => {
        const sequencer = new SearchRequestSequencer();
        const first = sequencer.begin('localQuery', 'https://x/?localQuery=a');
        const second = sequencer.begin('localQuery', 'https://x/?localQuery=aq');

        expect(first.signal.aborted).toBe(true);
        expect(first.isCurrent()).toBe(false);
        expect(second.isCurrent()).toBe(true);
    });

    it('keeps the fields independent', () => {
        const sequencer = new SearchRequestSequencer();
        const local = sequencer.begin('localQuery', 'https://x/?localQuery=a');
        const remote = sequencer.begin('remoteQuery', 'https://x/?remoteQuery=a');

        expect(local.signal.aborted).toBe(false);
        expect(remote.isCurrent()).toBe(true);
    });

    it('skips a request for what is already shown, but not while another is in flight', () => {
        const sequencer = new SearchRequestSequencer();
        sequencer.markShownOnce('localQuery', 'https://x/?localQuery=aqg');

        expect(sequencer.begin('localQuery', 'https://x/?localQuery=aqg')).toBeNull();

        const typing = sequencer.begin('localQuery', 'https://x/?localQuery=aqgx');
        const back = sequencer.begin('localQuery', 'https://x/?localQuery=aqg');
        expect(typing.signal.aborted).toBe(true);
        expect(back).not.toBeNull();
        back.done('https://x/?localQuery=aqg');
        expect(sequencer.begin('localQuery', 'https://x/?localQuery=aqg')).toBeNull();
    });
});
