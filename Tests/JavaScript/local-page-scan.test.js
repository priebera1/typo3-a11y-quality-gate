// @vitest-environment jsdom

import {beforeEach, describe, expect, it, vi} from 'vitest';
import {initializeLocalPageScan} from '../../Resources/Public/JavaScript/backend/core/local-page-scan.js';

const createFixture = () => {
    const root = document.createElement('div');
    root.innerHTML = `
        <button type="button"
                data-action="a11y-rescan"
                data-page-uid="42"
                data-language-uid="3">Scan this page</button>
    `;
    document.body.append(root);

    return {
        root,
        button: root.querySelector('[data-action="a11y-rescan"]'),
    };
};

describe('local page scan initializer', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('binds one click to one local page scan request', async () => {
        const {root, button} = createFixture();
        const module = {handleRescan: vi.fn().mockResolvedValue(undefined)};

        initializeLocalPageScan(module, root);
        button.click();

        await vi.waitFor(() => expect(module.handleRescan).toHaveBeenCalledTimes(1));
        expect(module.handleRescan).toHaveBeenCalledWith(button);
    });

    it('remains idempotent when initialized twice', async () => {
        const {root, button} = createFixture();
        const module = {handleRescan: vi.fn().mockResolvedValue(undefined)};

        expect(initializeLocalPageScan(module, root)).toBe(true);
        expect(initializeLocalPageScan(module, root)).toBe(false);
        button.click();

        await vi.waitFor(() => expect(module.handleRescan).toHaveBeenCalledTimes(1));
    });

    it('does not require a PRO-only element', async () => {
        const {root, button} = createFixture();
        const module = {handleRescan: vi.fn().mockResolvedValue(undefined)};

        expect(document.querySelector('[data-action="a11y-pro-scan-page"]')).toBeNull();
        initializeLocalPageScan(module, root);
        button.click();

        await vi.waitFor(() => expect(module.handleRescan).toHaveBeenCalledTimes(1));
    });

    it('blocks a duplicate click while the request is active', async () => {
        const {root, button} = createFixture();
        let resolveRequest;
        const module = {
            handleRescan: vi.fn().mockImplementation(() => new Promise((resolve) => {
                resolveRequest = resolve;
            })),
        };

        initializeLocalPageScan(module, root);
        button.click();
        button.click();

        await vi.waitFor(() => expect(module.handleRescan).toHaveBeenCalledTimes(1));
        resolveRequest();
    });

    it('passes the button with the production page and language data', async () => {
        const {root, button} = createFixture();
        const payloads = [];
        const module = {
            handleRescan: vi.fn().mockImplementation(async (scanButton) => {
                payloads.push({
                    pageUid: Number.parseInt(scanButton.dataset.pageUid, 10),
                    languageUid: Number.parseInt(scanButton.dataset.languageUid, 10),
                });
            }),
        };

        initializeLocalPageScan(module, root);
        button.click();

        await vi.waitFor(() => expect(payloads).toEqual([{pageUid: 42, languageUid: 3}]));
    });

    it('FREE and PRO entrypoints explicitly initialize the shared handler', async () => {
        const [{readFile}, {resolve}] = await Promise.all([
            import('node:fs/promises'),
            import('node:path'),
        ]);
        const root = resolve(process.cwd(), 'Resources/Public/JavaScript/backend');
        const [freeSource, proSource] = await Promise.all([
            readFile(resolve(root, 'module.free.js'), 'utf8'),
            readFile(resolve(root, 'module.pro.js'), 'utf8'),
        ]);

        expect(freeSource).toContain("import { initializeLocalPageScan } from './core/local-page-scan.js';");
        expect(freeSource).toContain('initializeLocalPageScan(module);');
        expect(proSource).toContain("import { initializeLocalPageScan } from './core/local-page-scan.js';");
        expect(proSource).toContain('initializeLocalPageScan(module);');
    });
});
