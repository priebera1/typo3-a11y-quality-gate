// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {
    A11yProBackendModule,
    REMOTE_SUBMIT_CONFIRM_ATTEMPTS,
} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';
import {resetAjaxPostHandler, setAjaxPostHandler} from './stubs/ajax-request.js';

// AQG 2.0.2 persona audit O1: a paid submit that timed out could still have been accepted by the crawler; the scan
// then ran as an orphan and the retry got "a scan is already running". TYPO3 now keeps the submit's Idempotency-Key and
// answers `submit_unconfirmed`; the module sends the same submit again and follows the scan the crawler returns.
const answer = (status, body) => ({
    response: {
        status,
        json: async () => body,
        clone: () => ({json: async () => body}),
    },
});
const unconfirmed = () => answer(503, {success: false, code: 'submit_unconfirmed', title: 'Scan start not confirmed', message: 'The AQG scanner did not confirm the frontend scan in time.'});
const accepted = {success: true, jobId: 'a0000000-0000-4000-8000-000000000001', siteIdentifier: 'main', status: 'queued'};

function moduleStub() {
    return {
        translate: (_key, fallback) => fallback,
        updateRemoteScanUi: vi.fn(),
        extractAjaxErrorData: A11yProBackendModule.prototype.extractAjaxErrorData,
        parseJsonSafely: () => null,
        extractCrawlerBodyJson: () => null,
    };
}

beforeEach(() => {
    vi.spyOn(window, 'setTimeout').mockImplementation((callback) => {
        callback();
        return 0;
    });
});

afterEach(() => {
    resetAjaxPostHandler();
    vi.restoreAllMocks();
});

describe('a frontend scan submit the AQG scanner did not confirm', () => {
    it('is sent again and follows the scan the scanner returns', async () => {
        const payloads = [];
        setAjaxPostHandler(async (_endpoint, payload) => {
            payloads.push(payload);
            if (payloads.length === 1) {
                throw unconfirmed();
            }
            return {resolve: async () => accepted};
        });
        const module = moduleStub();

        const result = await A11yProBackendModule.prototype.postRemoteSubmit.call(module, '/submit', {rootPid: 1, siteIdentifier: 'main'});

        expect(result).toEqual(accepted);
        expect(payloads).toEqual([{rootPid: 1, siteIdentifier: 'main'}, {rootPid: 1, siteIdentifier: 'main'}]);
        expect(module.updateRemoteScanUi).toHaveBeenCalledWith(expect.objectContaining({
            visible: true,
            message: 'Checking whether the AQG scanner accepted the scan…',
        }));
    });

    it('is given up after a few attempts with the unconfirmed answer', async () => {
        let attempts = 0;
        setAjaxPostHandler(async () => {
            attempts++;
            throw unconfirmed();
        });

        await expect(A11yProBackendModule.prototype.postRemoteSubmit.call(moduleStub(), '/submit', {})).rejects.toMatchObject({response: {status: 503}});
        expect(attempts).toBe(REMOTE_SUBMIT_CONFIRM_ATTEMPTS);
    });

    it('is not repeated for a definitive refusal', async () => {
        let attempts = 0;
        setAjaxPostHandler(async () => {
            attempts++;
            throw answer(429, {success: false, code: 'too_many_active_jobs'});
        });

        await expect(A11yProBackendModule.prototype.postRemoteSubmit.call(moduleStub(), '/submit', {})).rejects.toBeTruthy();
        expect(attempts).toBe(1);
    });
});

describe('a frontend scan the AQG scanner could not complete (D3b)', () => {
    it('reports the translated reason of its failure code, not the generic line', async () => {
        const module = {
            translate: (_key, fallback) => fallback,
            pollRemoteCrawlerJob: async () => ({
                success: true,
                status: 'failed',
                failureCode: 'sitemap_not_found',
                title: 'Sitemap not found',
                message: 'The site\'s sitemap answered "not found" to the AQG scanner, so no page was scanned.',
            }),
            extractReadableRemoteError: A11yProBackendModule.prototype.extractReadableRemoteError,
            isSessionExpiredError: () => false,
            buildFallbackMessageFromErrorPayload: () => '',
            normalizeRemoteErrorMessage: A11yProBackendModule.prototype.normalizeRemoteErrorMessage,
        };

        const failure = await A11yProBackendModule.prototype.monitorRemoteScan.call(module, {jobId: 'job-1', siteIdentifier: 'main'})
            .then(() => null, (error) => error);

        expect(failure).toBeInstanceOf(Error);
        expect(failure.code).toBe('sitemap_not_found');
        expect(failure.message).toBe('Sitemap not found: The site\'s sitemap answered "not found" to the AQG scanner, so no page was scanned.');
        expect(failure.message).not.toContain('did not complete successfully');
    });

    it('keeps the generic line for a status without a reason (an older AQG service)', async () => {
        const module = {
            translate: (_key, fallback) => fallback,
            pollRemoteCrawlerJob: async () => ({success: true, status: 'failed'}),
            extractReadableRemoteError: A11yProBackendModule.prototype.extractReadableRemoteError,
            isSessionExpiredError: () => false,
            buildFallbackMessageFromErrorPayload: () => '',
            normalizeRemoteErrorMessage: A11yProBackendModule.prototype.normalizeRemoteErrorMessage,
        };

        await expect(A11yProBackendModule.prototype.monitorRemoteScan.call(module, {jobId: 'job-1', siteIdentifier: 'main'}))
            .rejects.toThrow('Frontend scan did not complete successfully.');
    });
});
