import { notificationSeverity } from '@priebera/a11y-quality-gate/backend/core/notification-severity.js';

/**
 * One notification per scan, the same in the Overview, the page issue list and the Page module.
 *
 * - success: every check of the scan ran ("Content scan completed").
 * - info: completed, and some rendered HTML is not checkable by design: pages visitors do not get (hidden, scheduled,
 *   restricted). The message says so; nothing claims they were checked.
 * - warning: completed, but checks that should have run did not (a public page's rendered HTML could not be fetched,
 *   a frontend page could not be loaded).
 * - error: the scan failed, or a frontend scan loaded no page at all.
 * - cancelled: info.
 *
 * The pages and reasons stay in the Overview and the page view after the toast is gone; `details` tells the caller to
 * offer the action that opens them. A toast with an action stays longer, an error does not stick: the scan status keeps
 * the reason, and several failed scans must not pile up persistent toasts.
 *
 * @typedef {{title: string, message: string, severity: 'success'|'info'|'warning'|'error', details: boolean, reportUrl?: string}} ScanNotification
 * @typedef {(key: string, fallback: string, ...values: number[]) => string} Translate
 */

export const NOTIFICATION_DURATION = Object.freeze({ success: 5, info: 10, warning: 15, error: 15, withAction: 20 });

const RENDERED_SKIPPED = 'rendered_check_skipped';

function count(value) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? Math.floor(number) : 0;
}

function plural(translate, key, singularFallback, pluralFallback, amount, ...values) {
    return amount === 1
        ? translate(`${key}.singular`, singularFallback, amount, ...values)
        : translate(`${key}.plural`, pluralFallback, amount, ...values);
}

/**
 * The rendered-check coverage of a content scan answer: the server's count when present, otherwise the warnings.
 *
 * @returns {{skipped: number, notPublic: number, failed: number}}
 */
export function renderedCoverageOf(data) {
    const coverage = data?.renderedCoverage;
    if (coverage && typeof coverage === 'object') {
        const skipped = count(coverage.skipped);
        const notPublic = Math.min(skipped, count(coverage.notPublic));
        return { skipped, notPublic, failed: skipped - notPublic };
    }

    const pages = new Map();
    (Array.isArray(data?.warnings) ? data.warnings : []).forEach((warning) => {
        if (String(warning?.code || '') !== RENDERED_SKIPPED) {
            return;
        }
        const pageKey = String(warning?.context?.pageUid ?? warning?.message ?? '');
        pages.set(pageKey, String(warning?.context?.reason || '') !== '');
    });
    const notPublic = Array.from(pages.values()).filter(Boolean).length;

    return { skipped: pages.size, notPublic, failed: pages.size - notPublic };
}

/**
 * The open Content issues of the scanned scope after the scan (server answer `openIssues`), or null when the server did
 * not send them. Without them the notification names only the change, explicitly as a change.
 *
 * @returns {{total: number, critical: number, warning: number, info: number, needsReview: number}|null}
 */
function openIssuesOf(data) {
    const open = data?.openIssues;
    if (!open || typeof open !== 'object') {
        return null;
    }
    const counts = {
        critical: count(open.critical),
        warning: count(open.warning),
        info: count(open.info),
        needsReview: count(open.needs_review ?? open.needsReview),
    };
    const sum = counts.critical + counts.warning + counts.info + counts.needsReview;

    return { total: Math.max(count(open.total), sum), ...counts };
}

/**
 * NOT-01 (2026-10 Sol audit): "0 new, 0 resolved" was the whole message of a scan of a page with 32 open issues, and a
 * clean page got the same text. The total that is open now comes first; the change since the previous scan follows
 * and is named as such. Only a scan that checked everything and left nothing open is a success.
 *
 * @param {object} data the content scan endpoint's answer
 * @param {{scope?: 'page'|'site'}} context
 * @param {Translate} translate
 * @returns {ScanNotification}
 */
