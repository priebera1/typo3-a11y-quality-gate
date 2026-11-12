import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

/**
 * Settings › Agent access (MCP): waits for the pairing confirmation, copies the server URL / command / TSconfig line,
 * confirms token revocation in a modal and keeps the emergency disconnect disabled until the localised word is typed.
 * Every action is also checked on the server; this module only adds convenience.
 */
class AqgMcpSettings {
  constructor(root) {
    this.root = root;
    this.initPairing();
    this.initStarting();
    this.initCopy();
    this.initRevoke();
    this.initDisconnect();
    this.initSitesForm();
  }

  /**
   * Site access: "Unsaved changes" and Discard appear once a switch or capability differs from the saved state, so a
   * change is never left behind silently. Saving still posts the whole form; the server validates every value.
   */
  initSitesForm() {
    const form = this.root.querySelector('form[data-aqg-mcp-sites]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    const dirtyNote = form.querySelector('[data-aqg-mcp-sites-dirty]');
    const reset = form.querySelector('[data-aqg-mcp-sites-reset]');
    const foot = form.querySelector('[data-aqg-mcp-sites-foot]');
    const inputs = () => [...form.querySelectorAll('input[type="checkbox"]')];
    const saved = new Map(inputs().map((input) => [input, input.checked]));
    const update = () => {
      const dirty = inputs().some((input) => saved.get(input) !== input.checked);
      if (dirtyNote) {
        dirtyNote.hidden = !dirty;
      }
      if (reset) {
        reset.hidden = !dirty;
      }
      foot?.classList.toggle('is-dirty', dirty);
    };
    form.addEventListener('change', update);
    // A reset restores the checked attributes after the event, so read the state on the next frame.
    form.addEventListener('reset', () => window.requestAnimationFrame(update));
    update();
  }

  initPairing() {
    const pairing = this.root.querySelector('[data-aqg-mcp-pairing]');
    if (!(pairing instanceof HTMLElement) || !pairing.dataset.statusUrl) {
      return;
    }
    const status = pairing.querySelector('[data-aqg-mcp-pairing-status]');
    const poll = async () => {
      try {
        const response = await fetch(pairing.dataset.statusUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (payload.status === 'connected' || payload.status === 'none') {
          window.location.reload();
          return;
        }
        if (payload.status === 'expired' || payload.status === 'cancelled') {
          if (status) {
            status.textContent = this.root.dataset.labelPairingExpired || '';
          }
          window.setTimeout(() => window.location.reload(), 2500);
          return;
        }
      } catch (error) {
        // Transient: the next poll retries.
      }
      window.setTimeout(poll, 3000);
    };
    window.setTimeout(poll, 3000);
  }

  /**
   * A fresh connection whose connector has not reported yet ("starting"): asks the tab's own state route until the
   * state changes, then reloads once, into Connected or whatever replaced it. Bounded: after the server-side grace the
   * tab shows "offline" by itself, so polling stops after 15 minutes. Failed polls are retried; nothing is shown for
   * them, the status line already says what is happening.
   */
  initStarting() {
    const starting = this.root.querySelector('[data-aqg-mcp-starting]');
    if (!(starting instanceof HTMLElement) || !starting.dataset.stateUrl) {
      return;
    }
    const intervalMs = Math.max(2, Number(starting.dataset.pollSeconds) || 4) * 1000;
    const deadline = Date.now() + 15 * 60 * 1000;
    const poll = async () => {
      try {
        const response = await fetch(starting.dataset.stateUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (payload && payload.success === true && typeof payload.state === 'string' && payload.state !== 'starting') {
          window.location.reload();
          return;
        }
      } catch (error) {
        // Transient: the next poll retries.
      }
      if (Date.now() < deadline) {
        window.setTimeout(poll, intervalMs);
      }
    };
    window.setTimeout(poll, intervalMs);
  }

  initCopy() {
    this.root.querySelectorAll('[data-aqg-mcp-copy]').forEach((node) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-default btn-sm aqg-mcp-copy';
      const label = this.root.dataset.labelCopy || 'Copy';
      button.textContent = label;
      button.setAttribute('aria-label', `${label}: ${node.textContent.trim()}`);
      button.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(node.textContent.trim());
          button.textContent = this.root.dataset.labelCopied || 'Copied';
          window.setTimeout(() => { button.textContent = label; }, 2000);
        } catch (error) {
          window.getSelection()?.selectAllChildren(node);
        }
      });
      node.insertAdjacentElement('afterend', button);
    });
  }

  initRevoke() {
    this.root.querySelectorAll('form[data-aqg-mcp-revoke]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed === '1') {
          return;
        }
        event.preventDefault();
        const modal = Modal.confirm(
          this.root.dataset.labelRevokeTitle || '',
          this.root.dataset.labelRevokeText || '',
          Severity.warning,
          [
            { text: this.root.dataset.labelCancel || 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel' },
            { text: this.root.dataset.labelRevokeConfirm || 'Revoke', btnClass: 'btn-warning', name: 'revoke' },
          ]
        );
        modal.addEventListener('button.clicked', (clickEvent) => {
          const name = clickEvent.target.getAttribute('name');
          modal.hideModal();
          if (name === 'revoke') {
            form.dataset.confirmed = '1';
            form.requestSubmit();
          }
        });
      });
    });
  }

  initDisconnect() {
    const form = this.root.querySelector('form[data-aqg-mcp-disconnect]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    const input = form.querySelector('input[name="confirmWord"]');
    const submit = form.querySelector('[data-aqg-mcp-disconnect-submit]');
    if (!(input instanceof HTMLInputElement) || !(submit instanceof HTMLButtonElement)) {
      return;
    }
    const update = () => {
      submit.disabled = input.value.trim() !== (input.dataset.expected || '');
    };
    input.addEventListener('input', update);
    update();
  }
}

document.querySelectorAll('[data-aqg-mcp]').forEach((root) => {
  if (root.dataset.aqgMcpInitialized === '1') {
    return;
  }
  root.dataset.aqgMcpInitialized = '1';
  new AqgMcpSettings(root);
});
