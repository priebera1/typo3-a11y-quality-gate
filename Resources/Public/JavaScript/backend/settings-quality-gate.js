class AqgQualityGateSettings {
  constructor(form) {
    this.form = form;
    this.scopeInput = form.querySelector('.js-aqg-quality-gate-scope-input');
    this.resetInput = form.querySelector('.js-aqg-quality-gate-reset-input');
    this.globalPanel = form.querySelector('.js-aqg-scope-panel-global');
    this.perSitePanel = form.querySelector('.js-aqg-scope-panel-per-site');
    this.siteList = form.querySelector('.js-aqg-sitelist');
    this.emptyState = form.querySelector('.js-aqg-site-empty');
    this.addPanel = form.querySelector('.js-aqg-addsite-panel');
    this.addToggle = form.querySelector('.js-aqg-addsite-toggle');
    this.addSelect = form.querySelector('.js-aqg-addsite-select');
    this.countNode = form.querySelector('.js-aqg-site-override-count');
    this.metaNode = form.querySelector('.js-aqg-quality-gate-action-meta');

    this.bindEvents();
    this.updateModePills();
    this.updateState();
    this.updateAvailableSiteUi();
  }

  bindEvents() {
    this.form.querySelectorAll('.js-aqg-scope-card').forEach((button) => {
      button.addEventListener('click', () => this.setScope(button.dataset.scope === 'per-site' ? 'per-site' : 'global'));
    });

    this.form.addEventListener('change', (event) => {
      const target = event.target;
      if (target instanceof HTMLInputElement && target.matches('.aqg-radio-card__input')) {
        this.updateRadioCards(target.name);
      }
      if (target instanceof HTMLSelectElement && target.matches('.js-aqg-site-mode')) {
        this.updateModePillForRow(target.closest('.js-aqg-site-row'));
      }
    });

    this.form.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }

      if (target.closest('.js-aqg-reset-defaults')) {
        event.preventDefault();
        this.resetToDefaults();
        return;
      }

      if (target.closest('.js-aqg-addsite-toggle')) {
        event.preventDefault();
        this.openAddPanel();
        return;
      }

      if (target.closest('.js-aqg-addsite-cancel')) {
        event.preventDefault();
        this.closeAddPanel();
        return;
      }

      if (target.closest('.js-aqg-addsite-confirm')) {
        event.preventDefault();
        this.addSelectedSite();
        return;
      }

      const removeButton = target.closest('.js-aqg-remove-site');
      if (removeButton) {
        event.preventDefault();
        this.showRemoveConfirm(removeButton.closest('.js-aqg-site-row'));
        return;
      }

      const cancelButton = target.closest('.js-aqg-remove-cancel');
      if (cancelButton) {
        event.preventDefault();
        this.hideRemoveConfirm(cancelButton.closest('.js-aqg-site-row'));
        return;
      }

      const confirmedButton = target.closest('.js-aqg-remove-confirmed');
      if (confirmedButton) {
        event.preventDefault();
        this.removeSite(confirmedButton.closest('.js-aqg-site-row'));
      }
    });
  }

  setScope(scope) {
    const isPerSite = scope === 'per-site';
    this.scopeInput.value = isPerSite ? '0' : '1';

    this.form.querySelectorAll('.js-aqg-scope-card').forEach((button) => {
      const active = button.dataset.scope === scope;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      const radio = button.querySelector('.aqg-radio');
      if (radio) {
        radio.classList.toggle('is-checked', active);
      }
    });

    this.updateState();
  }

  updateState() {
    const isPerSite = this.scopeInput.value === '0';
    this.globalPanel?.classList.toggle('is-active', !isPerSite);
    this.perSitePanel?.classList.toggle('is-active', isPerSite);
    this.updateCounts();
  }

  updateCounts() {
    const count = this.siteList ? this.siteList.querySelectorAll('.js-aqg-site-row').length : 0;
    if (this.countNode) {
      this.countNode.textContent = String(count);
    }
    if (this.emptyState) {
      this.emptyState.hidden = count > 0;
    }
    if (this.metaNode) {
      this.metaNode.textContent = this.scopeInput.value === '0'
        ? `Applies to publish & unhide · Per-site · ${count} override${count === 1 ? '' : 's'}`
        : 'Applies to publish & unhide · Global configuration';
    }
  }

  updateRadioCards(name) {
    this.form.querySelectorAll(`.aqg-radio-card__input[name="${CSS.escape(name)}"]`).forEach((input) => {
      const card = input.closest('.aqg-radio-card');
      const radio = card?.querySelector('.aqg-radio');
      card?.classList.toggle('is-active', input.checked);
      radio?.classList.toggle('is-checked', input.checked);
    });
  }

  openAddPanel() {
    if (!this.addPanel || !this.addSelect || this.addSelect.options.length === 0) {
      this.updateAvailableSiteUi();
      return;
    }
    this.addPanel.hidden = false;
    this.addSelect.focus();
  }

  closeAddPanel() {
    if (this.addPanel) {
      this.addPanel.hidden = true;
    }
  }

  addSelectedSite() {
    if (!this.addSelect || !this.siteList || !this.addSelect.value) {
      return;
    }

    const option = this.addSelect.selectedOptions[0];
    const identifier = this.addSelect.value;
    const label = option?.dataset.label || option?.textContent?.trim() || identifier;

    if (this.siteList.querySelector(`[data-site-identifier="${CSS.escape(identifier)}"]`)) {
      this.closeAddPanel();
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = this.renderSiteRow(identifier, label);
    const row = wrapper.firstElementChild;
    if (!row) {
      return;
    }

    this.siteList.append(row);
    option?.remove();
    this.closeAddPanel();
    this.updateModePillForRow(row);
    this.updateCounts();
    this.updateAvailableSiteUi();
  }

  renderSiteRow(identifier, label) {
    const escapedIdentifier = this.escapeHtml(identifier);
    const escapedLabel = this.escapeHtml(label);
    const namePrefix = `qualityGate[sites][${escapedIdentifier}]`;

    return `
      <div class="aqg-site js-aqg-site-row" data-site-identifier="${escapedIdentifier}">
        <header class="aqg-site__head">
          <div class="aqg-site__title-block">
            <span class="aqg-site__title">
              ${escapedLabel}
              <span class="aqg-site__id">${escapedIdentifier}</span>
              <span class="aqg-mode-pill js-aqg-mode-pill" data-mode="1"><span class="aqg-mode-pill__dot"></span><span class="js-aqg-mode-label">Warn editors</span></span>
            </span>
            <span class="aqg-site__sub">Stored for site identifier <code>${escapedIdentifier}</code></span>
          </div>
          <div class="aqg-site__actions">
            <button type="button" class="btn btn-default btn-sm js-aqg-remove-site">Remove custom configuration</button>
            <span class="aqg-confirm js-aqg-remove-confirm" hidden="hidden">
              <span>Remove custom configuration?</span>
              <button type="button" class="aqg-confirm__btn js-aqg-remove-cancel">Cancel</button>
              <button type="button" class="aqg-confirm__btn aqg-confirm__btn--danger js-aqg-remove-confirmed">Remove</button>
            </span>
          </div>
        </header>
        <div class="aqg-site__body aqg-site__body--compact">
          <div class="aqg-field">
            <label class="aqg-field__label">Publishing mode</label>
            <select class="aqg-select js-aqg-site-mode" name="${namePrefix}[publish_mode]">
              <option value="0">Disabled — do not warn or block</option>
              <option value="1" selected="selected">Warn editors before publish</option>
              <option value="2">Block publish on failure</option>
            </select>
            <div class="aqg-field__help">Disabled · Warn editors · Block publishing.</div>
          </div>
          <div class="aqg-field">
            <label class="aqg-field__label">Critical threshold</label>
            <div class="aqg-inline-row"><input type="number" min="0" class="aqg-input aqg-input--num" name="${namePrefix}[threshold_critical]" value="0" /><span class="aqg-field__help">or more</span></div>
            <div class="aqg-field__help">Open criticals allowed. <code>0</code> = any critical fails.</div>
          </div>
          <div class="aqg-field">
            <label class="aqg-field__label">Warning threshold</label>
            <select class="aqg-select" name="${namePrefix}[threshold_warning]">
              <option value="-1" selected="selected">Ignore warnings</option>
              <option value="0">Any warning fails</option>
              <option value="1">1 or more fail</option>
              <option value="3">3 or more fail</option>
              <option value="5">5 or more fail</option>
              <option value="10">10 or more fail</option>
            </select>
            <div class="aqg-field__help">Optional. Leave on <em>Ignore</em> to evaluate critical only.</div>
          </div>
        </div>
        <div class="aqg-inherit-note js-aqg-inherit-note" hidden="hidden"><span class="aqg-inherit-note__badge">FYI</span>Removing this configuration makes the site inherit the global default again.</div>
      </div>`;
  }

  showRemoveConfirm(row) {
    if (!row) {
      return;
    }
    row.querySelector('.js-aqg-remove-site')?.setAttribute('hidden', 'hidden');
    row.querySelector('.js-aqg-remove-confirm')?.removeAttribute('hidden');
    row.querySelector('.js-aqg-inherit-note')?.removeAttribute('hidden');
  }

  hideRemoveConfirm(row) {
    if (!row) {
      return;
    }
    row.querySelector('.js-aqg-remove-site')?.removeAttribute('hidden');
    row.querySelector('.js-aqg-remove-confirm')?.setAttribute('hidden', 'hidden');
    row.querySelector('.js-aqg-inherit-note')?.setAttribute('hidden', 'hidden');
  }

  removeSite(row) {
    if (!row || !this.addSelect) {
      return;
    }
    const identifier = row.dataset.siteIdentifier || '';
    const label = row.querySelector('.aqg-site__title')?.childNodes[0]?.textContent?.trim() || identifier;
    if (identifier !== '') {
      const option = document.createElement('option');
      option.value = identifier;
      option.dataset.label = label;
      option.textContent = `${label}`;
      this.addSelect.append(option);
    }
    row.remove();
    this.updateCounts();
    this.updateAvailableSiteUi();
  }

  resetToDefaults() {
    if (this.resetInput) {
      this.resetInput.value = '1';
    }

    this.setScope('global');

    const defaultMode = this.form.querySelector('input[name="qualityGate[global][publish_mode]"][value="0"]');
    if (defaultMode instanceof HTMLInputElement) {
      defaultMode.checked = true;
      this.updateRadioCards(defaultMode.name);
    }

    const criticalInput = this.form.querySelector('input[name="qualityGate[global][threshold_critical]"]');
    if (criticalInput instanceof HTMLInputElement) {
      criticalInput.value = '0';
    }

    const warningSelect = this.form.querySelector('select[name="qualityGate[global][threshold_warning]"]');
    if (warningSelect instanceof HTMLSelectElement) {
      warningSelect.value = '-1';
    }

    this.form.querySelectorAll('.js-aqg-site-row').forEach((row) => this.removeSite(row));
    this.closeAddPanel();
    this.updateCounts();
    this.updateAvailableSiteUi();
  }

  updateAvailableSiteUi() {
    const hasAvailableSites = !!this.addSelect && this.addSelect.options.length > 0;
    if (this.addToggle) {
      this.addToggle.hidden = !hasAvailableSites;
      this.addToggle.disabled = !hasAvailableSites;
    }
    if (!hasAvailableSites) {
      this.closeAddPanel();
    }
  }

  updateModePills() {
    this.form.querySelectorAll('.js-aqg-site-row').forEach((row) => this.updateModePillForRow(row));
  }

  updateModePillForRow(row) {
    if (!row) {
      return;
    }
    const select = row.querySelector('.js-aqg-site-mode');
    const pill = row.querySelector('.js-aqg-mode-pill');
    const label = row.querySelector('.js-aqg-mode-label');
    if (!(select instanceof HTMLSelectElement) || !pill || !label) {
      return;
    }
    pill.classList.remove('tone-neutral', 'tone-warning', 'tone-critical');
    if (select.value === '2') {
      pill.classList.add('tone-critical');
      label.textContent = 'Block publish';
    } else if (select.value === '1') {
      pill.classList.add('tone-warning');
      label.textContent = 'Warn editors';
    } else {
      pill.classList.add('tone-neutral');
      label.textContent = 'Disabled';
    }
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

document.querySelectorAll('.js-aqg-quality-gate-form').forEach((form) => {
  if (form.dataset.aqgQualityGateInitialized === '1') {
    return;
  }
  form.dataset.aqgQualityGateInitialized = '1';
  new AqgQualityGateSettings(form);
});
