// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {initializeStatementSettings} from '../../Resources/Public/JavaScript/backend/settings-statement.js';

const UNAVAILABLE = 'Accessibility statement is not available right now.';
const PDF_UNAVAILABLE = 'Statement PDF is not available right now.';
const GENERATING = 'Generating statement…';
const JOB_ID = '11111111-1111-4111-8111-111111111111';

const createRoot = () => {
    document.body.innerHTML = `
        <div data-aqg-statement="true"
             data-generate-url="/statement/generate"
             data-pdf-url="/statement/pdf"
             data-i18n-page-url="Enter a page URL."
             data-i18n-invalid-page-url="Enter a valid http or https page URL."
             data-i18n-job-id="Enter a job ID."
             data-i18n-confirm-status="Confirm the status manually."
             data-i18n-custom-enforcement="Enter custom enforcement text."
             data-i18n-invalid-email="Enter a valid contact email address."
             data-i18n-invalid-url="Enter a valid evaluation report URL."
             data-i18n-custom-standard="Enter a custom standard."
             data-i18n-generating="${GENERATING}"
             data-i18n-generated="Statement generated."
             data-i18n-unavailable="${UNAVAILABLE}"
             data-i18n-button-generate="Generate statement"
             data-i18n-button-generating="Generating…"
             data-i18n-button-download-pdf="Download PDF"
             data-i18n-preparing-pdf="Preparing PDF…"
             data-i18n-pdf-unavailable="${PDF_UNAVAILABLE}"
             data-i18n-pdf-downloaded="Statement PDF downloaded."
             data-i18n-source-fallback="Automated draft generated from frontend scan data."
             data-i18n-source-site="Site"
             data-i18n-source-scope="Scan scope"
             data-i18n-source-scanned="Scan date"
             data-i18n-source-type-sitemap="Site scan"
             data-i18n-source-type-crawl="Site crawl"
             data-i18n-source-type-single-page="Single page scan"
             data-i18n-status-review="Draft - requires review">
            <select class="js-aqg-statement-site"><option value="main" selected>main</option></select>
            <input type="radio" name="statementScope" value="latest_site" class="js-aqg-statement-scope" checked>
            <input type="radio" name="statementScope" value="latest_page" class="js-aqg-statement-scope">
            <input type="radio" name="statementScope" value="specific_job" class="js-aqg-statement-scope">
            <div class="js-aqg-statement-page-panel" hidden><input type="url" class="js-aqg-statement-page-url"></div>
            <div class="js-aqg-statement-job-panel" hidden><input type="text" class="js-aqg-statement-job-id"></div>
            <input type="radio" name="statementLanguage" value="en" class="js-aqg-statement-language" checked>
            <input type="text" class="js-aqg-statement-organisation">
            <input type="email" class="js-aqg-statement-contact-email">
            <input type="url" class="js-aqg-statement-evaluation-url">
            <select class="js-aqg-statement-conformity-status">
                <option value="not_confirmed" selected>Not confirmed</option>
                <option value="mostly_compliant">Mostly conformant</option>
            </select>
            <input type="checkbox" class="js-aqg-statement-status-confirmed">
            <select class="js-aqg-statement-standard"><option value="wcag22aa" selected>WCAG 2.2 AA</option></select>
            <select class="js-aqg-statement-enforcement"><option value="generic" selected>Generic</option></select>
            <button type="button" class="js-aqg-statement-generate">Generate statement</button>
            <span class="aqg-inline-status js-aqg-statement-status" hidden></span>
            <section class="js-aqg-statement-empty"></section>
            <section class="js-aqg-statement-result" hidden>
                <p class="js-aqg-statement-source"></p>
                <span class="js-aqg-statement-status-badge"></span>
                <button type="button" class="js-aqg-statement-copy" disabled>Copy as HTML</button>
                <button type="button" class="js-aqg-statement-download" disabled>Download .txt</button>
                <button type="button" class="js-aqg-statement-pdf" disabled>Download PDF</button>
                <div class="js-aqg-statement-preview"></div>
            </section>
        </div>
    `;

    const root = document.querySelector('[data-aqg-statement="true"]');
    initializeStatementSettings(root);
    return root;
};

const el = (root, selector) => root.querySelector(selector);

const jsonResponse = (status, body, headers = {}) => new Response(JSON.stringify(body), {
    status,
    headers: {'Content-Type': 'application/json', ...headers},
});

