// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {A11yBaseModule} from '../../Resources/Public/JavaScript/backend/core/base-module.js';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';
import {
    resetAjaxPostHandler,
    setAjaxPostHandler,
} from './stubs/ajax-request.js';

const createButton = () => {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.pageUid = '42';
    button.dataset.languageUid = '3';
    button.dataset.loadingText = 'Scanning...';
    button.textContent = 'Scan this page';
    document.body.append(button);
    return button;
};

const createModule = () => ({
    translations: {},
    localScanCancellationRequested: false,
    setLoadingState(button, loading) {
        A11yBaseModule.prototype.setLoadingState.call(this, button, loading);
    },
    updateLocalScanProgress: vi.fn(),
    yieldForPaint: vi.fn().mockResolvedValue(undefined),
    isLocalScanCancelledResponse: vi.fn().mockReturnValue(false),
    showNotification: vi.fn(),
    translate(_key, fallback) {
        return fallback;
    },
    format(template, ...values) {
        return A11yBaseModule.prototype.format.call(this, template, ...values);
    },
    showScanWarnings: vi.fn(),
    reloadCurrentModule: vi.fn(),
});

afterEach(() => {
    resetAjaxPostHandler();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('local page scan request', () => {
    it('sends the production endpoint payload and exposes loading and success states', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_scan_page: '/typo3/ajax/a11y/scan/page',
                },
            },
        };
        const button = createButton();
        const module = createModule();
        let resolveResponse;
        const responsePromise = new Promise((resolve) => {
            resolveResponse = resolve;
        });
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockReturnValue(responsePromise),
        });
        setAjaxPostHandler(post);

        const request = A11yFreeBackendModule.prototype.handleRescan.call(module, button);

        await vi.waitFor(() => {
            expect(post).toHaveBeenCalledWith('/typo3/ajax/a11y/scan/page', {
                pageUid: 42,
                languageUid: 3,
            });
        });
        expect(button.disabled).toBe(true);
        expect(button.textContent).toBe('Scanning...');
        expect(module.updateLocalScanProgress).toHaveBeenCalledWith(
            true,
            'This usually takes a moment. You can view progress while it runs.',
            expect.any(Date),
        );

        resolveResponse({issuesNew: 2, issuesResolved: 1, warnings: []});
        await request;

        expect(module.showNotification).toHaveBeenCalledWith(
            'Scan complete — 2 new, 1 resolved.',
            'success',
        );
        expect(module.updateLocalScanProgress).toHaveBeenLastCalledWith(
            true,
            'Scan completed. Reloading results...',
            null,
            'completed',
        );
        expect(module.reloadCurrentModule).toHaveBeenCalledWith(2600);
    });

    it('restores the button and reports a backend failure', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_scan_page: '/typo3/ajax/a11y/scan/page',
                },
            },
        };
        const button = createButton();
        const module = createModule();
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockRejectedValue(new Error('Backend failed')),
        });
        setAjaxPostHandler(post);

        await A11yFreeBackendModule.prototype.handleRescan.call(module, button);

        expect(module.showNotification).toHaveBeenCalledWith(
            'Scan failed: Backend failed',
            'error',
        );
        expect(module.updateLocalScanProgress).toHaveBeenLastCalledWith(false, '');
        expect(button.disabled).toBe(false);
        expect(button.textContent).toBe('Scan this page');
        expect(module.reloadCurrentModule).not.toHaveBeenCalled();
    });
});
