// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// A site the AQG crawler cannot reach (DDEV, localhost, private network, VPN, internal DNS) must not end in a bare
// "private_network_blocked": the Free Remote Preview shows why and what to do instead.

const mountPreview = ({withTemplate = true} = {}) => {
    document.body.innerHTML = `
        <div class="a11y-overview">
            <div data-a11y-overview-panel="local"><h2 class="aqg-section__title">Content scan</h2></div>
            <div data-a11y-overview-panel="remote" hidden>
                <section data-aqg-free-preview="true">
                    <h2 id="aqg-free-preview-title" tabindex="-1">Frontend scan</h2>
                    <div data-aqg-free-preview-message="true"></div>
                    ${withTemplate ? `<template data-aqg-free-preview-unreachable-template="true">
                        <div class="aqg-notice aqg-tone-info" data-aqg-free-preview-unreachable="blocked">
                            <strong>The AQG crawler cannot reach this site</strong>
                            <button type="button" data-action="a11y-show-local-scan">Show content scan</button>
                        </div>
                    </template>` : ''}
                    <button data-aqg-free-preview-submit="true">Scan this page — Free</button>
                    <button data-action="a11y-free-preview-retry" hidden>Retry</button>
                </section>
            </div>
        </div>`;
};

const module = () => ({
    extractReadableRemoteError: vi.fn().mockReturnValue('The site address is not reachable.'),
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Free Remote Preview guidance for unreachable sites', () => {
    it.each(['private_network_blocked', 'dns_lookup_failed'])('explains a %s refusal with the next steps', (code) => {
        mountPreview();

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(module(), {state: 'INVALID_SITE', code}, 'Fallback');

        const message = document.querySelector('[data-aqg-free-preview-message="true"]');
        expect(message.querySelector('[data-aqg-free-preview-unreachable="blocked"]')).not.toBeNull();
        expect(message.textContent).toContain('The AQG crawler cannot reach this site');
        expect(message.querySelector('[role="status"], [role="alert"]')).toBeNull();
        // The scan stays locked: the guidance is no way around the crawler's refusal.
        expect(document.querySelector('[data-aqg-free-preview-submit="true"]').disabled).toBe(true);
        expect(document.querySelector('[data-action="a11y-free-preview-retry"]').hidden).toBe(true);
    });

    it('keeps the plain message for other site address problems', () => {
        mountPreview();

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(module(), {state: 'INVALID_SITE', code: 'invalid_url_port'}, 'Fallback');

        const message = document.querySelector('[data-aqg-free-preview-message="true"]');
        expect(message.querySelector('[data-aqg-free-preview-unreachable]')).toBeNull();
        expect(message.querySelector('[role="status"]').textContent).toBe('The site address is not reachable.');
    });

    it('falls back to the message when the page has no guidance template', () => {
        mountPreview({withTemplate: false});

        A11yProBackendModule.prototype.applyFreePreviewErrorState.call(module(), {state: 'INVALID_SITE', code: 'private_network_blocked'}, 'Fallback');

        expect(document.querySelector('[data-aqg-free-preview-message="true"]').textContent).toContain('The site address is not reachable.');
    });

    it('opens the other Overview tab and moves the focus to its heading', () => {
        mountPreview();
        const context = {
            setOverviewSource: (source) => {
                document.querySelectorAll('[data-a11y-overview-panel]').forEach((panel) => {
                    panel.hidden = panel.dataset.a11yOverviewPanel !== source;
                });
            },
        };

        A11yProBackendModule.prototype.showOverviewSourceAndFocus.call(context, 'remote', '#aqg-free-preview-title');
        expect(document.querySelector('[data-a11y-overview-panel="remote"]').hidden).toBe(false);
        expect(document.activeElement).toBe(document.querySelector('#aqg-free-preview-title'));

        A11yProBackendModule.prototype.showOverviewSourceAndFocus.call(context, 'local', '[data-a11y-overview-panel="local"] .aqg-section__title');
        const localHeading = document.querySelector('[data-a11y-overview-panel="local"] .aqg-section__title');
        expect(document.querySelector('[data-a11y-overview-panel="local"]').hidden).toBe(false);
        expect(document.activeElement).toBe(localHeading);
        expect(localHeading.getAttribute('tabindex')).toBe('-1');
        localHeading.dispatchEvent(new FocusEvent('blur'));
        expect(localHeading.hasAttribute('tabindex')).toBe(false);
    });

    it('wires the guidance buttons to the Overview tabs', () => {
        mountPreview();
        const context = {showOverviewSourceAndFocus: vi.fn()};
        A11yProBackendModule.prototype.bindProEvents.call(context);

        const next = document.createElement('button');
        next.dataset.action = 'a11y-open-free-preview';
        document.body.append(next);
        next.click();
        expect(context.showOverviewSourceAndFocus).toHaveBeenLastCalledWith('remote', '#aqg-free-preview-title');

        const template = document.querySelector('template[data-aqg-free-preview-unreachable-template="true"]');
        document.querySelector('[data-aqg-free-preview-message="true"]').append(template.content.cloneNode(true));
        document.querySelector('[data-action="a11y-show-local-scan"]').click();
        expect(context.showOverviewSourceAndFocus).toHaveBeenLastCalledWith('local', '[data-a11y-overview-panel="local"] .aqg-section__title');
    });
});
