/**
 * My issues — the assign control on a Page Detail issue-type card. The people list is loaded when the menu opens (only
 * people who can open the page in this language are returned); the request names the finding only.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const config = (() => {
    const node = document.querySelector('[data-aqg-assignment-config]');
    try {
        return node ? JSON.parse(node.textContent || '{}') : {};
    } catch {
        return {};
    }
})();

const format = (template, value) => String(template || '%s').replace('%s', value);

async function post(url, body) {
    const response = await new AjaxRequest(url).post(body);
    return response.resolve();
}

async function readError(error) {
    try {
        return await error.response.json();
    } catch {
        return {};
    }
}

function setStatus(root, text, isError = false) {
    const status = root.querySelector('[data-aqg-assign-status]');
    if (status) {
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    }
}

async function loadCandidates(root) {
    const select = root.querySelector('[data-aqg-assign-select]');
    const submit = root.querySelector('[data-aqg-assign-submit]');
    if (!select || select.dataset.loaded === '1' || !config.candidates) {
        return;
    }
    select.dataset.loaded = '1';
    try {
        const data = await post(config.candidates, { findingId: root.dataset.findingId });
        const current = root.dataset.currentAssignee || '';
        select.replaceChildren();
        const candidates = Array.isArray(data.candidates) ? data.candidates : [];
        if (candidates.length === 0) {
            select.append(new Option(root.dataset.labelNone || '', ''));
            return;
        }
        select.append(new Option(root.dataset.labelChoose || '', ''));
        candidates.forEach((candidate) => {
            const option = new Option(candidate.label, String(candidate.uid));
            option.selected = String(candidate.uid) === current;
            select.append(option);
        });
        select.disabled = false;
        if (submit) {
            submit.disabled = select.value === '';
        }
    } catch {
        select.dataset.loaded = '';
        setStatus(root, root.dataset.labelFailed || '', true);
    }
}

async function save(root, assignee) {
    const note = root.querySelector('[data-aqg-assign-note]')?.value || '';
    setStatus(root, '');
    try {
        const data = await post(config.assign, {
            findingId: root.dataset.findingId,
            assignee: String(assignee),
            // What this card showed: the server refuses the change (409) when someone else changed it meanwhile.
            expectedAssignee: root.dataset.currentAssignee || '0',
            note,
        });
        root.dataset.currentAssignee = data.assignee > 0 ? String(data.assignee) : '';
        const chip = root.querySelector('[data-aqg-assign-chip]');
        if (chip) {
            chip.textContent = data.assignee > 0 ? format(root.dataset.labelAssigned, data.assigneeLabel) : (root.dataset.labelNobody || '');
        }
        setStatus(root, data.message || '');
    } catch (error) {
        const payload = await readError(error);
        setStatus(root, payload.message || root.dataset.labelFailed || '', true);
    }
}

document.querySelectorAll('[data-aqg-assign]').forEach((root) => {
    const menu = root.querySelector('[data-aqg-assign-menu]');
    menu?.addEventListener('toggle', () => {
        if (menu.open) {
            loadCandidates(root);
        }
    });
    const select = root.querySelector('[data-aqg-assign-select]');
    const submit = root.querySelector('[data-aqg-assign-submit]');
    select?.addEventListener('change', () => {
        if (submit) {
            submit.disabled = select.value === '';
        }
    });
    submit?.addEventListener('click', () => {
        if (select && select.value !== '') {
            save(root, select.value);
        }
    });
    root.querySelector('[data-aqg-assign-clear]')?.addEventListener('click', () => save(root, 0));
});
