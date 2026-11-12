/**
 * Scan fields and Rules: collapsible groups. The group header holds controls of its own ("Enable all", "Enable all in
 * group"), so it cannot be a <summary> (axe nested-interactive, WCAG 4.1.2). The header is a plain row; its title is a
 * disclosure button (aria-expanded + aria-controls) and the bulk control is a sibling button.
 */
export const GROUP_SELECTOR = '[data-aqg-group-disclosure="true"]';
export const TOGGLE_SELECTOR = '[data-aqg-group-toggle="true"]';

export function setGroupExpanded(group, expanded) {
    const toggle = group.querySelector(TOGGLE_SELECTOR);
    const bodyId = toggle?.getAttribute('aria-controls') || '';
    const body = bodyId !== '' ? group.ownerDocument.getElementById(bodyId) : null;
    if (!toggle || !body) {
        return;
    }

    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    body.hidden = !expanded;
    group.classList.toggle('is-open', expanded);
}

export function initSettingsGroups(root = document) {
    root.querySelectorAll(GROUP_SELECTOR).forEach((group) => {
        const toggle = group.querySelector(TOGGLE_SELECTOR);
        if (!toggle || toggle.dataset.aqgGroupBound === '1') {
            return;
        }
        toggle.dataset.aqgGroupBound = '1';
        setGroupExpanded(group, toggle.getAttribute('aria-expanded') === 'true');
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            setGroupExpanded(group, toggle.getAttribute('aria-expanded') !== 'true');
        });
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initSettingsGroups(document));
    } else {
        initSettingsGroups(document);
    }
}