const statement = (overrides = {}) => ({
    available: true,
    html: '<article class="aqg-accessibility-statement"><h1>Draft Accessibility Statement</h1></article>',
    text: 'Draft Accessibility Statement\n',
    source: {jobId: JOB_ID, siteId: 'main', sourceType: 'crawl', scannedAtFormatted: '15.09.2026 10:00'},
    status: {statementStatus: 'draft_requires_review', statementStatusLabel: 'Draft - requires review', statementStatusTone: 'warning'},
    ...overrides,
});

const generate = async (root) => {
    el(root, '.js-aqg-statement-generate').click();
    await vi.waitFor(() => {
        expect(el(root, '.js-aqg-statement-generate').disabled).toBe(false);
        expect(el(root, '.js-aqg-statement-status').textContent).not.toBe(GENERATING);
    });
};

const expectNoStatement = (root) => {
    expect(el(root, '.js-aqg-statement-result').hidden).toBe(true);
    expect(el(root, '.js-aqg-statement-preview').innerHTML).toBe('');
    expect(el(root, '.js-aqg-statement-copy').disabled).toBe(true);
    expect(el(root, '.js-aqg-statement-download').disabled).toBe(true);
    expect(el(root, '.js-aqg-statement-pdf').disabled).toBe(true);
};

const expectError = (root, message) => {
    const status = el(root, '.js-aqg-statement-status');
    expect(status.textContent).toBe(message);
    expect(status.classList.contains('aqg-inline-status--error')).toBe(true);
};

