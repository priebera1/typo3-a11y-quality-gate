// @vitest-environment jsdom
import {afterEach, describe, expect, it, vi} from 'vitest';
import {assignPriorityFixOwner, initializePriorityFixOwner} from '@priebera/a11y-quality-gate/backend/pro/priority-fix-owner.js';

const ajaxUrls = {a11y_remediation_owner: '/typo3/ajax/a11y/remediation/owner'};

function mount({tag = ''} = {}) {
    document.body.innerHTML = `
        <ol><li class="aqg-fix">
            <div class="aqg-fix__main">
                <h3>Images need alternative text</h3>
                ${tag
                    ? `<p class="aqg-fix__tags" data-aqg-fix-tags="image-alt"><span class="aqg-fix__owner aqg-fix__owner--${tag}" data-aqg-fix-owner="${tag}"><span class="visually-hidden">Assigned owner: </span>Developer</span></p>`
                    : '<p class="aqg-fix__tags" data-aqg-fix-tags="image-alt" hidden="hidden"></p>'}
            </div>
            <div class="aqg-fix__details" hidden="hidden">
                <form data-aqg-fix-owner-form="true" data-rule-id="image-alt" data-page-uid="1"
                      data-label-prefix="Zugewiesene Zuständigkeit" data-label-saved="Saved." data-label-removed="Removed."
                      data-label-error-generic="Something went wrong." data-label-error-permission-denied="Not allowed.">
                    <label for="aqg-fix-owner-image-alt">Owner</label>
                    <select id="aqg-fix-owner-image-alt" data-aqg-fix-owner-select="true">
                        <option value="editor">Editor</option><option value="developer">Developer</option>
                        <option value="mixed">Mixed</option><option value="unassigned" selected>Unassigned</option>
                    </select>
                    <button type="button" data-aqg-fix-owner-save="true">Assign owner</button>
                    <span role="status" data-aqg-fix-owner-status="true"></span>
                </form>
            </div>
        </li></ol>`;
    return document.querySelector('form');
}

function answer(status, body) {
    return vi.fn(async () => ({ok: status < 400, status, redirected: false, headers: new Headers({'content-type': 'application/json'}), json: async () => body}));
}

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('What to fix first: owner selector (F-08)', () => {
    it('posts only page uid, rule id and owner to the existing owner route and shows the answered tag', async () => {
        const form = mount();
        const fetchMock = answer(200, {success: true, status: 'assigned', owner: 'developer', updated: 0, tag: {owner: 'developer', label: 'Entwicklung'}});
        vi.stubGlobal('fetch', fetchMock);
        form.querySelector('select').value = 'developer';

        expect(await assignPriorityFixOwner(form, {ajaxUrls})).toBe(true);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/typo3/ajax/a11y/remediation/owner');
        expect(JSON.parse(init.body)).toEqual({pageUid: 1, ruleId: 'image-alt', owner: 'developer'});
        const tags = document.querySelector('[data-aqg-fix-tags]');
        expect(tags.hidden).toBe(false);
        const chip = tags.querySelector('[data-aqg-fix-owner]');
        expect(chip.className).toBe('aqg-fix__owner aqg-fix__owner--developer');
        expect(chip.textContent).toBe('Zugewiesene Zuständigkeit: Entwicklung');
        expect(chip.querySelector('.visually-hidden').textContent).toBe('Zugewiesene Zuständigkeit: ');
        expect(form.querySelector('[role="status"]').textContent).toBe('Saved.');
    });

    it('shows "varies by page" when the server says so, and Unassigned removes the tag', async () => {
        const form = mount({tag: 'developer'});
        vi.stubGlobal('fetch', answer(200, {success: true, tag: {owner: 'varies', label: 'Varies by page'}}));
        form.querySelector('select').value = 'editor';
        await assignPriorityFixOwner(form, {ajaxUrls});
        expect(document.querySelector('[data-aqg-fix-owner]').className).toContain('aqg-fix__owner--varies');

        vi.stubGlobal('fetch', answer(200, {success: true, tag: {owner: '', label: ''}}));
        form.querySelector('select').value = 'unassigned';
        await assignPriorityFixOwner(form, {ajaxUrls});
        const tags = document.querySelector('[data-aqg-fix-tags]');
        expect(tags.hidden).toBe(true);
        expect(tags.children).toHaveLength(0);
        expect(form.querySelector('[role="status"]').textContent).toBe('Removed.');
    });

    it('keeps the tag and announces the refusal when the route refuses', async () => {
        const form = mount({tag: 'developer'});
        vi.stubGlobal('fetch', answer(403, {success: false, code: 'permission_denied'}));
        form.querySelector('select').value = 'editor';

        expect(await assignPriorityFixOwner(form, {ajaxUrls})).toBe(false);

        expect(document.querySelector('[data-aqg-fix-owner]').className).toContain('aqg-fix__owner--developer');
        expect(form.querySelector('[role="status"]').textContent).toBe('Not allowed.');
        expect(form.querySelector('button').disabled).toBe(false);

        vi.stubGlobal('fetch', answer(422, {success: false, code: 'invalid_input'}));
        await assignPriorityFixOwner(form, {ajaxUrls});
        expect(form.querySelector('[role="status"]').textContent).toBe('Something went wrong.');
    });

    it('assigns from the Assign button through the delegated click handler', async () => {
        const form = mount();
        const fetchMock = answer(200, {success: true, tag: {owner: 'mixed', label: 'Mixed'}});
        vi.stubGlobal('fetch', fetchMock);
        initializePriorityFixOwner(document, {ajaxUrls});
        form.querySelector('select').value = 'mixed';

        form.querySelector('button').click();
        await vi.waitFor(() => expect(document.querySelector('[data-aqg-fix-owner]')).not.toBeNull());

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-aqg-fix-owner]').getAttribute('data-aqg-fix-owner')).toBe('mixed');
    });
});
