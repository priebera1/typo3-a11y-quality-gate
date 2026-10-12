/**
 * TYPO3 notification severities (TYPO3\CMS\Core\Type\ContextualFeedbackSeverity / SeverityEnum):
 * notice -2, info -1, ok 0, warning 1, error 2. Shared by the module and the Page module so a failed scan is never
 * shown as a notice and a completed one never as a warning.
 */
export const NOTIFICATION_SEVERITY = Object.freeze({
    notice: -2,
    info: -1,
    success: 0,
    ok: 0,
    warning: 1,
    error: 2,
});

export function notificationSeverity(type) {
    return NOTIFICATION_SEVERITY[type] ?? NOTIFICATION_SEVERITY.info;
}