export function contentScanNotification(data, { scope = 'page' } = {}, translate) {
    const issuesNew = count(data?.issuesNew);
    const issuesResolved = count(data?.issuesResolved);
    const pagesScanned = count(data?.pagesScanned);
    const coverage = renderedCoverageOf(data);
    const open = openIssuesOf(data);

    const parts = [];
    if (scope === 'site') {
        parts.push(plural(translate, 'notification.scanResult.content.pagesChecked', 'Content checks ran on %d page.', 'Content checks ran on %d pages.', pagesScanned));
    }
    if (open === null) {
        parts.push(translate('notification.scanResult.changeOnly', 'Since the previous scan: %d new, %d resolved. The report lists every open issue.', issuesNew, issuesResolved));
    } else if (open.total === 0) {
        parts.push(issuesResolved > 0
            ? plural(translate, 'notification.scanResult.content.noneOpenResolved', 'No open issues: %d issue was resolved.', 'No open issues: %d issues were resolved.', issuesResolved)
            : translate('notification.scanResult.content.noneOpen', 'No open issues.'));
    } else {
        const known = Math.max(0, open.total - issuesNew);
        parts.push(plural(
            translate,
            'notification.scanResult.content.open',
            '%d open issue (%d new, %d already known); %d resolved.',
            '%d open issues (%d new, %d already known); %d resolved.',
            open.total,
            issuesNew,
            known,
            issuesResolved
        ));
    }
    if (coverage.failed > 0) {
        parts.push(plural(translate, 'notification.scanResult.rendered.failed', 'Rendered HTML could not be checked on %d page.', 'Rendered HTML could not be checked on %d pages.', coverage.failed));
    }
    if (coverage.notPublic > 0) {
        parts.push(plural(translate, 'notification.scanResult.rendered.notPublic', 'Rendered HTML was not checked on %d page that visitors cannot open (hidden or restricted).', 'Rendered HTML was not checked on %d pages that visitors cannot open (hidden or restricted).', coverage.notPublic));
    }

    let severity = 'success';
    if (coverage.failed > 0) {
        severity = 'warning';
    } else if (coverage.notPublic > 0 || open === null || open.total > 0) {
        severity = 'info';
    }

    return {
        title: severity === 'warning'
            ? translate('notification.scanResult.content.warnings', 'Content scan completed with warnings')
            : translate('notification.scanResult.content.completed', 'Content scan completed'),
        message: parts.join(' '),
        severity,
        details: coverage.skipped > 0,
        reportUrl: String(data?.reportUrl || ''),
    };
}

/**
 * @param {object} summary the frontend scan summary: pagesScanned / pagesFailed / pagesTotal, and from AQG 1.9.9 the
 *   stored `occurrencesTotal` / `issueTypesTotal`, `comparison` ({available, new, resolved}), `pagesExpected` /
 *   `pagesLoaded` / `pagesNotChecked` and `reportUrl`
 * @param {{scope?: 'page'|'site'}} context
 * @param {Translate} translate
 * @returns {ScanNotification}
 */
