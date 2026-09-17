const REQUEST_TIMEOUT_MS = 60000;

/**
 * An error whose message may be shown as is: it is the server's bounded message or one of this
 * module's translated messages, never text from the browser's network stack.
 */
class StatementRequestError extends Error {}

class AqgStatementSettings {
  constructor(root) {
    this.root = root;
    this.generateUrl = root.dataset.generateUrl || '';
    this.pdfUrl = root.dataset.pdfUrl || '';
    this.site = root.querySelector('.js-aqg-statement-site');
    this.scopes = Array.from(root.querySelectorAll('.js-aqg-statement-scope'));
    this.languages = Array.from(root.querySelectorAll('.js-aqg-statement-language'));
    this.pagePanel = root.querySelector('.js-aqg-statement-page-panel');
    this.jobPanel = root.querySelector('.js-aqg-statement-job-panel');
    this.pageUrl = root.querySelector('.js-aqg-statement-page-url');
    this.jobId = root.querySelector('.js-aqg-statement-job-id');
    this.websiteName = root.querySelector('.js-aqg-statement-website-name');
    this.commitment = root.querySelector('.js-aqg-statement-commitment');
    this.createdDate = root.querySelector('.js-aqg-statement-created-date');
    this.standard = root.querySelector('.js-aqg-statement-standard');
    this.standardCustomPanel = root.querySelector('.js-aqg-statement-standard-custom-panel');
    this.standardCustom = root.querySelector('.js-aqg-statement-standard-custom');
    this.organisationWarning = root.querySelector('.js-aqg-statement-organisation-warning');
    this.measures = Array.from(root.querySelectorAll('.js-aqg-statement-measure'));
    this.customMeasure = root.querySelector('.js-aqg-statement-custom-measure');
    this.remediation = root.querySelector('.js-aqg-statement-remediation');
    this.compatible = root.querySelector('.js-aqg-statement-compatible');
    this.incompatible = root.querySelector('.js-aqg-statement-incompatible');
    this.technologies = Array.from(root.querySelectorAll('.js-aqg-statement-technology'));
    this.assessments = Array.from(root.querySelectorAll('.js-aqg-statement-assessment'));
    this.manualReview = root.querySelector('.js-aqg-statement-manual-review');
    this.evaluationUrl = root.querySelector('.js-aqg-statement-evaluation-url');
    this.approvalOrganisation = root.querySelector('.js-aqg-statement-approval-organisation');
    this.approvalPerson = root.querySelector('.js-aqg-statement-approval-person');
    this.approvalRole = root.querySelector('.js-aqg-statement-approval-role');
    this.approvalDate = root.querySelector('.js-aqg-statement-approval-date');
    this.conformityStatus = root.querySelector('.js-aqg-statement-conformity-status');
    this.statusConfirmed = root.querySelector('.js-aqg-statement-status-confirmed');
    this.organisation = root.querySelector('.js-aqg-statement-organisation');
    this.contactEmail = root.querySelector('.js-aqg-statement-contact-email');
    this.phone = root.querySelector('.js-aqg-statement-phone');
    this.address = root.querySelector('.js-aqg-statement-address');
    this.responseTime = root.querySelector('.js-aqg-statement-response-time');
    this.responseNote = root.querySelector('.js-aqg-statement-response-note');
    this.contactWarning = root.querySelector('.js-aqg-statement-contact-warning');
    this.enforcement = root.querySelector('.js-aqg-statement-enforcement');
    this.enforcementCustomPanel = root.querySelector('.js-aqg-statement-enforcement-custom-panel');
    this.enforcementCustom = root.querySelector('.js-aqg-statement-enforcement-custom');
    this.generateButton = root.querySelector('.js-aqg-statement-generate');
    this.status = root.querySelector('.js-aqg-statement-status');
    this.empty = root.querySelector('.js-aqg-statement-empty');
    this.result = root.querySelector('.js-aqg-statement-result');
    this.source = root.querySelector('.js-aqg-statement-source');
    this.preview = root.querySelector('.js-aqg-statement-preview');
    this.statusBadge = root.querySelector('.js-aqg-statement-status-badge');
    this.copyButton = root.querySelector('.js-aqg-statement-copy');
    this.downloadButton = root.querySelector('.js-aqg-statement-download');
    this.pdfButton = root.querySelector('.js-aqg-statement-pdf');
    this.currentHtml = '';
    this.currentText = '';
    this.lastPayload = null;
    this.generating = false;
    this.preparingPdf = false;
    this.messages = this.root?.dataset || {};

    this.resetResultState();
    this.bindEvents();
    this.updateScopeUi();
    this.updateEnforcementUi();
    this.updateStandardUi();
    this.updateContactWarning();
  }

