import {postRemediation} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

/**
 * F-08 (Atlas 07·E): the owner selector in a "What to fix first" fix's closed Details. It assigns the frontend rule's
 * owner on the site root page through the existing route a11y_remediation_owner (the server checks edit access, the
 * licence and that the rule belongs to a stored paid frontend scan of the site) and then shows the owner tag the
 * server answers for this person: an owner, "Varies by page", or none ("Unassigned" removes the tag). Labels come from
 * the form's data-label-* attributes; the result is announced in the form's own status region.
 */
const OWNER_CLASS_PREFIX = 'aqg-fix__owner--';

const label = (form, name, fallback) => {
    const value = form.dataset[name];
    return typeof value === 'string' && value !== '' ? value : fallback;
};

const errorLabel = (form, code) => {
    const key = {
        permission_denied: 'labelErrorPermissionDenied',
        session_expired: 'labelErrorSessionExpired',
    }[code];
    return (key && label(form, key, '')) || label(form, 'labelErrorGeneric', 'Something went wrong. Nothing was changed.');
};

const renderTag = (form, tags, tag) => {
    const owner = String(tag?.owner ?? '');
    tags.replaceChildren();
    if (owner === '') {
        tags.hidden = true;
        return;
    }
    const chip = document.createElement('span');
    chip.className = `aqg-fix__owner ${OWNER_CLASS_PREFIX}${owner}`;
    chip.setAttribute('data-aqg-fix-owner', owner);
    const prefix = document.createElement('span');
    prefix.className = 'visually-hidden';
    prefix.textContent = `${label(form, 'labelPrefix', 'Assigned owner')}: `;
    chip.append(prefix, document.createTextNode(String(tag?.label ?? owner)));
    tags.append(chip);
    tags.hidden = false;
};

export const assignPriorityFixOwner = async (form, {ajaxUrls = window.TYPO3?.settings?.ajaxUrls ?? {}} = {}) => {
    const select = form.querySelector('[data-aqg-fix-owner-select]');
    const button = form.querySelector('[data-aqg-fix-owner-save]');
    const status = form.querySelector('[data-aqg-fix-owner-status]');
    const ruleId = form.dataset.ruleId ?? '';
    const owner = select?.value ?? '';
    if (!select || ruleId === '' || owner === '') {
        return false;
    }
    if (button) {
        button.disabled = true;
    }
    const response = await postRemediation(ajaxUrls.a11y_remediation_owner, {pageUid: Number(form.dataset.pageUid || 0), ruleId, owner});
    if (button) {
        button.disabled = false;
    }
    if (!response.ok) {
        if (status) {
            status.textContent = errorLabel(form, response.code);
        }
        return false;
    }
    const item = form.closest('.aqg-fix');
    const tags = [...(item?.querySelectorAll('[data-aqg-fix-tags]') ?? [])].find((node) => node.getAttribute('data-aqg-fix-tags') === ruleId);
    if (tags) {
        renderTag(form, tags, response.data?.tag);
    }
    if (status) {
        status.textContent = owner === 'unassigned' ? label(form, 'labelRemoved', 'Owner removed from this issue type.') : label(form, 'labelSaved', 'Owner saved for this issue type.');
    }
    return true;
};

export const initializePriorityFixOwner = (root = document, options = {}) => {
    root.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-aqg-fix-owner-save]') : null;
        const form = button?.closest('[data-aqg-fix-owner-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        assignPriorityFixOwner(form, options);
    });
    root.addEventListener('submit', (event) => {
        const form = event.target instanceof Element ? event.target.closest('[data-aqg-fix-owner-form]') : null;
        if (form) {
            event.preventDefault();
            assignPriorityFixOwner(form, options);
        }
    });
};
