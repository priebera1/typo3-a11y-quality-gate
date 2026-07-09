// @vitest-environment jsdom

import {beforeEach, describe, expect, it, vi} from 'vitest';
import {initializeAiIframeTitleSuggestion} from '../../Resources/Public/JavaScript/ai-iframe-title-suggestion.js';

const createRoot = () => {
    document.body.innerHTML = `
        <div data-aqg-ai-iframe-title-url="/suggest"
             data-message-invalid-input="Invalid input."
             data-message-iframe-title-ready="AI suggested an iframe title. Review it before using it."
             data-message-iframe-title-copied="Suggested iframe title copied."
             data-message-iframe-title-no-suggestion="No safe iframe title could be suggested."
             data-message-iframe-title-unsupported="AQG could not safely identify one exact iframe source in this rendered finding."
             data-message-iframe-title-disabled="AI iframe-title suggestions are disabled for this site."
             data-message-iframe-title-not-configured="AI iframe-title suggestions are not configured for this site."
             data-message-iframe-title-provider-error="The AI provider could not return an iframe title. Please try again later."
             data-message-ai-unavailable="AI unavailable."
             data-message-rate-limited="Rate limited."
             data-message-invalid-response="Invalid response."
             data-message-internal-error="Internal error."
             data-message-request-failed="Request failed.">
            <section data-aqg-ai-iframe-title-card="true" data-finding-id="937">
                <button type="button" data-action="aqg-suggest-iframe-title" data-loading-label="…">Suggest iframe title</button>
                <div data-aqg-ai-iframe-title-review="true" hidden>
                    <label data-aqg-ai-iframe-title-label="true" for="iframe-title">Suggested iframe title</label>
                    <p data-aqg-ai-iframe-title-no-suggestion="true" hidden><strong>No safe iframe title could be suggested.</strong></p>
                    <div data-aqg-ai-iframe-title-field-row="true">
                        <input id="iframe-title" data-aqg-ai-iframe-title-suggestion="true" type="text">
                        <button type="button" data-action="aqg-copy-iframe-title" data-aqg-ai-iframe-title-copy="true">Copy text</button>
                    </div>
                    <p><strong>Reason:</strong> <span data-aqg-ai-iframe-title-reason="true"></span></p>
                    <p>Nothing has been changed. Add a short iframe title manually when you know what the embedded content is.</p>
                </div>
                <div data-aqg-ai-iframe-title-status="true" hidden></div>
            </section>
        </div>
    `;

    const root = document.querySelector('[data-aqg-ai-iframe-title-url]');
    initializeAiIframeTitleSuggestion(root);

    return {
        root,
        card: root.querySelector('[data-aqg-ai-iframe-title-card="true"]'),
        suggestButton: root.querySelector('[data-action="aqg-suggest-iframe-title"]'),
        copyButton: root.querySelector('[data-action="aqg-copy-iframe-title"]'),
        input: root.querySelector('[data-aqg-ai-iframe-title-suggestion="true"]'),
        review: root.querySelector('[data-aqg-ai-iframe-title-review="true"]'),
        noSuggestion: root.querySelector('[data-aqg-ai-iframe-title-no-suggestion="true"]'),
        fieldRow: root.querySelector('[data-aqg-ai-iframe-title-field-row="true"]'),
        reason: root.querySelector('[data-aqg-ai-iframe-title-reason="true"]'),
        status: root.querySelector('[data-aqg-ai-iframe-title-status="true"]'),
    };
};

const mockResponse = (payload, ok = true, status = 200) => {
    globalThis.fetch = vi.fn().mockResolvedValue({
        ok,
        status,
        json: vi.fn().mockResolvedValue(payload),
    });
};

describe('AI iframe-title suggestion UI', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {writeText: vi.fn().mockResolvedValue(undefined)},
        });
    });

    it('renders needs_review with an empty title as a safe no-suggestion state', async () => {
        const reason = 'The iframe source is a generic placeholder URL and no safe description of the embedded content can be inferred from the provided context.';
        mockResponse({
            success: true,
            code: 'needs_review',
            status: 'needs_review',
            suggestedIframeTitle: '',
            reason,
            needsReview: true,
            reviewOnly: true,
        });
        const {suggestButton, copyButton, input, review, noSuggestion, fieldRow, reason: reasonNode, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(globalThis.fetch).toHaveBeenCalledTimes(1));
        await vi.waitFor(() => expect(noSuggestion.hidden).toBe(false));

        expect(noSuggestion.textContent).toContain('No safe iframe title could be suggested.');
        expect(status.hidden).toBe(true);
        expect(status.textContent).toBe('');
        expect(status.textContent).not.toContain('Request failed.');
        expect(review.hidden).toBe(false);
        expect(noSuggestion.hidden).toBe(false);
        expect(fieldRow.hidden).toBe(true);
        expect(copyButton.hidden).toBe(true);
        expect(copyButton.disabled).toBe(true);
        expect(input.value).toBe('');
        expect(reasonNode.textContent).toBe(reason);
    });


    it('renders unsupported_context with a provider reason as a safe no-suggestion state', async () => {
        const reason = 'The iframe source is a generic homepage URL and the available context does not safely identify the embedded content or purpose.';
        mockResponse({
            success: true,
            code: 'unsupported_context',
            status: 'unsupported_context',
            suggestedIframeTitle: '',
            reason,
            needsReview: true,
            reviewOnly: true,
        });
        const {suggestButton, copyButton, input, review, noSuggestion, fieldRow, reason: reasonNode, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(noSuggestion.hidden).toBe(false));

        expect(noSuggestion.textContent).toContain('No safe iframe title could be suggested.');
        expect(status.hidden).toBe(true);
        expect(status.textContent).toBe('');
        expect(status.textContent).not.toContain('Request failed.');
        expect(status.textContent).not.toContain('AQG could not safely identify one exact iframe source');
        expect(review.hidden).toBe(false);
        expect(noSuggestion.hidden).toBe(false);
        expect(fieldRow.hidden).toBe(true);
        expect(copyButton.hidden).toBe(true);
        expect(copyButton.disabled).toBe(true);
        expect(input.value).toBe('');
        expect(reasonNode.textContent).toBe(reason);
    });

    it('keeps valid suggestions copyable', async () => {
        mockResponse({
            success: true,
            code: 'suggestion',
            status: 'suggestion',
            suggestedIframeTitle: 'Product introduction video',
            reason: 'The iframe source indicates an embedded product video.',
            needsReview: true,
            reviewOnly: true,
        });
        const {suggestButton, copyButton, input, noSuggestion, fieldRow, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(input.value).toBe('Product introduction video'));
        expect(noSuggestion.hidden).toBe(true);
        expect(fieldRow.hidden).toBe(false);
        expect(copyButton.hidden).toBe(false);
        expect(copyButton.disabled).toBe(false);
        expect(status.textContent).toContain('AI suggested an iframe title.');

        copyButton.click();

        await vi.waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('Product introduction video'));
    });

    it('shows a safe provider error for unsafe HTML suggestions', async () => {
        mockResponse({
            success: false,
            code: 'provider_error',
            message: '<strong>Map</strong>',
        }, false, 502);
        const {suggestButton, review, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(status.textContent).toContain('The AI provider could not return an iframe title.'));
        expect(status.textContent).not.toContain('<strong>');
        expect(status.textContent).not.toContain('Request failed.');
        expect(review.hidden).toBe(true);
    });
});
