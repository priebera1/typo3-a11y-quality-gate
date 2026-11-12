/**
 * "Open in editor to apply" from AQG's AI fixes queue. The record editor opens with ?aqgRemediation=<item>; the
 * Smart Editor of exactly that record and field asks the server for the approved change (nothing travels in the URL
 * but the item id), matches the queue's element against its own live finding — same rule, tag, position, count and
 * current value — and hands it to the finding's AI session as its suggestion. The person still applies it in the
 * editor (one undo step) and stores it with TYPO3 Save; the queue learns about the save from the stored record.
 */

const STAGED_ROUTE = 'a11y_remediation_staged';
const INSERTED_ROUTE = 'a11y_remediation_inserted';

export function stagedItemId(search = globalThis.location?.search ?? '') {
    const value = Number(new URLSearchParams(search).get('aqgRemediation') ?? 0);
    return Number.isInteger(value) && value > 0 ? value : 0;
}

async function post(route, body) {
    const url = globalThis.TYPO3?.settings?.ajaxUrls?.[route];
    if (!url) {
        return {ok: false, code: 'unavailable', data: {}};
    }
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        return response.ok && data?.success === true ? {ok: true, code: '', data} : {ok: false, code: String(data?.code ?? 'unavailable'), data};
    } catch {
        return {ok: false, code: 'network', data: {}};
    }
}

export function fetchStaged(itemUid, {table, uid, field}) {
    return post(STAGED_ROUTE, {item: itemUid, table, uid: Number(uid), field});
}

export function reportInserted(itemUid) {
    return itemUid > 0 ? post(INSERTED_ROUTE, {item: itemUid}) : Promise.resolve({ok: false});
}

const sameAttributes = (left, right) => {
    const a = Object.entries(left ?? {}).map(([key, value]) => [key.toLowerCase(), String(value)]).sort();
    const b = Object.entries(right ?? {}).map(([key, value]) => [key.toLowerCase(), String(value)]).sort();
    return JSON.stringify(a) === JSON.stringify(b);
};

/**
 * The live finding the staged change belongs to, or null — never a nearby or similar element. The element is
 * identified by rule, tag, attributes and current value, because the editor may normalize the stored HTML (CKEditor
 * drops empty links), which shifts positions counted there. Identical candidates are told apart by the stored
 * position; if that does not single one out, nothing is adopted.
 */
export function findStagedIssue(issues, staged) {
    const target = staged?.target ?? {};
    const candidates = (issues ?? []).filter((issue) => {
        const live = issue?.aiTarget;
        return live
            && issue.ruleId === staged.ruleId
            && live.tag === target.tag
            && String(live.currentValue ?? '') === String(target.currentValue ?? '')
            && (target.attributes === undefined || sameAttributes(live.element?.attributes, target.attributes))
            && !live.unavailableReason;
    });
    if (candidates.length === 1) {
        return candidates[0];
    }
    const positioned = candidates.filter((issue) => Number(issue.aiTarget.occurrence) === Number(target.occurrence) && Number(issue.aiTarget.total) === Number(target.total));

    return positioned.length === 1 ? positioned[0] : null;
}

/**
 * One staging per editor instance: fetch once, then try to adopt after each live check until it worked or the
 * finding is provably gone (the first live check that does not contain it).
 */
export class StagedRemediation {
    constructor({table, uid, field, label, announce}) {
        this.itemUid = stagedItemId();
        this.target = {table, uid, field};
        this.label = label;
        this.announce = announce ?? (() => {});
        this.staged = null;
        this.done = this.itemUid === 0;
        this.loading = null;
    }

    get active() {
        return !this.done;
    }

    async load() {
        if (this.done || this.loading) {
            return this.loading;
        }
        this.loading = fetchStaged(this.itemUid, this.target).then((response) => {
            if (!response.ok) {
                this.done = true;
                if (response.code === 'conflict') {
                    this.announce(this.label('aiStagedStale', 'The staged change from AI fixes no longer matches this text. Regenerate it in AI fixes.'));
                }
                return null;
            }
            if (response.data.saved === true) {
                // Reloaded after TYPO3 Save: the change is stored, there is nothing left to stage.
                this.done = true;
                return null;
            }
            this.staged = response.data;
            return this.staged;
        });
        return this.loading;
    }

    /**
     * @param {object[]} issues the normalised live findings
     * @param {function(object): {adoptStaged: function(object): boolean}} sessionFor
     * @param {function(object): void} open opens the host's popover / panel for the finding
     * @returns {Promise<boolean>}
     */
    async adopt(issues, sessionFor, open) {
        if (this.done) {
            return false;
        }
        const staged = await this.load();
        if (!staged) {
            return false;
        }
        this.done = true;
        const issue = findStagedIssue(issues, staged);
        if (!issue) {
            this.announce(this.label('aiStagedNotFound', 'AQG could not find the element of the staged change in this field.'));
            return false;
        }
        const adopted = sessionFor(issue).adoptStaged(staged);
        if (adopted) {
            open(issue);
        }
        return adopted;
    }
}
