import {el, format} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

/**
 * Accessibility › AI fixes: filters, counts, pagination copy and the read-only views (three-way conflict, change log
 * row). Pure helpers, so the queue script stays about requests and focus; every value from the server is set as text.
 */

/** The queue filters as the list route reads them. Unknown or empty values mean "all". */
export function readQueueFilters(form, pageUid = 0) {
    const data = form ? new FormData(form) : new FormData();
    const text = (name) => String(data.get(name) ?? '').trim();
    const severity = data.get('severity');
    return {
        query: text('query').replace(/\s+/g, ' ').slice(0, 100),
        status: text('status'),
        owner: text('owner'),
        severity: severity === null || severity === '' ? -1 : Number(severity),
        origin: text('origin'),
        pageUid: Number(pageUid) > 0 ? Number(pageUid) : 0,
    };
}

/** True when anything narrows the list: then an empty result is "no match", not "nothing pending". */
export function filtersActive(filters = {}) {
    return Boolean(filters.query)
        || Boolean(filters.status)
        || Boolean(filters.owner)
        || Boolean(filters.origin)
        || (filters.severity !== undefined && Number(filters.severity) >= 0)
        || Number(filters.pageUid ?? 0) > 0;
}

/** The site scope of a request: the chosen site, or every site of the filter ("All sites"). */
export function siteScope(selected, allSites = [], defaultSites = []) {
    if (selected === '__all') {
        return [...allSites];
    }
    if (selected) {
        return allSites.includes(selected) ? [selected] : [];
    }
    return [...defaultSites];
}

/** "1–25 of 40" (Atlas copy deck): the rows on this page out of all matching rows. */
export function rangeLabel(template, page, perPage, total) {
    if (total <= 0) {
        return format(template, 0, 0, 0);
    }
    const size = Math.max(1, Number(perPage) || 1);
    const first = (Math.max(1, page) - 1) * size + 1;
    const last = Math.min(total, first + size - 1);
    return format(template, first, last, total);
}

/** The live result count: "1 item" / "14 items" without filters, "3 of 14 shown" with them. */
export function resultCountText(labels, total, tabTotal, active, locale = '') {
    if (active && Number.isFinite(tabTotal) && tabTotal >= total) {
        return format(labels.resultCountOf || '%1$d of %2$d shown', formatNumber(total, locale), formatNumber(tabTotal, locale));
    }
    return countText(total, labels.resultCountOne || '%d item', labels.resultCount || '%d items', locale);
}

/** Singular for exactly one, plural otherwise ("0 items", "1 item", "2 items"); the number in the backend locale. */
export function countText(count, one, many, locale = '') {
    return format(Number(count) === 1 ? one : many, formatNumber(count, locale));
}

/**
 * The backend language decides how dates and numbers read (the controller passes "de-DE" or "en-GB"), never the
 * browser locale: a German editor sees "02.11.2026, 19:39" in an English browser too (Atlas audit F-16).
 */
export function formatNumber(value, locale = '') {
    try {
        return new Intl.NumberFormat(locale || 'en-GB').format(Number(value) || 0);
    } catch {
        return String(Number(value) || 0);
    }
}

export function formatDateTime(timestamp, locale = '') {
    if (!timestamp) {
        return '';
    }
    // The same pattern as the PHP BackendLocaleFormatter::dateTime(): "2 Nov 2026, 19:39" / "02.11.2026, 19:39".
    const options = {dateStyle: 'medium', timeStyle: 'short'};
    try {
        return new Intl.DateTimeFormat(locale || 'en-GB', options).format(new Date(Number(timestamp) * 1000));
    } catch {
        return new Intl.DateTimeFormat('en-GB', options).format(new Date(Number(timestamp) * 1000));
    }
}

/**
 * The honest-outcome ladder of an item (Atlas 15 "Honest outcomes", shared .aqg-ladder): Suggested → (In editor) →
 * Saved → Verified. A step is done only once it really happened; the current step is the last one reached, and the
 * last step reads "Not verified" when AQG's check could not confirm the fix. Approved and Saving are not steps: an
 * approved or saving item is still at Suggested until the record holds the value. Rejected and not applicable items
 * have no ladder (null).
 *
 * @returns {{key: string, state: 'done'|'current'|'todo', variant: ''|'verified'|'notverified'}[]|null}
 */
