import {afterEach, describe, expect, it, vi} from 'vitest';
import {postRemediation} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';
import {askErrorLabelKey} from '@priebera/a11y-quality-gate/toolbar/a11y-toolbar-assistant.js';

// typo3test14, 2026-11-09: "What is wrong on this page?" answered "This did not work. Nothing was changed." An error
// answer without AQG's JSON — a 502/503/504 page a reverse proxy or CDN put in its place — is an upstream failure the
// toolbar names ("The AI provider did not answer"), not an unknown one. AQG's own codes always win.

const respond = (status, body, contentType = 'application/json') => {
    globalThis.fetch = vi.fn(async () => ({
        status,
        ok: status >= 200 && status < 300,
        redirected: false,
        headers: {get: () => contentType},
        json: async () => {
            if (typeof body === 'string') {
                throw new SyntaxError('not json');
            }
            return body;
        },
    }));
};

afterEach(() => {
    delete globalThis.fetch;
});

describe('postRemediation: error answers', () => {
    it.each([502, 503, 504])('a %s page without AQG JSON is upstream_unavailable', async (status) => {
        respond(status, '<html>Bad gateway</html>', 'text/html');
        const answer = await postRemediation('/typo3/ajax/a11y/toolbar/ask', {question: 'x'});
        expect(answer).toMatchObject({ok: false, code: 'upstream_unavailable', status});
        expect(askErrorLabelKey(answer.code)).toBe('errorProvider');
    });

    it('keeps AQG\'s own code on a 502 (the provider refusal answers)', async () => {
        respond(502, {success: false, status: 'error', code: 'paused', providerCode: 0});
        expect((await postRemediation('/x', {})).code).toBe('paused');
    });

    it('stays generic for other answers without a code', async () => {
        respond(500, '<html>oops</html>', 'text/html');
        expect((await postRemediation('/x', {})).code).toBe('generic');
        respond(200, {success: false});
        expect((await postRemediation('/x', {})).code).toBe('generic');
        expect(askErrorLabelKey('generic')).toBe('error');
    });
});
