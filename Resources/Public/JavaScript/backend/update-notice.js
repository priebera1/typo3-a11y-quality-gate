import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

// The "new AQG version available" notice of the AQG backend modules. Dismissing it hides that release for the
// current backend user on the server; the notice disappears only after the server has stored it.

const NOTICE_SELECTOR = '[data-aqg-update-notice]';
const DISMISS_SELECTOR = '[data-action="a11y-dismiss-update-notice"]';
const DISMISS_ROUTE = 'a11y_update_notice_dismiss';

const showFailure = (notice) => {
    const message = notice.dataset.labelDismissFailed || 'The notice could not be hidden. Try again.';
    const notification = window.top?.TYPO3?.Notification;
    if (notification && typeof notification.showMessage === 'function') {
        notification.showMessage(notice.dataset.labelNotificationTitle || 'Accessibility', message, -1, 5);
        return;
    }

    notice.querySelector(DISMISS_SELECTOR)?.setAttribute('title', message);
};

/**
 * Keyboard users keep their place: when the dismissed notice held the focus, it moves to the module heading
 * instead of falling back to the start of the document.
 */
const moveFocusAfterRemoval = (notice) => {
    const module = notice.closest('.aqg-module') ?? document;
    const heading = module.querySelector('.aqg-page-head__title, h1, h2');
    if (!(heading instanceof HTMLElement)) {
        return;
    }

    if (!heading.hasAttribute('tabindex')) {
        heading.setAttribute('tabindex', '-1');
        heading.addEventListener('blur', () => heading.removeAttribute('tabindex'), {once: true});
    }
    heading.focus();
};

export async function dismissUpdateNotice(button) {
    const notice = button.closest(NOTICE_SELECTOR);
    const endpoint = globalThis.TYPO3?.settings?.ajaxUrls?.[DISMISS_ROUTE] || '';
    const version = String(button.dataset.version || '').trim();
    if (!notice || endpoint === '' || version === '') {
        return false;
    }

    const hadFocus = notice.contains(document.activeElement);
    button.disabled = true;

    try {
        const response = await new AjaxRequest(endpoint).post({version});
        const data = await response.resolve();
        if (!data || data.success !== true) {
            throw new Error('dismiss_rejected');
        }
    } catch {
        button.disabled = false;
        showFailure(notice);
        return false;
    }

    if (hadFocus) {
        moveFocusAfterRemoval(notice);
    }
    notice.remove();

    return true;
}

export function initializeUpdateNotice(root = document) {
    root.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest(DISMISS_SELECTOR) : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        dismissUpdateNotice(button);
    });
}

if (typeof document !== 'undefined') {
    initializeUpdateNotice(document);
}
