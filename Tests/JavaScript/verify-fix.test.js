// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {initializeVerifyFix, runVerifyFix} from '../../Resources/Public/JavaScript/backend/pro/verify-fix.js';
import {resetAjaxGetHandler, resetAjaxPostHandler, setAjaxGetHandler, setAjaxPostHandler} from './stubs/ajax-request.js';

const ajaxUrls = {
    a11y_pro_verify_fix: '/typo3/ajax/a11y/pro/verify-fix',
    a11y_pro_crawl_status: '/typo3/ajax/a11y/pro/crawl/status',
    a11y_pro_crawl_summary: '/typo3/ajax/a11y/pro/crawl/summary',
    a11y_pro_verify_fix_result: '/typo3/ajax/a11y/pro/verify-fix/result',
};
const respond = (data) => ({resolve: vi.fn().mockResolvedValue(data)});

afterEach(() => {
    resetAjaxPostHandler();
    resetAjaxGetHandler();
});

describe('Verify fix', () => {
    it('sends only the finding id and follows the scan until it is stored before asking for the outcome', async () => {
        const post = vi.fn().mockReturnValue(respond({verificationUid: 7, jobId: 'job-9', siteIdentifier: 'main'}));
        setAjaxPostHandler(post);
        const statuses = [{status: 'running'}, {status: 'completed', persisted: false}];
        const calls = [];
        setAjaxGetHandler((url) => {
            calls.push(url);
            if (url.includes('/crawl/status')) {
                return respond(statuses.shift());
            }
            if (url.includes('/crawl/summary')) {
                return respond({success: true, saved: true});
            }
            return respond({outcome: 'resolved', label: 'Resolved', message: 'Gone.'});
        });

        const result = await runVerifyFix({findingId: 42, ajaxUrls, wait: () => Promise.resolve()});

        expect(post).toHaveBeenCalledWith(ajaxUrls.a11y_pro_verify_fix, {findingId: 42});
        expect(Object.keys(post.mock.calls[0][1])).toEqual(['findingId']);
        expect(calls.some((url) => url.includes('/crawl/summary') && url.includes('jobId=job-9'))).toBe(true);
        expect(calls.at(-1)).toContain('verificationUid=7');
        expect(result.outcome).toBe('resolved');
    });

    it('reports a failed scan as the server decides it, without claiming a resolution', async () => {
        setAjaxPostHandler(() => respond({verificationUid: 8, jobId: 'job-10', siteIdentifier: 'main'}));
        setAjaxGetHandler((url) => url.includes('/crawl/status')
            ? respond({status: 'failed'})
            : respond({outcome: 'not_verified', reason: 'scan_failed', label: 'Not verified'}));

        const result = await runVerifyFix({findingId: 43, ajaxUrls, wait: () => Promise.resolve()});

        expect(result.outcome).toBe('not_verified');
    });

    it('keeps following a verification that was still running when the page was reloaded', async () => {
        window.TYPO3 = {settings: {ajaxUrls}};
        const root = document.createElement('div');
        root.innerHTML = '<button type="button" data-action="a11y-verify-fix" data-finding-id="42"></button>'
            + '<p data-aqg-verify-fix-result="42" data-aqg-verify-fix-outcome="pending" data-aqg-verify-fix-verification="7"'
            + ' data-aqg-verify-fix-job="job-9" data-aqg-verify-fix-site="main"><strong>Checking…</strong></p>'
            + '<p data-aqg-verify-fix-result="43" data-aqg-verify-fix-outcome="resolved" data-aqg-verify-fix-verification="6"'
            + ' data-aqg-verify-fix-job="job-8" data-aqg-verify-fix-site="main"><strong>Resolved</strong></p>';
        document.body.append(root);
        const post = vi.fn();
        setAjaxPostHandler(post);
        const calls = [];
        let finish;
        const done = new Promise((resolve) => { finish = resolve; });
        setAjaxGetHandler((url) => {
            calls.push(url);
            if (url.includes('/crawl/status')) {
                return respond({status: 'completed', persisted: true});
            }
            finish();
            return respond({outcome: 'still_present', label: 'Still present', message: 'Still reported.'});
        });

        initializeVerifyFix(root);
        const button = root.querySelector('button');
        expect(button.disabled).toBe(true);
        await done;
        await vi.waitFor(() => expect(root.querySelector('[data-aqg-verify-fix-result="42"]').dataset.aqgVerifyFixOutcome).toBe('still_present'));

        expect(post).not.toHaveBeenCalled();
        expect(calls[0]).toContain('jobId=job-9');
        expect(calls.every((url) => !url.includes('job-8'))).toBe(true);
        expect(root.querySelector('[data-aqg-verify-fix-result="42"]').textContent).toContain('Still present');
        await vi.waitFor(() => expect(button.disabled).toBe(false));
        root.remove();
    });
});
