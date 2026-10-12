// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {switchScreenshotVariant} from '../../Resources/Public/JavaScript/backend/pro/screenshot-variant.js';

// Audit C15: new captures come as an untouched Original and a Highlighted image whose outlines carry the issue-type
// numbers. The page view switches between them in place; the enlarge button opens the image that is shown.

const render = () => {
    document.body.innerHTML = `
        <div class="aqg-shot">
            <button type="button" data-action="a11y-open-screenshot-modal" data-image-url="/shot?p=1"><img src="/shot?p=1" alt="Enlarge page screenshot"></button>
            <div role="group" aria-label="Screenshot view">
                <button type="button" id="hl" data-action="a11y-screenshot-variant" data-variant-url="/shot?p=1" aria-pressed="true">Highlighted</button>
                <button type="button" id="orig" data-action="a11y-screenshot-variant" data-variant-url="/shot?p=1&amp;variant=original" aria-pressed="false">Original</button>
            </div>
        </div>`;
};

describe('screenshot variant switch', () => {
    it('shows the Original and opens it when enlarged', () => {
        render();

        switchScreenshotVariant(document.getElementById('orig'));

        expect(document.querySelector('img').getAttribute('src')).toBe('/shot?p=1&variant=original');
        expect(document.querySelector('[data-action="a11y-open-screenshot-modal"]').dataset.imageUrl).toBe('/shot?p=1&variant=original');
        expect(document.getElementById('orig').getAttribute('aria-pressed')).toBe('true');
        expect(document.getElementById('hl').getAttribute('aria-pressed')).toBe('false');
    });

    it('ignores a button without a URL', () => {
        render();
        const broken = document.getElementById('orig');
        broken.removeAttribute('data-variant-url');

        switchScreenshotVariant(broken);

        expect(document.querySelector('img').getAttribute('src')).toBe('/shot?p=1');
        expect(document.getElementById('hl').getAttribute('aria-pressed')).toBe('true');
    });
});
