// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';


const json = (status, body) => new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}});

function renderRows(count) {
    document.body.innerHTML = `<div class="multi-record-selection-actions-wrapper"></div>${Array.from({length: count}, (_, i) => `
        <div data-multi-record-selection-element="true" data-filelist-type="file" data-filelist-uid="${i + 1}">
            <input type="checkbox" class="t3js-multi-record-selection-check">
        </div>`).join('')}`;
}

async function loadModule() {
    vi.resetModules();
    return import('@priebera/a11y-quality-gate/media-ai/filelist-media-ai.js');
}

// Each test loads a fresh module instance; its document listeners must not outlive the test.
let listeners = [];
const addEventListener = document.addEventListener.bind(document);

beforeEach(() => {
    listeners = [];
    document.addEventListener = (...args) => {
        listeners.push(args);
        addEventListener(...args);
    };
    vi.useFakeTimers();
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_ai_media_file_state: '/file-state', a11y_ai_media_suggest_metadata: '/suggest-meta'}, aqgMediaAi: {labels: {}}}};
});

afterEach(() => {
    for (const args of listeners) {
        document.removeEventListener(...args);
    }
    document.addEventListener = addEventListener;
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('Filelist AI metadata without a server write path', () => {
    it('tells a selection of several files to continue per file and sends nothing', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        renderRows(3);
        await loadModule();

        for (const checkbox of [...document.querySelectorAll('.t3js-multi-record-selection-check')].slice(0, 2)) {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change', {bubbles: true}));
        }
        await vi.advanceTimersByTimeAsync(400);

        const bar = document.querySelector('.aqg-media-bulkbar');
        expect(bar?.textContent).toContain('2 selected');
        expect(bar?.textContent).toContain('cannot apply AI metadata to several files at once');
        expect(bar?.querySelector('button')).toBeNull();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('removes the hint when fewer than two files stay selected', async () => {
        renderRows(2);
        await loadModule();
        const [first, second] = document.querySelectorAll('.t3js-multi-record-selection-check');
        first.checked = true;
        second.checked = true;
        first.dispatchEvent(new Event('change', {bubbles: true}));
        await vi.advanceTimersByTimeAsync(400);
        second.checked = false;
        second.dispatchEvent(new Event('change', {bubbles: true}));
        await vi.advanceTimersByTimeAsync(400);

        expect(document.querySelector('.aqg-media-bulkbar')).toBeNull();
    });

    it('after an upload only links each image without alternative text to its metadata form', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(200, {
            success: true,
            status: 'state',
            sites: [{identifier: 'main', state: 'ready'}],
            files: [
                {uid: 1, name: 'chart.png', status: 'eligible', editableFields: ['alternative'], metadata: {values: {alternative: ''}}, editUrl: '/typo3/record/edit?token=t&edit%5Bsys_file_metadata%5D%5B10%5D=edit'},
                {uid: 2, name: 'logo.png', status: 'eligible', editableFields: ['alternative'], metadata: {values: {alternative: 'Logo'}}, editUrl: '/typo3/record/edit?token=t&edit%5Bsys_file_metadata%5D%5B20%5D=edit'},
                {uid: 3, name: 'archive.zip', status: 'unsupported', editableFields: [], metadata: null, editUrl: ''},
                {uid: 4, name: 'evil.png', status: 'eligible', editableFields: ['alternative'], metadata: {values: {alternative: ''}}, editUrl: 'javascript:alert(1)'},
            ],
        }));
        renderRows(0);
        await loadModule();

        document.dispatchEvent(new CustomEvent('uploadSuccess', {detail: [null, {upload: [1, 2, 3, 4, 5, 6].map((uid) => ({uid}))}]}));
        await vi.advanceTimersByTimeAsync(3600);

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const [url, init] = fetchSpy.mock.calls[0];
        expect(url).toBe('/file-state');
        expect(JSON.parse(init.body)).toEqual({fileUids: [1, 2, 3, 4, 5], languageUid: 0, context: 'upload'});

        // The stub instance of this module registry (vi.resetModules() gives every test a fresh one).
        const {notificationCalls: notifications} = await import('@typo3/backend/notification.js');
        await vi.waitFor(() => expect(notifications).toHaveLength(1));
        const {message, actions} = notifications[0];
        // Audit F-26: one image is singular ("1 images have" before the Atlas pass).
        expect(message).toContain('1 image has no alternative text. Open it:');
        expect(notifications[0].title).toBe('6 files uploaded');
        expect(message).toContain('Only the first five uploads were checked.');
        expect(actions.map((action) => action.label)).toEqual(['Open metadata: chart.png', 'Not now']);

        expect(typeof actions[0].action.callback).toBe('function');
    });

    it('builds the form link with the way back and rejects anything that is not a backend URL', async () => {
        const {withReturnUrl} = await loadModule();
        const location = {pathname: '/typo3/module/file/list', search: '?id=1:/'};

        expect(withReturnUrl('/typo3/record/edit?token=t', location)).toBe('/typo3/record/edit?token=t&returnUrl=%2Ftypo3%2Fmodule%2Ffile%2Flist%3Fid%3D1%3A%2F');
        expect(withReturnUrl('javascript:alert(1)', location)).toBe('');
        expect(withReturnUrl(undefined, location)).toBe('');
    });

    it('never calls a metadata write route', async () => {
        const client = await import('@priebera/a11y-quality-gate/media-ai/media-ai-client.js');
        globalThis.TYPO3.settings.ajaxUrls.a11y_ai_media_apply_metadata = '/apply';
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        expect(await client.postMediaAi('applyMetadata', {})).toMatchObject({ok: false, code: 'unavailable'});
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
