// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// TYPO3 answers the AJAX request of an expired backend login with 401. A scan started then showed
// "Content scan failed: Unknown error" (2026-10-16 audit, TYPO3 14.3); it names the login instead.
// TYPO3 core answers 401 with an empty body (verified on TYPO3 14.3).
const expired = () => ({
    response: {
        status: 401,
        clone: () => ({json: async () => { throw new SyntaxError('Unexpected end of JSON input'); }}),
    },
});

const free = {
    translate: (_key, fallback) => fallback,
    isSessionExpiredError: A11yFreeBackendModule.prototype.isSessionExpiredError,
    sessionExpiredMessage: A11yFreeBackendModule.prototype.sessionExpiredMessage,
};

describe('expired backend login', () => {
    it('is named for a content scan', async () => {
        const message = await A11yFreeBackendModule.prototype.readableLocalScanError.call(free, expired());

        expect(message).toBe('Your TYPO3 login has expired. Log in again, then repeat the action.');
    });

    it('is named for a frontend scan', () => {
        const pro = {...free, buildFallbackMessageFromErrorPayload: () => '', normalizeRemoteErrorMessage: (value) => String(value || '').trim()};
        const message = A11yProBackendModule.prototype.extractReadableRemoteError.call(pro, null, expired(), 'Frontend scan failed.');

        expect(message).toBe('Your TYPO3 login has expired. Log in again, then repeat the action.');
    });

    it('is not claimed for a 401 that carries an AQG answer, such as a Free Remote Preview token error', () => {
        const pro = {...free, buildFallbackMessageFromErrorPayload: () => '', normalizeRemoteErrorMessage: (value) => String(value || '').trim()};
        const payload = {success: false, code: 'token_expired', state: 'TOKEN_ERROR', message: 'Reload the page and start the Free Remote Preview again.'};
        const message = A11yProBackendModule.prototype.extractReadableRemoteError.call(pro, payload, expired(), 'Frontend scan failed.');

        expect(message).toBe('Reload the page and start the Free Remote Preview again.');
    });

    it('leaves other refusals to their own message', async () => {
        const forbidden = {response: {status: 403, clone: () => ({json: async () => ({error: 'Access denied'})})}};

        expect(await A11yFreeBackendModule.prototype.readableLocalScanError.call(free, forbidden)).toBe('Access denied');
    });
});
