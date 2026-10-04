// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {dismissUpdateNotice} from '../../Resources/Public/JavaScript/backend/update-notice.js';
import {resetAjaxPostHandler, setAjaxPostHandler} from './stubs/ajax-request.js';

const DISMISS_URL = '/typo3/ajax/a11y/update-notice/dismiss?token=route-token';

const mount = () => {
    document.body.innerHTML = `
        <div class="aqg-module">
            <header><h2 class="aqg-page-head__title">Accessibility overview</h2></header>
            <div class="aqg-update-notice" data-aqg-update-notice="1.9.9"
                 data-label-notification-title="Barrierefreiheit"
                 data-label-dismiss-failed="Der Hinweis konnte nicht ausgeblendet werden. Versuchen Sie es erneut.">
                <p><strong>AQG 1.9.9 is available</strong> <span>You're using 1.9.8.</span></p>
                <a href="https://typo3.priebera.sk/docs/changelog#v199">What's new</a>
                <button type="button" data-action="a11y-dismiss-update-notice" data-version="1.9.9"
                        aria-label="Hide the notice about AQG 1.9.9">×</button>
            </div>
        </div>`;

    return document.querySelector('[data-action="a11y-dismiss-update-notice"]');
};

const answer = (data) => ({resolve: vi.fn().mockResolvedValue(data)});

beforeEach(() => {
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_update_notice_dismiss: DISMISS_URL}}};
});

afterEach(() => {
    resetAjaxPostHandler();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('AQG update notice', () => {
    it('stores the dismissal on the server with nothing but the release version', async () => {
        const post = vi.fn().mockReturnValue(answer({success: true, version: '1.9.9'}));
        setAjaxPostHandler(post);

        expect(await dismissUpdateNotice(mount())).toBe(true);

        expect(post).toHaveBeenCalledOnce();
        expect(post).toHaveBeenCalledWith(DISMISS_URL, {version: '1.9.9'});
        expect(document.querySelector('[data-aqg-update-notice]')).toBeNull();
    });

    it('keeps the keyboard focus in the module after the dismissed notice is gone', async () => {
        setAjaxPostHandler(() => answer({success: true}));
        const button = mount();
        button.focus();

        await dismissUpdateNotice(button);

        const heading = document.querySelector('.aqg-page-head__title');
        expect(document.activeElement).toBe(heading);
        expect(heading.getAttribute('tabindex')).toBe('-1');
        heading.dispatchEvent(new FocusEvent('blur'));
        expect(heading.hasAttribute('tabindex')).toBe(false);
    });

    it('does not move the focus when the notice was dismissed by pointer from elsewhere', async () => {
        setAjaxPostHandler(() => answer({success: true}));
        const button = mount();
        const other = document.createElement('button');
        document.body.append(other);
        other.focus();

        await dismissUpdateNotice(button);

        expect(document.activeElement).toBe(other);
    });

    it('keeps the notice and says so when the server did not store the dismissal', async () => {
        setAjaxPostHandler(() => ({resolve: vi.fn().mockRejectedValue(new Error('403'))}));
        const showMessage = vi.fn();
        globalThis.TYPO3.Notification = {showMessage};
        const button = mount();

        expect(await dismissUpdateNotice(button)).toBe(false);

        expect(document.querySelector('[data-aqg-update-notice]')).not.toBeNull();
        expect(button.disabled).toBe(false);
        expect(showMessage).toHaveBeenCalledWith(
            'Barrierefreiheit',
            'Der Hinweis konnte nicht ausgeblendet werden. Versuchen Sie es erneut.',
            -1,
            5,
        );
    });

    it('treats an unsuccessful answer as a failure', async () => {
        setAjaxPostHandler(() => answer({success: false, code: 'access_denied'}));
        const button = mount();

        expect(await dismissUpdateNotice(button)).toBe(false);
        expect(document.querySelector('[data-aqg-update-notice]')).not.toBeNull();
        expect(button.title).toContain('konnte nicht ausgeblendet werden');
    });

    it('sends nothing without the tokenized route URL', async () => {
        const post = vi.fn();
        setAjaxPostHandler(post);
        globalThis.TYPO3 = {settings: {ajaxUrls: {}}};

        expect(await dismissUpdateNotice(mount())).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('dismisses from a click on the close button', async () => {
        const post = vi.fn().mockReturnValue(answer({success: true}));
        setAjaxPostHandler(post);
        mount().click();

        await vi.waitFor(() => expect(document.querySelector('[data-aqg-update-notice]')).toBeNull());
        expect(post).toHaveBeenCalledWith(DISMISS_URL, {version: '1.9.9'});
    });
});
