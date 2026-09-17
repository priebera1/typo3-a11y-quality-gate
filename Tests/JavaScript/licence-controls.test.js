// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';
import {resetAjaxPostHandler, setAjaxPostHandler} from './stubs/ajax-request.js';

const SAVED_KEY = 'aqg_live_saved_key';

const renderLicenceForm = ({savedKey = '', invalidStatus = false} = {}) => {
    document.body.innerHTML = `
        <form>
            <input type="text" data-a11y-licence-key-input="true" value="${savedKey}">
            ${savedKey !== '' ? '<button type="button" data-action="a11y-validate-licence">Revalidate</button>' : ''}
            <div data-a11y-licence-validate-result="true"></div>
            <button type="submit" data-aqg-licence-submit="true"
                    data-label-save-validate="Save and validate"
                    data-label-save="Save changes">${savedKey !== '' ? 'Save changes' : 'Save and validate'}</button>
        </form>
        ${invalidStatus ? '<section data-aqg-licence-state="api_unreachable"><a href="/licence" data-aqg-licence-action="retry">Retry</a></section>' : ''}
    `;

    const module = {
        translate: (_key, fallback) => fallback,
        escapeHtml: A11yFreeBackendModule.prototype.escapeHtml,
        formatPlanLabel: A11yFreeBackendModule.prototype.formatPlanLabel,
        revalidateLicence: A11yFreeBackendModule.prototype.revalidateLicence,
        reloadPage: vi.fn(),
    };
    A11yFreeBackendModule.prototype.initLicenceControls.call(module);

    return {
        module,
        input: document.querySelector('[data-a11y-licence-key-input="true"]'),
        revalidate: document.querySelector('[data-action="a11y-validate-licence"]'),
        submit: document.querySelector('[data-aqg-licence-submit="true"]'),
        result: document.querySelector('[data-a11y-licence-validate-result="true"]'),
    };
};

const type = (input, value) => {
    input.value = value;
    input.dispatchEvent(new Event('input', {bubbles: true}));
};

afterEach(() => {
    resetAjaxPostHandler();
    vi.useRealTimers();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('Licence key actions', () => {
    it('offers Save and validate as the only action for a new key', () => {
        const {input, revalidate, submit} = renderLicenceForm();

        expect(revalidate).toBeNull();
        expect(submit.textContent).toBe('Save changes');

        type(input, 'aqg_live_new_key');
        expect(submit.textContent).toBe('Save and validate');

        type(input, '');
        expect(submit.textContent).toBe('Save changes');
    });

    it('offers Revalidate only while the field still holds the saved key', () => {
        const {input, revalidate, submit} = renderLicenceForm({savedKey: SAVED_KEY});

        expect(revalidate.hidden).toBe(false);
        expect(submit.textContent).toBe('Save changes');

        type(input, 'aqg_live_other_key');
        expect(revalidate.hidden).toBe(true);
        expect(submit.textContent).toBe('Save and validate');

        type(input, SAVED_KEY);
        expect(revalidate.hidden).toBe(false);
        expect(submit.textContent).toBe('Save changes');
    });

    it('revalidates the saved key and refreshes a status that showed the licence as not valid', async () => {
        vi.useFakeTimers();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_validate_licence: '/typo3/ajax/licence/validate'}}};
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockResolvedValue({valid: true, plan: 'pro', domain: 'example.org'}),
        });
        setAjaxPostHandler(post);
        const {module, revalidate, result} = renderLicenceForm({savedKey: SAVED_KEY, invalidStatus: true});

        revalidate.click();
        await vi.waitFor(() => expect(result.textContent).toContain('Validated'));

        expect(post).toHaveBeenCalledWith('/typo3/ajax/licence/validate', {licenceKey: SAVED_KEY});
        expect(result.textContent).toContain('PRO');
        expect(result.innerHTML).toContain('<strong>example.org</strong>');
        vi.advanceTimersByTime(1000);
        expect(module.reloadPage).toHaveBeenCalledOnce();
    });

    it('shows the reason label, never the raw reason code, when the saved key is not valid', async () => {
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_validate_licence: '/typo3/ajax/licence/validate'}}};
        setAjaxPostHandler(vi.fn().mockReturnValue({
            resolve: vi.fn().mockResolvedValue({valid: false, reason: 'domain_mismatch', reasonLabel: 'This domain is not registered for the licence.'}),
        }));
        const {module, revalidate, result} = renderLicenceForm({savedKey: SAVED_KEY});

        revalidate.click();
        await vi.waitFor(() => expect(result.textContent).toContain('This domain is not registered for the licence.'));

        expect(result.textContent).not.toContain('domain_mismatch');
        expect(module.reloadPage).not.toHaveBeenCalled();
    });

    it('turns the status card retry link into an in-place revalidation', async () => {
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_validate_licence: '/typo3/ajax/licence/validate'}}};
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockResolvedValue({valid: false, reasonLabel: 'The AQG API could not be reached right now.'}),
        });
        setAjaxPostHandler(post);
        renderLicenceForm({savedKey: SAVED_KEY, invalidStatus: true});

        const retry = document.querySelector('[data-aqg-licence-action="retry"]');
        const click = new MouseEvent('click', {bubbles: true, cancelable: true});
        retry.dispatchEvent(click);

        expect(click.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(post).toHaveBeenCalledWith('/typo3/ajax/licence/validate', {licenceKey: SAVED_KEY}));
    });
});

describe('More actions menu', () => {
    beforeAll(() => {
        A11yFreeBackendModule.prototype.initDisclosureMenus.call({});
    });

    const renderMenu = () => {
        document.body.innerHTML = `
            <details class="aqg-menu" data-aqg-menu="true" open>
                <summary>More actions</summary>
                <div class="aqg-menu__panel">
                    <button type="button" class="aqg-menu__item" data-action="a11y-bulk-open-rule-page">Ignore all on this page (2)</button>
                </div>
            </details>
            <button type="button" id="outside">Outside</button>
        `;

        return document.querySelector('details[data-aqg-menu="true"]');
    };

    it('closes after an action was chosen', () => {
        const menu = renderMenu();

        menu.querySelector('.aqg-menu__item').click();

        expect(menu.open).toBe(false);
    });

    it('closes on a click elsewhere and stays open for clicks inside the panel', () => {
        const menu = renderMenu();

        menu.querySelector('.aqg-menu__panel').click();
        expect(menu.open).toBe(true);

        document.getElementById('outside').click();
        expect(menu.open).toBe(false);
    });

    it('closes on Escape and returns focus to its toggle', () => {
        const menu = renderMenu();
        const item = menu.querySelector('.aqg-menu__item');
        item.focus();

        item.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));

        expect(menu.open).toBe(false);
        expect(document.activeElement).toBe(menu.querySelector('summary'));
    });
});
