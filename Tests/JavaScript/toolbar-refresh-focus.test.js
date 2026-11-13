// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {replaceMenuContent} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

// Regression (T1): the toolbar's 15 s status refresh rewrote the open dropdown, so a keyboard or screen-reader user
// who had tabbed into it lost focus to the document body (and, during a running scan, on every poll).
const dropdown = (progress = 10) => `
    <div class="aqt-pop" role="region" aria-label="Accessibility">
        <div class="aqt-section"><p>Content scan</p><div class="aqt-progress"><span style="width: ${progress}%"></span></div></div>
        <div class="aqt-pop__foot"><a href="/typo3/module/web/a11y?token=x" class="btn">Open Accessibility module</a></div>
    </div>`;

const mountMenu = () => {
    document.body.innerHTML = `
        <div id="toolbar-item">
            <button class="toolbar-item-link dropdown-toggle" aria-expanded="true">Accessibility</button>
            <div class="dropdown-menu show"></div>
        </div>
        <a href="/elsewhere" id="elsewhere">Elsewhere</a>`;

    return document.querySelector('.dropdown-menu');
};

const progressOf = (menu) => menu.querySelector('.aqt-progress span').style.width;

afterEach(() => {
    document.body.innerHTML = '';
});

describe('toolbar dropdown refresh', () => {
    it('does not touch the dropdown while focus is inside it', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10));
        const link = menu.querySelector('a');
        link.focus();

        expect(replaceMenuContent(menu, dropdown(40))).toBe(false);
        expect(replaceMenuContent(menu, dropdown(70))).toBe(false);

        expect(menu.querySelector('a')).toBe(link);
        expect(document.activeElement).toBe(link);
        expect(progressOf(menu)).toBe('10%');
    });

    it('puts the newest status in as soon as focus leaves the dropdown', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10));
        menu.querySelector('a').focus();
        const onApplied = vi.fn();
        replaceMenuContent(menu, dropdown(40), onApplied);
        replaceMenuContent(menu, dropdown(70), onApplied);

        document.getElementById('elsewhere').focus();

        expect(progressOf(menu)).toBe('70%');
        expect(onApplied).toHaveBeenCalledTimes(1);
        expect(document.activeElement.id).toBe('elsewhere');
    });

    it('keeps waiting while focus moves within the dropdown', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10).replace('</div>\n    </div>', '<button type="button">Second</button></div>\n    </div>'));
        menu.querySelector('a').focus();
        replaceMenuContent(menu, dropdown(40));

        menu.querySelector('button').focus();

        expect(progressOf(menu)).toBe('10%');
        expect(document.activeElement.textContent).toBe('Second');
    });

    it('replaces changed markup at once when focus is outside the dropdown', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10));
        const toggle = document.querySelector('.dropdown-toggle');
        toggle.focus();

        expect(replaceMenuContent(menu, dropdown(30))).toBe(true);

        expect(progressOf(menu)).toBe('30%');
        expect(document.activeElement).toBe(toggle);
    });

    it('leaves unchanged markup alone', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10));
        const link = menu.querySelector('a');

        expect(replaceMenuContent(menu, dropdown(10))).toBe(false);

        expect(menu.querySelector('a')).toBe(link);
    });

    it('drops a waiting update when the markup changed back to what is shown', () => {
        const menu = mountMenu();
        replaceMenuContent(menu, dropdown(10));
        menu.querySelector('a').focus();
        replaceMenuContent(menu, dropdown(40));
        replaceMenuContent(menu, dropdown(10));

        document.getElementById('elsewhere').focus();

        expect(progressOf(menu)).toBe('10%');
    });
});
