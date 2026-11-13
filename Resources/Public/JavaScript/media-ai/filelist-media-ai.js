import {postMediaAi} from '@priebera/a11y-quality-gate/media-ai/media-ai-client.js';
import {MediaAiLabels} from '@priebera/a11y-quality-gate/media-ai/media-ai-common.js';

const settings = globalThis.TYPO3?.settings?.aqgMediaAi ?? {};
const labels = new MediaAiLabels(settings.labels);
const UPLOAD_LIMIT = 5;
const ROW_SELECTOR = '[data-multi-record-selection-element="true"][data-filelist-type="file"][data-filelist-uid]';

/** A backend URL from AQG's own pre-flight, with the way back to this Filelist view; '' for anything else. */
export function withReturnUrl(url, location = window.location) {
    if (typeof url !== 'string' || !/^(\/|https?:\/\/)/i.test(url)) {
        return '';
    }
    return `${url}${url.includes('?') ? '&' : '?'}returnUrl=${encodeURIComponent(location.pathname + location.search)}`;
}

/**
 * AI metadata in the Filelist (UX Atlas screen 04). The row menu entry is a plain link into TYPO3's metadata form
 * (rendered server-side), where the AQG wand suggests per field and TYPO3 Save stores the values (04 A).
 *
 * AQG has no write path into sys_file_metadata (Atlas open decision #1 is not taken), so there is no multi-file apply:
 * for a selection of several files the AQG bar only says so, and after an upload one notification names the new images
 * without alternative text and opens their metadata forms one by one. Neither sends anything to an AI provider.
 */
class FilelistMediaAi {
    constructor() {
        this.bar = null;
        this.selectionTimer = 0;
        this.uploaded = new Set();
        this.uploadTimer = 0;
    }

    init() {
        // TYPO3's "check all/none/toggle" dispatch this per checkbox; the capture phase also sees non-bubbling events.
        document.addEventListener('multiRecordSelection:checkbox:state:changed', () => this.scheduleSelection(), true);
        document.addEventListener('change', (event) => {
            if (event.target instanceof Element && event.target.matches('.t3js-multi-record-selection-check')) {
                this.scheduleSelection();
            }
        }, true);
        document.addEventListener('uploadSuccess', (event) => this.onUploadSuccess(event), true);
    }

    selectedFileUids() {
        return [...document.querySelectorAll(ROW_SELECTOR)]
            .filter((row) => row.querySelector('.t3js-multi-record-selection-check')?.checked === true)
            .map((row) => Number(row.getAttribute('data-filelist-uid')))
            .filter((uid) => uid > 0);
    }

    scheduleSelection() {
        window.clearTimeout(this.selectionTimer);
        this.selectionTimer = window.setTimeout(() => this.updateSelection(), 350);
    }

    /** Several selected files: say honestly that AQG applies AI metadata one file at a time. No request is made. */
    updateSelection() {
        const count = this.selectedFileUids().length;
        if (count < 2) {
            this.bar?.remove();
            this.bar = null;
            return;
        }
        const bar = this.ensureBar();
        bar.innerHTML = `<span class="aqg-media-bulkbar__count">${labels.h('selected', '%d selected', count)}</span>
            <span class="aqg-media-bulkbar__scope">${labels.h('bulkUnavailable', 'AQG cannot apply AI metadata to several files at once. Open each image with "Suggest metadata with AI" and save it with TYPO3.')}</span>`;
    }

    ensureBar() {
        if (this.bar?.isConnected) {
            return this.bar;
        }
        const bar = document.createElement('div');
        bar.className = 'aqg-media-bulkbar';
        bar.setAttribute('role', 'status');
        const anchor = document.querySelector('.multi-record-selection-actions-wrapper')
            ?? document.querySelector('.t3js-multi-record-selection-actions')?.parentElement
            ?? document.querySelector('.t3-filelist-container, #filelist');
        anchor?.insertAdjacentElement('afterend', bar);
        this.bar = bar;
        return bar;
    }

    onUploadSuccess(event) {
        const detail = Array.isArray(event.detail) ? event.detail : [];
        for (const upload of detail[1]?.upload ?? []) {
            const uid = Number(upload?.uid ?? 0);
            if (uid > 0) {
                this.uploaded.add(uid);
            }
        }
        window.clearTimeout(this.uploadTimer);
        this.uploadTimer = window.setTimeout(() => this.offerUploadFollowUp(), 3500);
    }

    /** One notification after an upload batch: which new images have no alternative text, and a link to each form. */
    async offerUploadFollowUp() {
        const uids = [...this.uploaded];
        this.uploaded.clear();
        if (uids.length === 0) {
            return;
        }
        const response = await postMediaAi('fileState', {fileUids: uids.slice(0, UPLOAD_LIMIT), languageUid: 0, context: 'upload'});
        if (!response.ok || (response.data.sites ?? []).every((site) => site.state !== 'ready')) {
            return;
        }
        const missing = (response.data.files ?? []).filter((file) => file.status === 'eligible'
            && (file.editableFields ?? []).includes('alternative')
            && String(file.metadata?.values?.alternative ?? '').trim() === ''
            && withReturnUrl(file.editUrl) !== '');
        if (missing.length === 0) {
            return;
        }
        const [{default: Notification}, {default: ImmediateAction}] = await Promise.all([
            import('@typo3/backend/notification.js'),
            import('@typo3/backend/action-button/immediate-action.js'),
        ]);
        let message = missing.length === 1
            ? labels.l('uploadOpenHintOne', '1 image has no alternative text. Open it: the wand in its metadata form suggests a text, and TYPO3 Save stores it.')
            : labels.l('uploadOpenHint', '%d images have no alternative text. Open each one: the wand in its metadata form suggests a text, and TYPO3 Save stores it.', missing.length);
        if (uids.length > UPLOAD_LIMIT) {
            message += ' ' + labels.l('uploadFirstFive', 'Only the first five uploads were checked.');
        }
        Notification.info(uids.length === 1 ? labels.l('uploadTitleOne', '1 file uploaded') : labels.l('uploadTitle', '%d files uploaded', uids.length), message, 20, [
            ...missing.map((file) => ({
                label: labels.l('uploadOpenFile', 'Open metadata: %s', String(file.name ?? '')),
                action: new ImmediateAction(() => {
                    window.location.href = withReturnUrl(file.editUrl);
                }),
            })),
            {
                label: labels.l('notNow', 'Not now'),
                action: new ImmediateAction(() => {}),
            },
        ]);
    }
}

new FilelistMediaAi().init();