export function ladderSteps(item) {
    if (!item || ['rejected', 'not_applicable'].includes(item.status)) {
        return null;
    }
    const keys = item.applyMode === 'editor'
        ? ['pending_review', 'in_editor', 'saved', 'verified']
        : ['pending_review', 'saved', 'verified'];
    let reached = -1;
    switch (item.status) {
        case 'verified':
        case 'not_verified':
            reached = keys.length - 1;
            break;
        case 'saved':
            reached = keys.indexOf('saved');
            break;
        case 'in_editor':
            reached = keys.indexOf('in_editor');
            break;
        default:
            reached = Number(item.proposalVersion) > 0 ? 0 : -1;
    }
    return keys.map((key, index) => {
        const last = index === keys.length - 1;
        return {
            key: last && item.status === 'not_verified' ? 'not_verified' : key,
            state: index < reached ? 'done' : (index === reached ? 'current' : 'todo'),
            variant: index === reached && last ? (item.status === 'verified' ? 'verified' : 'notverified') : '',
        };
    });
}

/** <ol class="aqg-ladder"> for ladderSteps(); the state is in the words and the class, never in a glyph. */
export function ladderView(item, l) {
    const steps = ladderSteps(item);
    if (!steps) {
        return null;
    }
    return el('ol', {class: 'aqg-ladder', 'aria-label': l('drawer.outcome', 'Outcome')}, ...steps.map((step) => el('li', {
        class: [step.state === 'todo' ? '' : `is-${step.state}`, step.variant ? `is-${step.variant}` : ''].filter(Boolean).join(' ') || null,
        'aria-current': step.state === 'current' ? 'step' : null,
    },
    el('span', {class: 'aqg-ladder__dot', 'aria-hidden': 'true'}),
    el('span', {text: l(`ladder.${step.key}`, {pending_review: 'Suggested', in_editor: 'In editor, unsaved', saved: 'Saved', verified: 'Verified', not_verified: 'Not verified'}[step.key])}))));
}

/** True when the item holds a real AI proposal (a value someone could review), not only a status or a reason. */
export function hasProposal(item) {
    return Boolean(item && (item.finalValue || item.proposedValue) && Number(item.proposalVersion ?? 1) > 0);
}

/**
 * What the drawer and the list show as the item's result (owner UX pass 3.0.0). A failed preparation is an error, never
 * a suggestion: its reason is shown once, without the outcome ladder, and with the route to correcting the record.
 *
 * - proposal:      a reviewable before → after exists (any status, including an apply that failed)
 * - conflict:      the record changed after the proposal (three-way view)
 * - preparing:     waiting for a suggestion
 * - prepFailed:    preparing failed (image unreadable, provider error…): no proposal
 * - noSuggestion:  the AI found no safe change: fix by hand
 * - closed:        rejected / no longer applicable without a proposal
 *
 * @returns {'proposal'|'conflict'|'preparing'|'prepFailed'|'noSuggestion'|'closed'}
 */
export function resultKind(item) {
    if (!item) {
        return 'closed';
    }
    if (item.status === 'conflict' || item.conflict) {
        return 'conflict';
    }
    if (hasProposal(item)) {
        return 'proposal';
    }
    switch (item.status) {
        case 'preparing':
            return 'preparing';
        case 'failed':
            return 'prepFailed';
        case 'no_suggestion':
            return 'noSuggestion';
        default:
            return 'closed';
    }
}

/**
 * The outcome chip tone of a status (shared .aqg-outcome--*). AI-generated, approved, saved and verified stay
 * distinct: only Verified is green, Saved is neutral ink, a pending suggestion is the quiet "suggested" chip.
 */
export function outcomeTone(status) {
    return {
        preparing: 'queued', no_suggestion: 'queued', pending_review: 'suggested', approved: 'approved', in_editor: 'inserted',
        applying: 'approved', saved: 'saved', verified: 'verified', not_verified: 'notverified', conflict: 'conflict',
        failed: 'failed', rejected: 'rejected', not_applicable: 'queued',
    }[status] ?? 'suggested';
}

export function outcomeChip(status, label) {
    return el('span', {class: `aqg-outcome aqg-outcome--${outcomeTone(status)}`, text: label});
}

/**
 * "You can apply this change." only while there is something to apply; a done, saved or verified item says nothing
 * about applying (Atlas audit F-12). A refusal (no edit access, no licence…) stays visible while the item is open.
 */
export function permissionLineVisible(item) {
    if (!item || item.isDone || item.isSaved) {
        return false;
    }
    if (item.permissionLine !== 'can_apply') {
        return true;
    }
    return Boolean(item.actions?.approve || item.actions?.edit || item.actions?.openInEditor || item.editUrl);
}

