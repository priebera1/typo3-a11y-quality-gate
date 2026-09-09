// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';
import {
    resetAjaxPostHandler,
    setAjaxPostHandler,
} from './stubs/ajax-request.js';

const createFreeButton = () => {
    const button = document.createElement('button');
    button.dataset.aqgFreePreviewSubmit = 'true';
    button.dataset.rootPid = '1';
    button.dataset.currentPageUid = '42';
    button.dataset.siteIdentifier = 'main';
    button.dataset.freeSubmitIntent = 'signed-intent';
    document.body.append(button);

    return button;
};

const freePreviewTemplate = readFileSync(
    resolve(process.cwd(), 'Resources/Private/Partials/Overview/FreeRemotePreview.html'),
    'utf8',
);

afterEach(() => {
    resetAjaxPostHandler();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('Free Remote Preview browser boundary', () => {
    it('keeps the available, used and localized reset UI contracts in the Fluid template', () => {
        expect(freePreviewTemplate).toContain("{freePreview.state} == 'FREE_AVAILABLE'");
        expect(freePreviewTemplate).toContain('Scan this page — Free');
        expect(freePreviewTemplate).toContain('Free scans today');
        expect(freePreviewTemplate).toContain('%1$d of %2$d used');
        expect(freePreviewTemplate).toContain('freePreview.scansRemaining');
        expect(freePreviewTemplate).toContain('Free scan limit reached for today.');
        expect(freePreviewTemplate).toContain('{freePreview.hasTodayResult}');
        expect(freePreviewTemplate).toContain("View this page's result");
        expect(freePreviewTemplate).toContain('datetime="{freePreview.resetsAt}"');
        expect(freePreviewTemplate).toContain("f:format.date(format: 'd.m.Y H:i')");
        expect(freePreviewTemplate).not.toContain('>{freePreview.resetsAt}</time>');
        expect(freePreviewTemplate).not.toContain('freePreview.pagesUsed');
        expect(freePreviewTemplate).not.toContain('freePreview.pagesLimit');
        expect(freePreviewTemplate).not.toContain('data-max-pages=');
    });

    it('submits only the minimal server-bound intent payload', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_pro_crawl_submit: '/typo3/ajax/a11y/crawl/submit',
                },
            },
        };
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockResolvedValue({success: true, jobId: 'job-1'}),
        });
        setAjaxPostHandler(post);
        const module = {
            resolveScanLanguageUid: vi.fn().mockReturnValue(0),
            setLoadingState: vi.fn(),
            setScanInProgress: vi.fn(),
            handleProSubmitPayload: vi.fn().mockResolvedValue(undefined),
            showNotification: vi.fn(),
            translate: (_key, fallback) => fallback,
            remoteSubmitInProgress: false,
        };

        await A11yProBackendModule.prototype.handleProScanSite.call(module, createFreeButton());

        expect(post).toHaveBeenCalledWith('/typo3/ajax/a11y/crawl/submit', {
            rootPid: 1,
            pageUid: 42,
            siteIdentifier: 'main',
            freeSubmitIntent: 'signed-intent',
        });
        const payload = post.mock.calls[0][1];
        expect(payload).not.toHaveProperty('maxPages');
        expect(payload).not.toHaveProperty('axeLocale');
        expect(payload).not.toHaveProperty('followLinks');
        expect(payload).not.toHaveProperty('accessToken');
        expect(payload).not.toHaveProperty('licenceKey');
        expect(payload).not.toHaveProperty('installationId');
        expect(payload).not.toHaveProperty('pageUrl');
        expect(module.handleProSubmitPayload).toHaveBeenCalledWith(
            expect.any(HTMLButtonElement),
            {success: true, jobId: 'job-1'},
            {
                fallbackScope: 'page',
                fallbackPageUid: 42,
                fallbackLanguageUid: 0,
            },
        );
    });

    it.each([
        'API_UNAVAILABLE',
        'TOKEN_ERROR',
        'MISSING_INSTALLATION_ID',
        'FREE_LIMIT_REACHED',
        'FREE_USED_TODAY',
        'IDEMPOTENCY_CONFLICT',
        'FEATURE_NOT_AVAILABLE',
        'PROOF_ERROR',
        'REMOTE_SCAN_ACTIVE',
    ])('locks the submit control for the safe %s state', (state) => {
        document.body.innerHTML = `
            <section data-aqg-free-preview="true">
                <div data-aqg-free-preview-message="true"></div>
                <button data-aqg-free-preview-submit="true"></button>
            </section>
        `;
        const module = {
            extractReadableRemoteError: vi.fn().mockReturnValue('Safe message'),
        };

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(module, {state}, 'Fallback');

        const preview = document.querySelector('[data-aqg-free-preview="true"]');
        expect(preview.dataset.aqgFreePreviewState).toBe(state);
        expect(preview.querySelector('[data-aqg-free-preview-submit="true"]').disabled).toBe(true);
        expect(preview.textContent).toContain('Safe message');
    });

    it('keeps FREE_AVAILABLE submit-capable and hides Retry', () => {
        document.body.innerHTML = `
            <section data-aqg-free-preview="true">
                <div data-aqg-free-preview-message="true"></div>
                <button data-aqg-free-preview-submit="true" disabled>Scan this page — Free</button>
                <button data-action="a11y-free-preview-retry">Retry</button>
            </section>
        `;
        const module = {
            extractReadableRemoteError: vi.fn().mockReturnValue('Available.'),
        };

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(
            module,
            {state: 'FREE_AVAILABLE'},
            'Fallback',
        );

        expect(document.querySelector('[data-aqg-free-preview-submit="true"]').disabled).toBe(false);
        expect(document.querySelector('[data-action="a11y-free-preview-retry"]').hidden).toBe(true);
    });

    it('shows Retry for an unavailable API while keeping submit locked', () => {
        document.body.innerHTML = `
            <section data-aqg-free-preview="true">
                <div data-aqg-free-preview-message="true"></div>
                <button data-aqg-free-preview-submit="true"></button>
                <button data-action="a11y-free-preview-retry" hidden>Retry</button>
            </section>
        `;
        const module = {
            extractReadableRemoteError: vi.fn().mockReturnValue('Temporarily unavailable.'),
        };

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(
            module,
            {state: 'API_UNAVAILABLE'},
            'Fallback',
        );

        expect(document.querySelector('[data-aqg-free-preview-submit="true"]').disabled).toBe(true);
        expect(document.querySelector('[data-action="a11y-free-preview-retry"]').hidden).toBe(false);
    });

    it('keeps proof failures locked after submit loading cleanup', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_pro_crawl_submit: '/typo3/ajax/a11y/crawl/submit',
                },
            },
        };
        document.body.innerHTML = `
            <section data-aqg-free-preview="true">
                <div data-aqg-free-preview-message="true"></div>
            </section>
        `;
        const button = createFreeButton();
        document.querySelector('[data-aqg-free-preview="true"]').append(button);
        setAjaxPostHandler(() => ({
            resolve: vi.fn().mockRejectedValue(new Error('Proof rejected')),
        }));
        const module = {
            resolveScanLanguageUid: vi.fn().mockReturnValue(0),
            setLoadingState: (_button, isLoading) => {
                _button.disabled = isLoading;
            },
            setScanInProgress: vi.fn(),
            extractAjaxErrorData: vi.fn().mockResolvedValue({
                state: 'PROOF_ERROR',
                code: 'invalid_installation_proof',
                message: 'Proof could not be verified.',
            }),
            extractReadableRemoteError: vi.fn().mockReturnValue('Proof could not be verified.'),
            buildRemoteErrorNotificationText: vi.fn().mockReturnValue('Proof could not be verified.'),
            applyFreePreviewErrorState(errorPayload, fallbackMessage) {
                return A11yProBackendModule.prototype.applyFreePreviewErrorState.call(
                    this,
                    errorPayload,
                    fallbackMessage,
                );
            },
            resetRemoteScanDomState: vi.fn(),
            updateRemoteScanUi: vi.fn(),
            showNotification: vi.fn(),
            translate: (_key, fallback) => fallback,
            remoteSubmitInProgress: false,
        };

        await A11yProBackendModule.prototype.handleProScanSite.call(module, button);

        expect(button.disabled).toBe(true);
        expect(document.querySelector('[data-aqg-free-preview="true"]').dataset.aqgFreePreviewState)
            .toBe('PROOF_ERROR');
    });

    it('forces a server-side entitlement refresh so Retry is never answered from cache', () => {
        const original = window.location;
        const assign = vi.fn();

        delete window.location;
        window.location = {
            href: 'https://typo3.test/typo3/module/content/a11y?id=729&site=aqg',
            assign,
        };

        try {
            A11yProBackendModule.prototype.retryFreePreview.call({});
        } finally {
            delete window.location;
            window.location = original;
        }

        expect(assign).toHaveBeenCalledOnce();

        const target = new URL(assign.mock.calls[0][0]);
        expect(target.searchParams.get('aqgFreeRefresh')).toBe('1');
        // The page context must survive the retry.
        expect(target.searchParams.get('id')).toBe('729');
        expect(target.searchParams.get('site')).toBe('aqg');
    });

    it('drops the refresh flag from the URL so the cache bypass is one-shot', () => {
        // The refreshed request has already been served by the time the module boots. If the flag
        // stayed in the address bar, every later reload — and any copied link — would bypass the
        // bounded entitlement cache again and re-hit the API.
        const replaceState = vi.fn();
        const originalLocation = window.location;
        const originalReplaceState = window.history.replaceState;
        window.history.replaceState = replaceState;

        delete window.location;
        window.location = {
            href: 'https://typo3.test/typo3/module/content/a11y?id=729&site=aqg&aqgFreeRefresh=1&aqgSource=remote',
        };

        try {
            A11yProBackendModule.prototype.consumeFreeRefreshFlag.call({});
        } finally {
            delete window.location;
            window.location = originalLocation;
            window.history.replaceState = originalReplaceState;
        }

        expect(replaceState).toHaveBeenCalledOnce();

        const rewritten = new URL(replaceState.mock.calls[0][2]);
        expect(rewritten.searchParams.has('aqgFreeRefresh')).toBe(false);
        // The page context and the selected source tab must survive the rewrite.
        expect(rewritten.searchParams.get('id')).toBe('729');
        expect(rewritten.searchParams.get('site')).toBe('aqg');
        expect(rewritten.searchParams.get('aqgSource')).toBe('remote');
    });

    it('consumes the refresh flag on module boot, not only when called directly', () => {
        // Without this wiring the flag would survive in the address bar and the bypass would stop
        // being one-shot, even though consumeFreeRefreshFlag() itself is correct.
        const source = readFileSync(
            resolve(process.cwd(), 'Resources/Public/JavaScript/backend/pro/pro-module.js'),
            'utf8',
        );
        const constructorBody = source.slice(source.indexOf('constructor()'), source.indexOf('initFreePreviewCountdown()'));

        expect(constructorBody).toContain('this.consumeFreeRefreshFlag();');
    });

    it('leaves an ordinary module URL untouched, so normal reloads use the cache', () => {
        const replaceState = vi.fn();
        const originalLocation = window.location;
        const originalReplaceState = window.history.replaceState;
        window.history.replaceState = replaceState;

        delete window.location;
        window.location = {href: 'https://typo3.test/typo3/module/content/a11y?id=729&site=aqg'};

        try {
            A11yProBackendModule.prototype.consumeFreeRefreshFlag.call({});
        } finally {
            delete window.location;
            window.location = originalLocation;
            window.history.replaceState = originalReplaceState;
        }

        // No rewrite at all: nothing to consume, and no history churn on every module load.
        expect(replaceState).not.toHaveBeenCalled();
    });

    it('reloads the server-derived entitlement state from the Retry action', () => {
        document.body.innerHTML = '<button data-action="a11y-free-preview-retry">Retry</button>';
        const module = {
            retryFreePreview: vi.fn(),
        };

        A11yProBackendModule.prototype.bindProEvents.call(module);
        document.querySelector('[data-action="a11y-free-preview-retry"]').click();

        expect(module.retryFreePreview).toHaveBeenCalledOnce();
    });
});