export function frontendScanNotification(summary, { scope = 'site' } = {}, translate) {
    // From AQG 1.9.9 the server compares the stored findings with the previous compatible scan (`comparison`); the
    // crawler's own counts are the fallback for responses without it.
    const comparison = summary?.comparison && typeof summary.comparison === 'object' ? summary.comparison : null;
    const compared = comparison === null || comparison.available === true;
    const issuesNew = count(comparison?.available ? comparison.new : summary?.issuesNew);
    const issuesResolved = count(comparison?.available ? comparison.resolved : summary?.issuesResolved);
    const loaded = count(summary?.pagesLoaded ?? summary?.pagesScanned);
    const failed = count(summary?.pagesFailed);
    const expected = Math.max(loaded + failed, count(summary?.pagesExpected ?? summary?.pagesTotal));
    const notChecked = Math.max(0, expected - loaded - failed);
    const reportUrl = String(summary?.reportUrl || '');
    const hasTotals = summary?.occurrencesTotal !== undefined && summary?.occurrencesTotal !== null;
    const occurrences = count(summary?.occurrencesTotal);
    const issueTypes = count(summary?.issueTypesTotal);

    if (expected > 0 && loaded === 0) {
        return {
            title: translate('notification.scanResult.frontend.noPage', 'Frontend scan checked no page'),
            message: expected === 1
                ? translate('notification.scanResult.frontend.noPage.single', 'The page could not be loaded. The Overview shows the reason.')
                : translate('notification.scanResult.frontend.noPage.text', 'None of the %d pages could be loaded. The Overview lists the reasons.', expected),
            severity: 'error',
            details: true,
            reportUrl,
        };
    }

    const result = !hasTotals
        ? translate('notification.scanResult.changeOnly', 'Since the previous scan: %d new, %d resolved. The report lists every open issue.', issuesNew, issuesResolved)
        : (occurrences === 0
            ? (compared
                ? translate('notification.scanResult.frontend.noneFound', 'No issues found on the checked pages; %d resolved.', issuesResolved)
                : translate('notification.scanResult.frontend.noneFoundFirst', 'No issues found on the checked pages.'))
            : [
                plural(translate, 'notification.scanResult.frontend.occurrences', '%d occurrence', '%d occurrences', occurrences),
                plural(translate, 'notification.scanResult.frontend.issueTypes', 'of %d issue type', 'of %d issue types', issueTypes),
                compared
                    ? translate('notification.scanResult.frontend.delta', '(%d new); %d resolved.', issuesNew, issuesResolved)
                    : translate('notification.scanResult.frontend.noBaseline', '(no earlier comparable scan).'),
            ].join(' '));

    if (failed > 0 || notChecked > 0) {
        const missing = [
            failed > 0 ? plural(translate, 'notification.scanResult.frontend.failedPages', '%d page could not be loaded.', '%d pages could not be loaded.', failed) : '',
            notChecked > 0 ? plural(translate, 'notification.scanResult.frontend.notChecked', '%d page was not checked.', '%d pages were not checked.', notChecked) : '',
        ].filter(Boolean);

        return {
            title: translate('notification.scanResult.frontend.warnings', 'Frontend scan completed with warnings'),
            message: [
                translate('notification.scanResult.frontend.partial', 'Checked %d of %d pages.', loaded, expected),
                result,
                ...missing,
            ].join(' '),
            severity: 'warning',
            details: true,
            reportUrl,
        };
    }

    return {
        title: translate('notification.scanResult.frontend.completed', 'Frontend scan completed'),
        message: [
            scope === 'site' ? plural(translate, 'notification.scanResult.frontend.pagesChecked', '%d page checked.', '%d pages checked.', loaded) : '',
            result,
        ].filter(Boolean).join(' '),
        severity: hasTotals && occurrences === 0 ? 'success' : 'info',
        details: false,
        reportUrl,
    };
}

/**
 * Page module: the content scan of the page, and its frontend scan when the panel runs both.
 *
 * @returns {ScanNotification}
 */
export function pageScanNotification(contentData, frontendSummary, translate) {
    const content = contentScanNotification(contentData, { scope: 'page' }, translate);
    if (!frontendSummary) {
        return content;
    }

    const frontend = frontendScanNotification(frontendSummary, { scope: 'page' }, translate);
    const rank = { success: 0, info: 1, warning: 2, error: 3 };
    const severity = rank[frontend.severity] > rank[content.severity] ? frontend.severity : content.severity;
    const label = (key, fallback, message) => `${translate(key, fallback)}: ${message}`;

    return {
        title: severity === 'success' || severity === 'info'
            ? translate('notification.scanResult.page.completed', 'Page scan completed')
            : translate('notification.scanResult.page.warnings', 'Page scan completed with warnings'),
        message: [
            label('notification.scanResult.page.content', 'Content scan', content.message),
            label('notification.scanResult.page.frontend', 'Frontend scan', frontend.message),
        ].join(' '),
        // A frontend page that could not be loaded leaves the content results valid: a warning, not a failed scan.
        severity: severity === 'error' ? 'warning' : severity,
        details: content.details || frontend.details,
        reportUrl: content.reportUrl,
    };
}