/**
 * Conflict (Atlas 08·B): base / saved now / suggested side by side. The current value is shown only when the server
 * could read it; otherwise the column says so and offers nothing it cannot prove.
 */
export function conflictView(conflict, l) {
    if (!conflict) {
        return null;
    }
    const value = (text) => (text ? el('span', {class: 'aqg-remediation__value', text}) : el('span', {class: 'aqg-remediation__muted', text: l('drawer.empty', '(empty)')}));
    const current = conflict.currentKnown
        ? [value(conflict.current), conflict.currentDecorative ? el('span', {class: 'aqg-remediation__muted aqg-remediation__flag', text: l('conflict.decorative', 'Marked as decorative')}) : null]
        : [el('span', {class: 'aqg-remediation__muted', text: l('conflict.unknown', 'AQG can no longer locate this element in the stored record. Open the record to compare.')})];
    const column = (heading, modifier, ...content) => el('div', {class: `aqg-remediation__threeway-col${modifier ? ` ${modifier}` : ''}`},
        el('h4', {class: 'aqg-remediation__threeway-title', text: heading}),
        ...content,
    );

    return el('section', {class: 'aqg-remediation__conflict', 'aria-labelledby': 'aqg-rq-conflict-title', 'data-aqg-rq-conflict': 'true'},
        el('div', {class: 'aqg-remediation__conflict-notice', role: 'note'},
            el('h3', {id: 'aqg-rq-conflict-title', class: 'aqg-remediation__h3', text: l('conflict.title', 'The record changed after this suggestion was made')}),
            el('p', {text: l('conflict.text', 'Applying would overwrite that change, so it is blocked. Nothing was written.')}),
        ),
        el('div', {class: 'aqg-remediation__threeway'},
            column(l('conflict.base', 'Suggestion base'), '', value(conflict.base)),
            column(l('conflict.current', 'Saved now'), 'is-current', ...current),
            column(l('conflict.proposed', 'Suggested'), '', value(conflict.proposed)),
        ),
    );
}

/**
 * One change-log row: step and status before → after; the short value hash as stored stays in a closed disclosure
 * (information density); never a reconstructed value. Outcome is what this event reached, empty for other steps.
 */
export function logRow(entry, l, when = (timestamp) => formatDateTime(timestamp)) {
    const change = el('td', {class: 'aqg-remediation__logchange', 'data-label': l('log.col.change', 'Change (before → after)')},
        el('strong', {text: entry.actionLabel}),
        entry.fromLabel || entry.toLabel
            ? el('span', {class: 'aqg-remediation__logstatus'},
                el('span', {class: 'aqg-remediation__muted', text: entry.fromLabel || '–'}),
                el('span', {'aria-hidden': 'true', text: ' → '}),
                el('span', {class: 'visually-hidden', text: ` ${l('change.to', 'changes to')} `}),
                el('span', {text: entry.toLabel || '–'}))
            : null,
        entry.valueHash ? el('details', {class: 'aqg-remediation__logdetails'},
            el('summary', {text: l('log.details', 'Technical details')}),
            el('span', {class: 'aqg-remediation__hash', text: format(l('log.hash', 'Value hash %s'), entry.valueHash)})) : null,
    );
    return el('tr', {},
        el('td', {class: 'aqg-remediation__age', 'data-label': l('log.col.time', 'Time')}, el('time', {datetime: new Date(entry.at * 1000).toISOString(), text: when(entry.at)})),
        el('td', {'data-label': l('log.col.person', 'Person'), text: entry.by || '–'}),
        el('td', {'data-label': l('log.col.page', 'Page · field')},
            el('span', {text: entry.pageTitle}), ' ', el('span', {class: 'aqg-remediation__uid', text: `[${entry.pageUid}]`}),
            el('span', {class: 'aqg-remediation__target', text: [entry.ruleTitle, entry.fieldLabel].filter(Boolean).join(' · ')})),
        change,
        el('td', {'data-label': l('log.col.model', 'Model · source')},
            el('span', {text: entry.model ? [entry.providerLabel, entry.modelLabel || entry.model].filter(Boolean).join(' · ') : '–'}),
            el('span', {class: 'aqg-remediation__target', text: entry.originLabel})),
        el('td', {'data-label': l('log.col.outcome', 'Outcome')}, entry.outcome ? outcomeChip(entry.outcome, entry.outcomeLabel) : null),
    );
}