describe('Statement Assistant generate', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('renders a generated statement with a localized source line', async () => {
        const root = createRoot();
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {success: true, statement: statement()}));

        await generate(root);

        expect(el(root, '.js-aqg-statement-preview .aqg-accessibility-statement')).not.toBeNull();
        expect(el(root, '.js-aqg-statement-result').hidden).toBe(false);
        expect(el(root, '.js-aqg-statement-status').textContent).toBe('Statement generated.');
        expect(el(root, '.js-aqg-statement-source').textContent)
            .toBe('Site: main · Scan scope: Site crawl · Scan date: 15.09.2026 10:00');
        expect(el(root, '.js-aqg-statement-pdf').disabled).toBe(false);
        expect(JSON.parse(fetchMock.mock.calls[0][1].body).scope).toBe('latest_site');
    });

    it('keeps the statement unavailable when HTTP 200 carries success:false', async () => {
        const root = createRoot();
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {success: false, message: 'The selected scan contains no successfully checked pages.'}));

        await generate(root);

        expectError(root, 'The selected scan contains no successfully checked pages.');
        expectNoStatement(root);
    });

    it('shows the rate-limit message and generates on the next attempt', async () => {
        const root = createRoot();
        const limited = 'The AQG service is limiting requests right now. Try again in about 30 seconds.';
        fetchMock
            .mockResolvedValueOnce(jsonResponse(429, {success: false, code: 'rate_limited', message: limited, retryAfter: 30}, {'Retry-After': '30'}))
            .mockResolvedValueOnce(jsonResponse(200, {success: true, statement: statement()}));

        await generate(root);
        expectError(root, limited);
        expectNoStatement(root);

        await generate(root);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(el(root, '.js-aqg-statement-preview .aqg-accessibility-statement')).not.toBeNull();
        expect(el(root, '.js-aqg-statement-status').textContent).toBe('Statement generated.');
    });

    it.each([
        ['an HTML gateway error', () => new Response('<html><body>502 Bad Gateway</body></html>', {status: 502, headers: {'Content-Type': 'text/html'}})],
        ['a malformed JSON body', () => new Response('{"success": tru', {status: 200, headers: {'Content-Type': 'application/json'}})],
        ['a server error without a message', () => jsonResponse(503, {success: false})],
        ['a success envelope without statement HTML', () => jsonResponse(200, {success: true, statement: {available: true, html: '   '}})],
        ['a success envelope with an unavailable statement', () => jsonResponse(200, {success: true, statement: statement({available: false})})],
    ])('falls back to the translated message for %s', async (label, response) => {
        const root = createRoot();
        fetchMock.mockResolvedValueOnce(response());

        await generate(root);

        expectError(root, UNAVAILABLE);
        expectNoStatement(root);
    });

    it('never shows the browser network error text', async () => {
        const root = createRoot();
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        await generate(root);

        expectError(root, UNAVAILABLE);
        expect(el(root, '.js-aqg-statement-status').textContent).not.toContain('Failed to fetch');
        expectNoStatement(root);
    });

    it('aborts a request that never answers and re-enables Generate', async () => {
        vi.useFakeTimers();
        const root = createRoot();
        fetchMock.mockImplementationOnce((url, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
        }));

        el(root, '.js-aqg-statement-generate').click();
        expect(el(root, '.js-aqg-statement-generate').disabled).toBe(true);
        await vi.advanceTimersByTimeAsync(60000);

        expect(el(root, '.js-aqg-statement-generate').disabled).toBe(false);
        expectError(root, UNAVAILABLE);
        expectNoStatement(root);
    });

    it('sends one request while a generation is pending', async () => {
        const root = createRoot();
        let answer;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => {
            answer = resolve;
        }));

        el(root, '.js-aqg-statement-generate').click();
        el(root, '.js-aqg-statement-generate').click();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        answer(jsonResponse(200, {success: true, statement: statement()}));
        await vi.waitFor(() => expect(el(root, '.js-aqg-statement-status').textContent).toBe('Statement generated.'));
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('clears an earlier statement when the changed form fails validation', async () => {
        const root = createRoot();
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {success: true, statement: statement()}));
        await generate(root);
        expect(el(root, '.js-aqg-statement-preview .aqg-accessibility-statement')).not.toBeNull();

        el(root, '.js-aqg-statement-contact-email').value = 'not-an-email';
        await generate(root);

        expectError(root, 'Enter a valid contact email address.');
        expectNoStatement(root);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each([
        ['javascript:alert(1)'],
        ['https://'],
        ['https://example.org/a page'],
    ])('rejects the page URL %s before any request', async (url) => {
        const root = createRoot();
        el(root, '.js-aqg-statement-scope[value="latest_page"]').checked = true;
        el(root, '.js-aqg-statement-page-url').value = url;

        await generate(root);

        expectError(root, 'Enter a valid http or https page URL.');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('rejects an evaluation report URL with a script scheme before any request', async () => {
        const root = createRoot();
        el(root, '.js-aqg-statement-evaluation-url').value = 'javascript:alert(2)';

        await generate(root);

        expectError(root, 'Enter a valid evaluation report URL.');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('opens the collapsed optional details and focuses the field that failed validation', async () => {
        const root = createRoot();
        const url = el(root, '.js-aqg-statement-evaluation-url');
        const optional = document.createElement('details');
        optional.className = 'aqg-statement-optional js-aqg-statement-optional';
        optional.append(document.createElement('summary'));
        url.replaceWith(optional);
        optional.append(url);
        url.value = 'javascript:alert(2)';

        await generate(root);

        expectError(root, 'Enter a valid evaluation report URL.');
        expect(optional.open).toBe(true);
        expect(document.activeElement).toBe(url);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('requires the manual confirmation for a non-default conformance status', async () => {
        const root = createRoot();
        el(root, '.js-aqg-statement-conformity-status').value = 'mostly_compliant';

        await generate(root);

        expectError(root, 'Confirm the status manually.');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('Statement Assistant PDF', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        URL.createObjectURL = vi.fn(() => 'blob:statement');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const clickPdf = async (root) => {
        el(root, '.js-aqg-statement-pdf').click();
        await vi.waitFor(() => expect(el(root, '.js-aqg-statement-pdf').textContent).toBe('Download PDF'));
    };

    it('requests the PDF for the previewed job, not for whatever scan is latest', async () => {
        const root = createRoot();
        fetchMock
            .mockResolvedValueOnce(jsonResponse(200, {success: true, statement: statement()}))
            .mockResolvedValueOnce(new Response('%PDF-1.7', {status: 200, headers: {'Content-Type': 'application/pdf'}}));
        await generate(root);

        await clickPdf(root);

        const pdfPayload = JSON.parse(fetchMock.mock.calls[1][1].body);
        expect(fetchMock.mock.calls[1][0]).toBe('/statement/pdf');
        expect(pdfPayload.scope).toBe('specific_job');
        expect(pdfPayload.jobId).toBe(JOB_ID);
        expect(pdfPayload).not.toHaveProperty('startUrl');
        expect(el(root, '.js-aqg-statement-status').textContent).toBe('Statement PDF downloaded.');
    });

    it('shows the bounded message when the PDF request fails', async () => {
        const root = createRoot();
        fetchMock
            .mockResolvedValueOnce(jsonResponse(200, {success: true, statement: statement()}))
            .mockRejectedValueOnce(new TypeError('NetworkError when attempting to fetch resource.'));
        await generate(root);

        await clickPdf(root);

        expectError(root, PDF_UNAVAILABLE);
        expect(el(root, '.js-aqg-statement-pdf').disabled).toBe(false);
    });
});