  bindEvents() {
    this.scopes.forEach((input) => input.addEventListener('change', () => this.updateScopeUi()));
    this.enforcement?.addEventListener('change', () => this.updateEnforcementUi());
    this.standard?.addEventListener('change', () => this.updateStandardUi());
    [this.organisation, this.contactEmail].forEach((field) => {
      field?.addEventListener('input', () => this.updateContactWarning());
    });
    this.generateButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.generate();
    });
    this.copyButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.copyHtml();
    });
    this.downloadButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.downloadTxt();
    });
    this.pdfButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.downloadPdf();
    });
  }

  message(name) {
    return this.messages[`i18n${name}`] || '';
  }

  resetResultState() {
    this.currentHtml = '';
    this.currentText = '';
    this.lastPayload = null;
    if (this.status) {
      this.status.textContent = '';
      this.status.hidden = true;
      this.status.className = 'aqg-inline-status js-aqg-statement-status';
    }
    if (this.result) {
      this.result.hidden = true;
    }
    if (this.empty) {
      this.empty.hidden = false;
    }
    if (this.preview) this.preview.innerHTML = '';
    if (this.copyButton) this.copyButton.disabled = true;
    if (this.downloadButton) this.downloadButton.disabled = true;
    if (this.pdfButton) this.pdfButton.disabled = true;
  }

  getSelectedScope() {
    const selected = this.scopes.find((input) => input instanceof HTMLInputElement && input.checked);
    return selected instanceof HTMLInputElement ? selected.value : 'latest_site';
  }

  getSelectedLanguage() {
    const selected = this.languages.find((input) => input instanceof HTMLInputElement && input.checked);
    return selected instanceof HTMLInputElement ? selected.value : 'en';
  }

  updateScopeUi() {
    const scope = this.getSelectedScope();
    if (this.pagePanel) {
      this.pagePanel.hidden = scope !== 'latest_page';
    }
    if (this.jobPanel) {
      this.jobPanel.hidden = scope !== 'specific_job';
    }
  }

  updateEnforcementUi() {
    if (this.enforcementCustomPanel) {
      this.enforcementCustomPanel.hidden = !(this.enforcement instanceof HTMLSelectElement && this.enforcement.value === 'custom');
    }
  }

  updateStandardUi() {
    if (this.standardCustomPanel) {
      this.standardCustomPanel.hidden = !(this.standard instanceof HTMLSelectElement && this.standard.value === 'custom');
    }
  }

  updateContactWarning() {
    if (!this.contactWarning) {
      return;
    }
    const missing = !this.getInputValue(this.organisation) || !this.getInputValue(this.contactEmail);
    this.contactWarning.hidden = !missing;
    if (this.organisationWarning) {
      this.organisationWarning.hidden = !!this.getInputValue(this.organisation);
    }
  }

  buildPayload() {
    const scope = this.getSelectedScope();
    const payload = {
      siteId: this.site instanceof HTMLSelectElement ? this.site.value : '',
      scope,
      language: this.getSelectedLanguage(),
      draftOptions: {
        websiteName: this.getInputValue(this.websiteName),
        organisation: this.getInputValue(this.organisation),
        commitmentText: this.getInputValue(this.commitment),
        statementCreatedDate: this.getInputValue(this.createdDate),
        accessibilityStandard: this.standard instanceof HTMLSelectElement ? this.standard.value : 'wcag22aa',
        customAccessibilityStandard: this.getInputValue(this.standardCustom),
        conformityStatus: this.conformityStatus instanceof HTMLSelectElement ? this.conformityStatus.value : 'not_confirmed',
        statusConfirmed: this.statusConfirmed instanceof HTMLInputElement ? this.statusConfirmed.checked : false,
        measures: this.getCheckedValues(this.measures),
        customMeasure: this.getInputValue(this.customMeasure),
        remediationNote: this.getInputValue(this.remediation),
        contactEmail: this.getInputValue(this.contactEmail),
        phone: this.getInputValue(this.phone),
        postalAddress: this.getInputValue(this.address),
        responseTime: this.getInputValue(this.responseTime),
        responseNote: this.getInputValue(this.responseNote),
        compatibleEnvironments: this.getInputValue(this.compatible),
        incompatibleEnvironments: this.getInputValue(this.incompatible),
        technologies: this.getCheckedValues(this.technologies),
        assessmentApproach: this.getCheckedValues(this.assessments),
        manualReviewPerformed: this.manualReview instanceof HTMLInputElement ? this.manualReview.checked : false,
        evaluationReportUrl: this.getInputValue(this.evaluationUrl),
        approvalOrganisation: this.getInputValue(this.approvalOrganisation),
        approvalPerson: this.getInputValue(this.approvalPerson),
        approvalRole: this.getInputValue(this.approvalRole),
        approvalDate: this.getInputValue(this.approvalDate),
        enforcementProcedure: this.enforcement instanceof HTMLSelectElement ? this.enforcement.value : 'generic',
        customEnforcementText: this.getInputValue(this.enforcementCustom),
      },
    };

    if (scope === 'latest_page') {
      payload.startUrl = this.pageUrl instanceof HTMLInputElement ? this.pageUrl.value.trim() : '';
    }

    if (scope === 'specific_job') {
      payload.jobId = this.jobId instanceof HTMLInputElement ? this.jobId.value.trim() : '';
    }

    return payload;
  }

  validatePayload(payload) {
    // Remembers the field to reveal: optional fields live in a closed disclosure.
    this.invalidField = null;
    const fail = (message, field) => {
      this.invalidField = field || null;
      return message;
    };

    if (payload.scope === 'latest_page') {
      if (!payload.startUrl) {
        return fail(this.message('PageUrl'), this.pageUrl);
      }
      if (!this.isHttpUrl(payload.startUrl)) {
        return fail(this.message('InvalidPageUrl'), this.pageUrl);
      }
    }
    if (payload.scope === 'specific_job' && !payload.jobId) {
      return fail(this.message('JobId'), this.jobId);
    }
    if (payload.draftOptions.conformityStatus && payload.draftOptions.conformityStatus !== 'not_confirmed' && !payload.draftOptions.statusConfirmed) {
      return fail(this.message('ConfirmStatus'), this.statusConfirmed);
    }
    if (payload.draftOptions.enforcementProcedure === 'custom' && !payload.draftOptions.customEnforcementText) {
      return fail(this.message('CustomEnforcement'), this.enforcementCustom);
    }
    if (payload.draftOptions.contactEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.draftOptions.contactEmail)) {
      return fail(this.message('InvalidEmail'), this.contactEmail);
    }
    if (payload.draftOptions.evaluationReportUrl && !this.isHttpUrl(payload.draftOptions.evaluationReportUrl)) {
      return fail(this.message('InvalidUrl'), this.evaluationUrl);
    }
    if (payload.draftOptions.accessibilityStandard === 'custom' && !payload.draftOptions.customAccessibilityStandard) {
      return fail(this.message('CustomStandard'), this.standardCustom);
    }
    return '';
  }

  revealField(field) {
    if (!(field instanceof HTMLElement)) {
      return;
    }
    const disclosure = field.closest('details');
    if (disclosure instanceof HTMLDetailsElement && !disclosure.open) {
      disclosure.open = true;
    }
    field.focus();
  }

  // Same rule as the server: an absolute http(s) URL with a host and without whitespace.
  isHttpUrl(value) {
    if (typeof value !== 'string' || value === '' || /\s/.test(value)) {
      return false;
    }
    try {
      const url = new URL(value);
      return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
    } catch (error) {
      return false;
    }
  }

  async generate() {
    if (!this.generateUrl || !this.generateButton || this.generating) {
      return;
    }

    const payload = this.buildPayload();
    // Every attempt starts without a statement: once the form has changed, an earlier result must
    // no longer be exportable as if it described the current input.
    this.resetGeneratedResultOnly();
    const validationError = this.validatePayload(payload);
    if (validationError) {
      this.setStatus(validationError, 'error');
      this.revealField(this.invalidField);
      return;
    }

    this.generating = true;
    this.setStatus(this.message('Generating'), 'running');
    this.generateButton.disabled = true;
    this.generateButton.textContent = this.message('ButtonGenerating');

    try {
      const data = await this.postJson(this.generateUrl, payload);
      const statement = data.statement && typeof data.statement === 'object' ? data.statement : null;
      // A success envelope without a renderable statement would show an empty draft as generated.
      if (!statement || statement.available === false || typeof statement.html !== 'string' || statement.html.trim() === '') {
        throw new StatementRequestError(this.message('Unavailable'));
      }
      this.renderStatement(statement, payload);
      this.setStatus(this.message('Generated'), 'ok');
    } catch (error) {
      this.resetGeneratedResultOnly();
      this.setStatus(this.errorMessage(error, 'Unavailable'), 'error');
    } finally {
      this.generating = false;
      this.generateButton.disabled = false;
      this.generateButton.textContent = this.message('ButtonGenerate');
    }
  }

  async postJson(url, payload) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS) : 0;
    try {
      let response;
      try {
        response = await fetch(url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify(payload),
          signal: controller ? controller.signal : undefined,
        });
      } catch (error) {
        throw new StatementRequestError(this.message('Unavailable'));
      }
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.success !== true) {
        throw new StatementRequestError(this.serverMessage(data) || this.message('Unavailable'));
      }
      return data;
    } finally {
      if (controller) {
        window.clearTimeout(timer);
      }
    }
  }

  serverMessage(data) {
    return data && typeof data.message === 'string' ? data.message.trim() : '';
  }

  errorMessage(error, fallbackName) {
    return error instanceof StatementRequestError && error.message ? error.message : this.message(fallbackName);
  }

  resetGeneratedResultOnly() {
    this.currentHtml = '';
    this.currentText = '';
    this.lastPayload = null;
    if (this.result) this.result.hidden = true;
    if (this.empty) this.empty.hidden = false;
    if (this.preview) this.preview.innerHTML = '';
    if (this.copyButton) this.copyButton.disabled = true;
    if (this.downloadButton) this.downloadButton.disabled = true;
    if (this.pdfButton) this.pdfButton.disabled = true;
  }

  renderStatement(statement, payload) {
    const source = statement.source && typeof statement.source === 'object' ? statement.source : {};
    const status = statement.status && typeof statement.status === 'object' ? statement.status : {};

    this.currentHtml = statement.html;
    this.currentText = typeof statement.text === 'string' && statement.text !== '' ? statement.text : this.buildPlainTextFallback(statement);
    // The PDF must describe the previewed scan, not whichever scan is the latest when it is requested.
    this.lastPayload = this.buildPdfPayload(payload, source);

    if (this.source) {
      const sourceBits = [];
      if (source.siteId) sourceBits.push(`${this.message('SourceSite')}: ${source.siteId}`);
      const scopeLabel = this.mapSourceTypeLabel(source.sourceType);
      if (scopeLabel) sourceBits.push(`${this.message('SourceScope')}: ${scopeLabel}`);
      if (source.scannedAtFormatted) sourceBits.push(`${this.message('SourceScanned')}: ${source.scannedAtFormatted}`);
      this.source.textContent = sourceBits.length > 0 ? sourceBits.join(' · ') : this.message('SourceFallback');
    }

    if (this.statusBadge) {
      const label = status.statementStatusLabel || this.mapStatusLabel(status.statementStatus || 'draft_requires_review');
      const tone = status.statementStatusTone || 'warning';
      this.statusBadge.textContent = label;
      this.statusBadge.className = 'aqg-status-badge js-aqg-statement-status-badge';
      this.statusBadge.classList.add(`aqg-status-badge--${tone === 'neutral' ? 'neutral' : (tone === 'muted' ? 'muted' : 'warning')}`);
    }

    if (this.preview) {
      this.preview.innerHTML = this.currentHtml;
    }

    if (this.copyButton) {
      this.copyButton.disabled = this.currentHtml === '';
    }
    if (this.downloadButton) {
      this.downloadButton.disabled = this.currentText === '';
    }
    if (this.pdfButton) {
      this.pdfButton.disabled = !this.lastPayload || this.currentHtml === '';
    }
    if (this.empty) {
      this.empty.hidden = true;
    }
    this.result?.removeAttribute('hidden');
  }

  buildPdfPayload(payload, source) {
    const jobId = typeof source.jobId === 'string' ? source.jobId.trim() : '';
    if (jobId === '') {
      return payload;
    }
    const pinned = { ...payload, scope: 'specific_job', jobId };
    delete pinned.startUrl;
    return pinned;
  }

  mapSourceTypeLabel(sourceType) {
    switch (sourceType) {
      case 'sitemap':
        return this.message('SourceTypeSitemap');
      case 'crawl':
        return this.message('SourceTypeCrawl');
      case 'single_page':
        return this.message('SourceTypeSinglePage');
      default:
        return '';
    }
  }

  async copyHtml() {
    if (!this.currentHtml) {
      return;
    }
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(this.currentHtml);
      } else {
        this.copyViaTextarea(this.currentHtml);
      }
      this.setTemporaryButtonLabel(this.copyButton, this.message('Copied'));
      this.setStatus(this.message('CopyOk'), 'ok');
    } catch (error) {
      this.setStatus(this.message('CopyFailed'), 'error');
    }
  }

  copyViaTextarea(value) {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);
    if (!copied) {
      throw new Error('copy_failed');
    }
  }

  downloadTxt() {
    if (!this.currentText) {
      return;
    }
    const blob = new Blob([this.currentText], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'accessibility-statement-draft.txt';
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    this.setTemporaryButtonLabel(this.downloadButton, this.message('Downloaded'));
  }

  async downloadPdf() {
    if (!this.pdfUrl || !this.lastPayload || !this.pdfButton || this.preparingPdf) {
      return;
    }
    this.preparingPdf = true;
    this.pdfButton.disabled = true;
    const originalLabel = this.pdfButton.textContent || this.message('ButtonDownloadPdf');
    this.pdfButton.textContent = this.message('PreparingPdf');
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS) : 0;
    try {
      let response;
      try {
        response = await fetch(this.pdfUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/pdf, application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          body: JSON.stringify(this.lastPayload),
          signal: controller ? controller.signal : undefined,
        });
      } catch (error) {
        throw new StatementRequestError(this.message('PdfUnavailable'));
      }
      const contentType = response.headers.get('content-type') || '';
      if (!response.ok || !contentType.includes('application/pdf')) {
        const error = contentType.includes('application/json') ? await response.json().catch(() => null) : null;
        throw new StatementRequestError(this.serverMessage(error) || this.message('PdfUnavailable'));
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'accessibility-statement-draft.pdf';
      document.body.append(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      this.setStatus(this.message('PdfDownloaded'), 'ok');
    } catch (error) {
      this.setStatus(this.errorMessage(error, 'PdfUnavailable'), 'error');
    } finally {
      if (controller) {
        window.clearTimeout(timer);
      }
      this.preparingPdf = false;
      this.pdfButton.disabled = this.currentHtml === '';
      this.pdfButton.textContent = originalLabel;
    }
  }

  buildPlainTextFallback(statement) {
    const lines = [this.message('StatusReview'), ''];
    if (Array.isArray(statement.sections)) {
      statement.sections.forEach((section) => {
        if (section.heading) lines.push(String(section.heading));
        if (section.body) lines.push(String(section.body));
        if (Array.isArray(section.list)) {
          section.list.forEach((item) => lines.push(`- ${String(item)}`));
        }
        lines.push('');
      });
    }
    return `${lines.join('\n').trim()}\n`;
  }

  getCheckedValues(fields) {
    return Array.isArray(fields) ? fields
      .filter((field) => field instanceof HTMLInputElement && field.checked)
      .map((field) => field.value) : [];
  }

  getInputValue(field) {
    if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
      return field.value.trim();
    }
    return '';
  }

  mapStatusLabel(statementStatus) {
    switch (statementStatus) {
      case 'draft_issues_found':
        return this.message('StatusDraftIssues');
      case 'draft_no_issues_found':
        return this.message('StatusNoIssues');
      case 'scan_failed_or_incomplete':
        return this.message('StatusIncomplete');
      case 'no_scan_available':
        return this.message('StatusNoScan');
      case 'draft_requires_review':
      default:
        return this.message('StatusReview');
    }
  }

  setStatus(message, tone) {
    if (!this.status) {
      return;
    }
    this.status.textContent = message;
    this.status.className = 'aqg-inline-status js-aqg-statement-status';
    this.status.classList.add(`aqg-inline-status--${tone === 'ok' ? 'ok' : (tone === 'error' ? 'error' : 'muted')}`);
    this.status.hidden = false;
  }

  setTemporaryButtonLabel(button, label) {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    const original = button.textContent || '';
    button.textContent = label;
    window.setTimeout(() => {
      button.textContent = original;
    }, 1600);
  }
}

export function initializeStatementSettings(root) {
  return new AqgStatementSettings(root);
}

document.querySelectorAll('[data-aqg-statement="true"]').forEach((root) => {
  initializeStatementSettings(root);
});
