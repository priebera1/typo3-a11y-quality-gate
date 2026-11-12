// @vitest-environment jsdom

import {afterEach, describe, expect, it} from 'vitest';
import {initializeSectionNavigation} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

// AQG 3.0.0 AI settings length pass: the in-page navigation is plain anchor links; JavaScript only marks the section
// being read (aria-current="location") and never takes over navigation.

const markup = () => {
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true">
            <nav data-aqg-ai-sectionnav="true"><ol>
                <li><a href="#aqg-ai-section-provider" data-aqg-ai-section-link="provider">1</a></li>
                <li><a href="#aqg-ai-section-usage" data-aqg-ai-section-link="usage">3</a></li>
            </ol></nav>
            <section id="aqg-ai-section-provider"></section>
            <section id="aqg-ai-section-usage"></section>
        </div>`;
    return document.querySelector('[data-aqg-ai-settings]');
};

const place = (root, tops) => {
    root.querySelector('nav').getBoundingClientRect = () => ({top: 0, bottom: 60});
    Object.entries(tops).forEach(([id, top]) => {
        root.querySelector(`#${id}`).getBoundingClientRect = () => ({top, bottom: top + 400});
    });
};

describe('AI settings section navigation', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('marks the last section whose top passed the navigation, and follows clicks', () => {
        const root = markup();
        place(root, {'aqg-ai-section-provider': 100, 'aqg-ai-section-usage': 900});
        const update = initializeSectionNavigation(root);
        const [provider, usage] = root.querySelectorAll('a');
        expect(provider.getAttribute('aria-current')).toBe('location');

        place(root, {'aqg-ai-section-provider': -700, 'aqg-ai-section-usage': 70});
        update();
        expect(usage.getAttribute('aria-current')).toBe('location');
        expect(provider.hasAttribute('aria-current')).toBe(false);

        provider.dispatchEvent(new MouseEvent('click', {bubbles: true}));
        expect(provider.getAttribute('aria-current')).toBe('location');
        expect(provider.getAttribute('href')).toBe('#aqg-ai-section-provider');
    });

    it('does nothing without section links', () => {
        document.body.innerHTML = '<div data-aqg-ai-settings="true"></div>';
        expect(initializeSectionNavigation(document.body.firstElementChild)).toBeNull();
    });
});
