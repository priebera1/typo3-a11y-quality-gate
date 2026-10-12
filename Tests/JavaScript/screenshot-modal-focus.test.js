// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {modalCalls} from './stubs/modal.js';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// Audit control F13: closing the enlarged screenshot left focus on the document body, so a keyboard user started
// again at the top of the page. Focus returns to the screenshot that opened the modal.

describe('screenshot modal', () => {
    it('returns focus to the screenshot that opened it', () => {
        globalThis.TYPO3 = {lang: {}};
        document.body.innerHTML = `<button type="button" id="shot" data-action="a11y-open-screenshot-modal"
            data-image-url="/typo3/ajax/shot?remotePageUid=1" data-image-title="Page screenshot">Enlarge</button>
            <a id="elsewhere" href="#x">x</a>`;
        const trigger = document.querySelector('#shot');
        const context = {translate: (key, fallback) => fallback, showNotification: () => undefined};

        A11yProBackendModule.prototype.handleScreenshotModal.call(context, trigger);
        const {configuration, element} = modalCalls.at(-1);
        expect(configuration.title).toBe('Page screenshot');

        document.querySelector('#elsewhere').focus();
        element.dispatchEvent(new Event('typo3-modal-hidden'));

        expect(document.activeElement).toBe(trigger);
    });
});