/** @returns {ScanNotification} */
export function scanFailedNotification(source, message, translate) {
    return {
        title: source === 'frontend'
            ? translate('notification.scanResult.frontend.failed', 'Frontend scan failed')
            : translate('notification.scanResult.content.failed', 'Content scan failed'),
        message,
        severity: 'error',
        details: false,
    };
}

/** @returns {ScanNotification} */
export function scanCancelledNotification(source, translate) {
    return {
        title: source === 'frontend'
            ? translate('notification.scanResult.frontend.cancelled', 'Frontend scan cancelled')
            : translate('notification.scanResult.content.cancelled', 'Content scan cancelled'),
        message: source === 'frontend'
            ? translate('notification.scanResult.frontend.cancelledText', 'The results of the last completed scan stay as they were.')
            : translate('notification.scanResult.content.cancelledText', 'Findings of the pages checked before cancelling are kept; nothing was resolved for the others.'),
        severity: 'info',
        details: false,
    };
}

/**
 * Navigates the content frame through the backend viewport (TYPO3 13 and 14), so the action works after the module
 * frame has reloaded with the new results.
 */
function openInContentFrame(topWindow, url) {
    const container = topWindow?.TYPO3?.Backend?.ContentContainer;
    if (container && typeof container.setUrl === 'function') {
        container.setUrl(url);
        return;
    }

    const frame = topWindow?.document?.querySelector('#typo3-contentIframe, typo3-iframe-module iframe');
    if (frame?.contentWindow) {
        frame.contentWindow.location.href = url;
    }
}

/**
 * Shows the notification. With `details` and a `detailsUrl` it carries one action (a link-like button TYPO3 renders in
 * the toast) that opens the page list with the reasons.
 *
 * @param {ScanNotification} notification
 * @param {{detailsUrl?: string, detailsLabel?: string, reportLabel?: string, notificationApi?: object, topWindow?: Window}} options
 * @returns {boolean} whether TYPO3's notification API was available
 */
export function showScanNotification(notification, { detailsUrl = '', detailsLabel = '', reportLabel = '', notificationApi = null, topWindow = null } = {}) {
    const top = topWindow ?? window.top;
    const api = notificationApi ?? top?.TYPO3?.Notification;
    if (!api || typeof api.showMessage !== 'function') {
        return false;
    }

    // One action per result: the affected pages when checks were skipped, otherwise the report itself.
    const url = notification.details && detailsUrl !== '' ? detailsUrl : String(notification.reportUrl || '');
    const label = notification.details ? detailsLabel : (reportLabel || detailsLabel);
    const actions = url !== '' && label !== ''
        ? [{ label, action: { execute: () => openInContentFrame(top, url) } }]
        : [];

    const duration = NOTIFICATION_DURATION[notification.severity] ?? NOTIFICATION_DURATION.success;
    api.showMessage(
        notification.title,
        notification.message,
        notificationSeverity(notification.severity),
        // Time to read it and reach the action after the view has reloaded with the new results.
        actions.length > 0 ? Math.max(duration, NOTIFICATION_DURATION.withAction) : duration,
        actions
    );

    return true;
}

/**
 * The current module URL pointing at an element of the reloaded view (`#aqg-rendered-coverage`), without AQG's reload
 * marker.
 */
export function moduleUrlWithTarget(href, targetId) {
    try {
        const url = new URL(href, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
        url.searchParams.delete('_aqgReload');
        url.hash = targetId;
        return url.toString();
    } catch {
        return '';
    }
}

/**
 * The module URL without an explicitly selected earlier scan — `remoteScanUid`, opened from a notification or the
 * history, and a comparison — so the view shows the latest result. Used for the reload after a scan completed.
 */
export function withoutSelectedScan(href) {
    const url = new URL(href, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
    ['remoteScanUid', 'compareFromJobId', 'compareToJobId'].forEach((name) => url.searchParams.delete(name));
    return url.toString();
}
