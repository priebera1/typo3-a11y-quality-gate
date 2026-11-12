// @vitest-environment jsdom

import {beforeAll, beforeEach, describe, expect, it} from 'vitest';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';

// AQG 3.0 video QA: a German backend showed "All changes saved" in the Rules actionbar. The translated texts are
// rendered on the actionbar's meta container (Settings/TabRules.html), and the script read them from the actionbar.

beforeAll(() => {
    globalThis.TYPO3 = {lang: {}};
});

let module;
beforeEach(() => {
    document.body.innerHTML = `
        <form data-a11y-rules-form="true" data-label-change-one="%d Änderung" data-label-change-other="%d Änderungen">
            <input type="checkbox" id="rule" data-a11y-rules-input="true" checked>
            <div class="aqg-actionbar" data-a11y-rules-actionbar="true">
                <div class="aqg-actionbar__meta"
                     data-a11y-rules-saved-meta="Alle Änderungen gespeichert · 30.10.2026 10:00"
                     data-a11y-rules-dirty-meta="Nicht gespeicherte Änderungen">
                    <span data-a11y-rules-meta-label="true">Alle Änderungen gespeichert · 30.10.2026 10:00</span>
                </div>
                <button type="button" data-a11y-rules-discard="true" hidden>Verwerfen</button>
                <button type="submit" data-a11y-rules-save="true" disabled>Speichern</button>
            </div>
        </form>`;
    module = Object.create(A11yFreeBackendModule.prototype);
    module.initRulesTab();
});

describe('rules actionbar', () => {
    it('keeps the translated saved and unsaved texts', () => {
        const label = document.querySelector('[data-a11y-rules-meta-label]');
        expect(label.textContent).toBe('Alle Änderungen gespeichert · 30.10.2026 10:00');

        const checkbox = document.getElementById('rule');
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', {bubbles: true}));
        expect(label.textContent).toBe('Nicht gespeicherte Änderungen — 1 Änderung');

        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', {bubbles: true}));
        expect(label.textContent).toBe('Alle Änderungen gespeichert · 30.10.2026 10:00');
    });
});
