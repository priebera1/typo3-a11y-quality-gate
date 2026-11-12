/**
 * Callback of the context menu entry "Suggest metadata with AI" (UX Atlas 04 A). It opens TYPO3's metadata form of the
 * file in the content frame, where the AQG wand beside each field suggests a value and TYPO3 Save stores it. AQG has
 * no write path into the file metadata, so this entry never sends anything itself.
 */
function returnUrl(top) {
    try {
        const location = top.list_frame?.document?.location;
        return location ? location.pathname + location.search : '';
    } catch {
        // A frame of another origin is never ours.
        return '';
    }
}

export default class MediaAiContextMenuActions {
    static openMetadataForm(table, uid, dataset) {
        const url = String(dataset?.editUrl ?? '');
        // Only TYPO3's own backend URL from the server; never anything that would run script.
        if (url === '' || !/^(\/|https?:\/\/)/i.test(url)) {
            return;
        }
        const top = globalThis.top ?? window;
        const back = returnUrl(top);
        const target = back !== '' ? `${url}${url.includes('?') ? '&' : '?'}returnUrl=${encodeURIComponent(back)}` : url;
        const container = top.TYPO3?.Backend?.ContentContainer;
        if (container && typeof container.setUrl === 'function') {
            container.setUrl(target);
        } else {
            window.location.href = target;
        }
    }
}
