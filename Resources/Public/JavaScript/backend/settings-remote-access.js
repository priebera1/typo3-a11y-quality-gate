class AqgRemoteAccessSettings {
  constructor(form) {
    this.form = form;
    this.regenerateUrl = form.dataset.regenerateTokenUrl || '';
    this.testHttpAuthUrl = form.dataset.testHttpAuthUrl || '';
    this.rulesetSiteInput = form.querySelector('input[name="rulesetSite"]');
    this.tokenPresent = form.querySelector('.js-aqg-token-present');
    this.tokenEmpty = form.querySelector('.js-aqg-token-empty');
    this.tokenValue = form.querySelector('.js-aqg-token-value');
    this.tokenSuccess = form.querySelector('.js-aqg-token-success');
    this.regenerateButton = form.querySelector('.js-aqg-regenerate-token');
    this.regenerateLabel = form.querySelector('.js-aqg-regenerate-token-label');
    this.httpUser = form.querySelector('.js-aqg-http-auth-user');
    this.httpPass = form.querySelector('.js-aqg-http-auth-pass');
    this.testButton = form.querySelector('.js-aqg-test-http-auth');
    this.testResult = form.querySelector('.js-aqg-test-result');
    this.actionbar = form.querySelector('.js-aqg-remote-actionbar');
    this.actionMeta = form.querySelector('.js-aqg-remote-action-meta');
    this.saveToast = form.querySelector('.js-aqg-remote-save-toast');

    this.bindEvents();
  }

  bindEvents() {
    this.regenerateButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.regenerateToken();
    });

    this.testButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.testHttpAuth();
    });

    this.form.addEventListener('input', (event) => {
      if (event.target instanceof HTMLElement && event.target.closest('.aqg-card')) {
        this.setDirty(true);
      }
    });

    this.form.addEventListener('change', () => this.setDirty(true));

    this.form.addEventListener('reset', () => {
      window.setTimeout(() => {
        this.setDirty(false);
        this.hideTestResult();
      }, 0);
    });

    this.form.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }

      const chip = target.closest('.js-aqg-add-pattern');
      if (chip instanceof HTMLElement) {
        event.preventDefault();
        this.addPattern(chip);
        return;
      }

      const copy = target.closest('.js-aqg-copy-token');
      if (copy instanceof HTMLElement) {
        event.preventDefault();
        this.copyToken(copy);
      }
    });
  }

  async regenerateToken() {
    if (!this.regenerateUrl || !this.regenerateButton) {
      return;
    }

    this.setButtonLoading(this.regenerateButton, this.regenerateLabel, 'Regenerating…');
    this.tokenSuccess?.setAttribute('hidden', 'hidden');

    try {
      const response = await fetch(this.regenerateUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({}),
      });
      const payload = await response.json();

      if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Scanner token could not be regenerated.');
      }

      this.updateToken(payload.maskedToken || '', payload.token || '');
      this.tokenSuccess?.removeAttribute('hidden');
      window.setTimeout(() => this.tokenSuccess?.setAttribute('hidden', 'hidden'), 4500);
    } catch (error) {
      this.showInlineError(this.tokenSuccess, error instanceof Error ? error.message : 'Scanner token could not be regenerated.');
    } finally {
      this.restoreButton(this.regenerateButton, this.regenerateLabel, this.tokenValue?.textContent?.trim() ? 'Regenerate token' : 'Generate token');
    }
  }

  async testHttpAuth() {
    if (!this.testHttpAuthUrl || !this.testButton) {
      return;
    }

    this.setTestResult('running', 0, 'Testing connection…');
    this.testButton.disabled = true;
    this.testButton.textContent = 'Testing…';

    try {
      const response = await fetch(this.testHttpAuthUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          rulesetSite: this.rulesetSiteInput?.value || '',
          username: this.httpUser?.value || '',
          password: this.httpPass?.value || '',
        }),
      });
      const payload = await response.json();
      const status = Number(payload.status || 0);
      const ok = !!payload.ok;
      const tone = ok ? 'ok' : (status === 401 || status === 403 ? 'warning' : 'error');
      this.setTestResult(tone, status, payload.message || (ok ? 'Connection OK.' : 'Connection failed.'));
    } catch (error) {
      this.setTestResult('error', 0, 'Connection failed. Please check the credentials or the frontend protection.');
    } finally {
      this.testButton.disabled = false;
      this.testButton.textContent = 'Test connection';
    }
  }

  updateToken(maskedToken, fullToken) {
    if (this.tokenValue) {
      this.tokenValue.textContent = maskedToken;
    }
    this.tokenPresent?.removeAttribute('hidden');
    this.tokenEmpty?.setAttribute('hidden', 'hidden');
    if (this.regenerateLabel) {
      this.regenerateLabel.textContent = 'Regenerate token';
    }
    const copy = this.form.querySelector('.js-aqg-copy-token');
    if (copy instanceof HTMLElement) {
      copy.dataset.token = fullToken;
      copy.disabled = fullToken === '';
      copy.textContent = fullToken !== '' ? 'Copy token' : 'Copy after regenerate';
    }
  }

  setTestResult(tone, status, message) {
    if (!this.testResult) {
      return;
    }
    this.testResult.className = 'aqg-test-result js-aqg-test-result';
    this.testResult.classList.add(`tone-${tone}`);
    const label = tone === 'running'
      ? '<span class="aqg-test-result__spin"></span>'
      : `<span class="aqg-test-result__http">${status > 0 ? `HTTP ${status}` : 'ERR'}</span>`;
    this.testResult.innerHTML = `${label}<span>${this.escapeHtml(message)}</span>`;
    this.testResult.removeAttribute('hidden');
  }

  hideTestResult() {
    this.testResult?.setAttribute('hidden', 'hidden');
  }

  addPattern(chip) {
    const selector = chip.dataset.target || '';
    const value = chip.dataset.value || '';
    if (!selector || !value) {
      return;
    }
    const textarea = this.form.querySelector(selector);
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }
    const lines = textarea.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (!lines.includes(value)) {
      lines.push(value);
      textarea.value = lines.join('\n');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  async copyToken(button) {
    const token = button.dataset.token || '';
    if (!token || !navigator.clipboard) {
      return;
    }
    await navigator.clipboard.writeText(token);
    const original = button.textContent;
    button.textContent = 'Copied';
    window.setTimeout(() => { button.textContent = original || 'Copy'; }, 1800);
  }

  setDirty(isDirty) {
    this.actionbar?.classList.toggle('is-dirty', isDirty);
    if (this.actionMeta) {
      this.actionMeta.textContent = isDirty ? 'You have unsaved changes' : 'All changes saved';
    }
    this.saveToast?.setAttribute('hidden', 'hidden');
  }

  setButtonLoading(button, label, text) {
    button.disabled = true;
    if (label) {
      label.textContent = text;
    }
  }

  restoreButton(button, label, text) {
    button.disabled = false;
    if (label) {
      label.textContent = text;
    }
  }

  showInlineError(node, message) {
    if (!node) {
      return;
    }
    node.textContent = message;
    node.classList.remove('aqg-toast');
    node.classList.add('aqg-test-result', 'tone-error');
    node.removeAttribute('hidden');
  }

  escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}

document.querySelectorAll('.js-aqg-remote-access-form').forEach((form) => {
  if (form.dataset.aqgRemoteAccessInitialized === '1') {
    return;
  }
  form.dataset.aqgRemoteAccessInitialized = '1';
  new AqgRemoteAccessSettings(form);
});
