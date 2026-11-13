/**
 * Bulk review state, kept free of DOM so its rules are testable: which selected items may be applied together, and
 * the honest summary of a run. Only image texts (server-applied simple fields) go into a bulk run; an item counts as
 * reviewed only once its complete change was displayed for exactly its current version (the server checks again).
 */
export const BULK_STATUSES = ['pending_review'];

export function isBulkEligible(item) {
    return Boolean(item && item.applyMode === 'server' && item.actions?.approve && (BULK_STATUSES.includes(item.status) || item.actions?.retry));
}

/** @returns {{eligible: object[], excluded: object[], unseen: object[], label: 'apply'|'review'}} */
export function classifySelection(items) {
    const eligible = [];
    const excluded = [];
    for (const item of items) {
        (isBulkEligible(item) ? eligible : excluded).push(item);
    }
    const unseen = eligible.filter((item) => !item.seen);

    return {eligible, excluded, unseen, label: eligible.length > 0 && unseen.length === 0 && excluded.length === 0 ? 'apply' : 'review'};
}

/** Outcome of one finished bulk item, from the item the server returned (or the error code). */
export function outcomeOf(result) {
    if (!result) {
        return 'failed';
    }
    if (result.error) {
        return ['changed_concurrently', 'in_progress', 'already_done', 'not_reviewed', 'permission_denied', 'no_edit_access', 'agent_approval_disabled', 'workspace_mismatch'].includes(result.error)
            ? 'skipped'
            : 'failed';
    }
    switch (result.item?.status) {
        case 'verified':
            return 'verified';
        case 'saved':
            return 'saved';
        case 'not_verified':
            return 'not_verified';
        case 'conflict':
            return 'conflict';
        case 'failed':
            return 'failed';
        default:
            return 'skipped';
    }
}

/** Counts that never merge Saved with Verified: a verified item is saved too, a not-verified one is saved only. */
export function summarize(results) {
    const counts = {saved: 0, verified: 0, notVerified: 0, conflict: 0, failed: 0, skipped: 0};
    for (const result of results) {
        const outcome = outcomeOf(result);
        if (outcome === 'verified') {
            counts.saved++;
            counts.verified++;
        } else if (outcome === 'saved') {
            counts.saved++;
        } else if (outcome === 'not_verified') {
            counts.saved++;
            counts.notVerified++;
        } else if (outcome === 'conflict') {
            counts.conflict++;
        } else if (outcome === 'failed') {
            counts.failed++;
        } else {
            counts.skipped++;
        }
    }

    return counts;
}

/** Failed items that may be sent again: the write did not happen (the server decides again on retry). */
export function retryable(results) {
    return results.filter((result) => outcomeOf(result) === 'failed' && (result.item?.actions?.retry === true || result.error === 'network'));
}
