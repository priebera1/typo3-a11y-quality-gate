// @vitest-environment jsdom

import {beforeEach, describe, expect, it, vi} from 'vitest';
import {initializeAiLinkTextSuggestion} from '../../Resources/Public/JavaScript/ai-link-text-suggestion.js';

const createRoot = () => {
    document.body.innerHTML = `
        <div data-aqg-ai-link-text-url="/suggest"
             data-message-invalid-input="Invalid input."
             data-message-link-text-ready="AI suggested a link text. Review it before using it."
             data-message-link-text-copied="Suggested link text copied."
             data-message-link-text-no-suggestion="No safe link text could be suggested."
             data-message-link-text-unsupported="AQG could not safely identify one exact link in this finding."
             data-message-link-text-disabled="AI link-text suggestions are disabled for this site."
             data-message-link-text-not-configured="AI link-text suggestions are not configured for this site."
             data-message-link-text-provider-error="The AI provider could not return a suggestion. Please try again later."
             data-message-ai-unavailable="AI unavailable."
             data-message-rate-limited="Rate limited."
             data-message-invalid-response="Invalid response."
             data-message-internal-error="Internal error."
             data-message-request-failed="Request failed.">
            <section data-aqg-ai-link-text-card="true" data-finding-id="66035">
                <button type="button" data-action="aqg-suggest-link-text" data-loading-label="…">Suggest link text</button>
                <div data-aqg-ai-link-text-review="true" hidden>
                    <label data-aqg-ai-link-text-label="true" for="link-text">Suggested link text</label>
                    <p data-aqg-ai-link-text-no-suggestion="true" hidden><strong>No safe link text could be suggested.</strong></p>
                    <div data-aqg-ai-link-text-field-row="true">
                        <input id="link-text" data-aqg-ai-link-text-suggestion="true" type="text">
                        <button type="button" data-action="aqg-copy-link-text" data-aqg-ai-link-text-copy="true">Copy text</button>
                    </div>
                    <p><strong>Reason:</strong> <span data-aqg-ai-link-text-reason="true"></span></p>
                    <p>Nothing has been changed. Review the suggestion and update the link text manually.</p>
                </div>
                <div data-aqg-ai-link-text-status="true" hidden></div>
            </section>
        </div>
    `;

    const root = document.querySelector('[data-aqg-ai-link-text-url]');
    initializeAiLinkTextSuggestion(root);

    return {
        root,
        card: root.querySelector('[data-aqg-ai-link-text-card="true"]'),
        suggestButton: root.querySelector('[data-action="aqg-suggest-link-text"]'),
        copyButton: root.querySelector('[data-action="aqg-copy-link-text"]'),
        input: root.querySelector('[data-aqg-ai-link-text-suggestion="true"]'),
        review: root.querySelector('[data-aqg-ai-link-text-review="true"]'),
        noSuggestion: root.querySelector('[data-aqg-ai-link-text-no-suggestion="true"]'),
        fieldRow: root.querySelector('[data-aqg-ai-link-text-field-row="true"]'),
        reason: root.querySelector('[data-aqg-ai-link-text-reason="true"]'),
        status: root.querySelector('[data-aqg-ai-link-text-status="true"]'),
    };
};

const mockResponse = (payload, ok = true, status = 200) => {
    globalThis.fetch = vi.fn().mockResolvedValue({
        ok,
        status,
        json: vi.fn().mockResolvedValue(payload),
    });
};

describe('AI link-text suggestion UI', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {writeText: vi.fn().mockResolvedValue(undefined)},
        });
    });

    it('sends only findingId and renders a valid suggestion', async () => {
        mockResponse({
            success: true,
            code: 'suggestion',
            status: 'suggestion',
            suggestedLinkText: 'Contact us',
            reason: 'The href points to the contact page.',
            needsReview: true,
            reviewOnly: true,
        });
        const {suggestButton, copyButton, input, fieldRow, noSuggestion, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(input.value).toBe('Contact us'));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [, options] = globalThis.fetch.mock.calls[0];
        expect(String(options.body)).toBe('findingId=66035');
        expect(noSuggestion.hidden).toBe(true);
        expect(fieldRow.hidden).toBe(false);
        expect(copyButton.hidden).toBe(false);
        expect(copyButton.disabled).toBe(false);
        expect(status.textContent).toContain('AI suggested a link text.');

        copyButton.click();

        await vi.waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('Contact us'));
    });

    it('renders needs_review with an empty title as a safe no-suggestion state', async () => {
        const reason = 'The link target cannot be inferred safely from the available context.';
        mockResponse({
            success: true,
            code: 'needs_review',
            status: 'needs_review',
            suggestedLinkText: '',
            reason,
            needsReview: true,
            reviewOnly: true,
        });
        const {suggestButton, copyButton, input, review, noSuggestion, fieldRow, reason: reasonNode, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(noSuggestion.hidden).toBe(false));
        expect(noSuggestion.textContent).toContain('No safe link text could be suggested.');
        expect(status.hidden).toBe(true);
        expect(status.textContent).not.toContain('Request failed.');
        expect(review.hidden).toBe(false);
        expect(fieldRow.hidden).toBe(true);
        expect(copyButton.hidden).toBe(true);
        expect(copyButton.disabled).toBe(true);
        expect(input.value).toBe('');
        expect(reasonNode.textContent).toBe(reason);
    });

    it('shows a safe provider error for unsafe HTML suggestions', async () => {
        mockResponse({
            success: false,
            code: 'provider_error',
            message: '<strong>Contact us</strong>',
        }, false, 502);
        const {suggestButton, review, status} = createRoot();

        suggestButton.click();

        await vi.waitFor(() => expect(status.textContent).toContain('The AI provider could not return a suggestion.'));
        expect(status.textContent).not.toContain('<strong>');
        expect(status.textContent).not.toContain('Request failed.');
        expect(review.hidden).toBe(true);
    });
});
