/**
 * Where the finding popover of an editor goes (UX Atlas 02 · A and 02 · F, audit F-03 / F-38). Pure geometry, so the
 * CKEditor plugin and the tests share one decision:
 *
 * - narrow editor (< 560 px): a bottom sheet docked to the window bottom (at most 64 % of its height); the host scrolls
 *   the marked text into the space above the sheet;
 * - otherwise below the marker when the whole popover fits there, above it when it fits there, else shifted into the
 *   visible area (it may then cover the marker's row, never the window edge); only a popover taller than the visible
 *   area gets a max-height, and its action row stays sticky at the bottom, so the primary actions are always visible.
 *
 * All rectangles are viewport coordinates ({top, bottom, width}); `top` of the result is relative to the host (the
 * popover is absolutely positioned inside it).
 */
export const SHEET_BREAKPOINT = 560;
export const SHEET_MAX_HEIGHT_RATIO = 0.64;

export function computePopoverPlacement({
    anchorRect,
    hostRect,
    popoverHeight,
    viewportHeight,
    topLimit = 0,
    fromSummary = false,
    gap = 4,
    margin = 12,
}) {
    if (hostRect.width < SHEET_BREAKPOINT) {
        return {mode: 'sheet', top: null, maxHeight: Math.round(viewportHeight * SHEET_MAX_HEIGHT_RATIO)};
    }

    const visibleTop = Math.max(0, topLimit) + margin;
    const visibleBottom = viewportHeight - margin;
    const available = Math.max(160, visibleBottom - visibleTop);
    const height = Math.min(popoverHeight, available);
    const maxHeight = popoverHeight > available ? Math.round(available) : null;
    const toHost = (viewportTop) => Math.round(viewportTop - hostRect.top);

    // "Show issues" opens the list at the top of the editing area unless that runs out of the window.
    const preferredTop = fromSummary ? hostRect.top + gap : anchorRect.bottom + gap;
    if (preferredTop + height <= visibleBottom && preferredTop >= visibleTop) {
        return {mode: 'below', top: toHost(preferredTop), maxHeight};
    }

    const aboveTop = anchorRect.top - gap - height;
    if (!fromSummary && aboveTop >= visibleTop) {
        return {mode: 'above', top: toHost(aboveTop), maxHeight};
    }

    // Neither side has room for the whole popover: keep all of it in view.
    const shifted = Math.min(Math.max(preferredTop, visibleTop), visibleBottom - height);
    return {mode: 'shift', top: toHost(Math.max(visibleTop, shifted)), maxHeight};
}

/**
 * How far the scroll container must move so that `targetRect` sits fully above a bottom sheet of `sheetHeight`
 * (0: it already does). Positive values scroll down.
 */
export function sheetScrollDelta({targetRect, viewportHeight, sheetHeight, topLimit = 0, margin = 12}) {
    const bottomLimit = viewportHeight - sheetHeight - margin;
    const topBound = Math.max(0, topLimit) + margin;
    if (targetRect.bottom > bottomLimit) {
        // Bring the target down to just above the sheet, but never past the visible top.
        return Math.round(Math.min(targetRect.bottom - bottomLimit, targetRect.top - topBound));
    }
    if (targetRect.top < topBound) {
        return Math.round(targetRect.top - topBound);
    }
    return 0;
}
