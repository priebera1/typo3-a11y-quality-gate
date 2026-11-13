/**
 * Shared pieces of the AI media UI: labels with English fallbacks (the server sends the translated set), escaping,
 * the outcome ladder (Suggested → Filled in / Saved → Verified), the "What was sent?" disclosure and the one message
 * per error cause.
 */

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export class MediaAiLabels {
    constructor(labels) {
        this.labels = labels && typeof labels === 'object' ? labels : {};
    }

    l(key, fallback, ...args) {
        let text = typeof this.labels[key] === 'string' && this.labels[key] !== '' ? this.labels[key] : fallback;
        for (const arg of args) {
            text = text.replace(/%[sd]/, String(arg));
        }
        return text;
    }

    h(key, fallback, ...args) {
        return escapeHtml(this.l(key, fallback, ...args));
    }
}

export const FIELDS = ['alternative', 'title', 'description'];

export function fieldLabel(labels, field) {
    switch (field) {
        case 'alternative':
            return labels.l('fieldAlternative', 'Alternative text');
        case 'title':
            return labels.l('fieldTitle', 'Title');
        default:
            return labels.l('fieldDescription', 'Description');
    }
}

/** The one message per cause (UX Atlas error catalogue); provider details never reach editors. */
export function errorMessage(labels, code, data = {}) {
    switch (code) {
        case 'rate_limited':
            return labels.l('errorRateLimited', 'Too many requests. You can request another suggestion in %d seconds.', Number(data.retryAfter ?? 30));
        case 'session_expired':
            return labels.l('errorSessionExpired', 'Your TYPO3 session has expired. Log in again in the TYPO3 login window, then try again.');
        case 'privacy_images':
            return labels.l('errorPrivacyImages', 'This site does not allow sending images to AI providers. Nothing was sent.');
        case 'privacy_images_usage':
            return labels.l('errorPrivacyImagesUsage', 'This image is used on a site that does not allow sending images to AI providers. Nothing was sent. Write the text yourself.');
        case 'privacy_usage_unknown':
            return labels.l('errorPrivacyUsageUnknown', 'AQG could not determine on which sites this image is used, so it was not sent. Write the text yourself.');
        case 'privacy_page_title':
            return labels.l('errorPrivacyPageTitle', 'This site does not allow sending page titles to AI providers.');
        case 'no_licence':
            return labels.l('errorNoLicence', 'AI suggestions are part of AQG PRO and Agency.');
        case 'no_permission':
        case 'permission_denied':
        case 'read_only':
            return labels.l('errorPermission', 'You may not change this. Nothing was sent or changed.');
        case 'bulk_not_permitted':
            return labels.l('errorBulk', 'Bulk suggestions are not enabled for your user group. Single files work from the row menu.');
        case 'not_configured':
        case 'site_not_available':
            return labels.l('errorNotConfigured', 'AI suggestions are not set up for this site. Your administrator can enable them.');
        case 'site_required':
            return labels.l('errorSiteRequired', 'Choose the site whose AI settings apply.');
        case 'paused':
            return labels.l('errorPaused', 'AI is paused. The selected model must be re-confirmed by your administrator.');
        case 'budget_reached':
            return labels.l('errorBudgetReached', 'Monthly budget reached. This site has used its AI budget for this month; your administrator can raise the budget.');
        case 'provider_credit':
            return labels.l('errorCredit', 'The provider account for this site has run out of credit. Ask your administrator to add credit, then try again.');
        case 'provider_timeout':
            return labels.l('errorTimeout', 'No answer from the AI provider. Nothing was changed.');
        case 'provider_rate_limited':
        case 'provider_unavailable':
            return labels.l('errorProvider', 'The AI provider is not available right now. Try again in a few minutes.');
        case 'refused':
            return labels.l('errorRefused', 'No safe suggestion. Please write it yourself.');
        case 'invalid_response':
            return labels.l('errorInvalidResponse', 'The AI answer could not be used safely. Nothing was changed.');
        case 'unsupported_file':
            return labels.l('errorUnsupported', 'AQG can suggest metadata for JPEG, PNG, WebP and SVG images only.');
        case 'svg_external_references':
            return labels.l('errorSvgReferences', 'This SVG refers to other files or web addresses, so AQG does not convert or send it. Write the text yourself.');
        case 'svg_not_rasterised':
            return labels.l('errorSvg', 'TYPO3 could not convert this SVG for a suggestion. Nothing was sent.');
        case 'image_too_large':
            return labels.l('errorTooLarge', 'This file is too large to send for a suggestion.');
        case 'image_unavailable':
            return labels.l('errorImageUnavailable', 'The image could not be loaded. Nothing was sent or changed.');
        case 'translation_missing':
            return labels.l('errorTranslationMissing', 'There is no metadata record in this language yet. Create it with TYPO3\'s Translate first.');
        case 'language_unavailable':
            return labels.l('errorLanguage', 'This language does not exist on the chosen site.');
        case 'nothing_to_translate':
            return labels.l('errorNothingToTranslate', 'The default language has no text to translate.');
        case 'record_unsaved':
            return labels.l('errorRecordUnsaved', 'Save the content element once before asking for a suggestion.');
        case 'reference_mismatch':
        case 'stale_reference':
            return labels.l('errorStaleReference', 'The image changed in the form. Save the record, then try again.');
        case 'cancelled':
            return labels.l('cancelled', 'Cancelled.');
        default:
            return labels.l('errorGeneric', 'Something went wrong. Nothing was changed.');
    }
}

/** Refusals, rate limits and privacy blocks are not failures of the image itself. */
export function isManualOutcome(code) {
    return code === 'refused';
}

const STEPS = ['suggested', 'filled', 'saved', 'verified'];

/**
 * The outcome ladder: the shared `.aqg-ladder` component (Scss/components/_ladder.scss, UX Atlas 15 "Honest
 * outcomes"), an ordered list whose current step carries aria-current="step". "filled" is "Filled in, unsaved": AQG
 * only fills form fields, TYPO3 Save makes it "Saved", and Verified only follows AQG's check of the saved record.
 * The state is in the words and the class, never in a glyph.
 */
export function ladderHtml(labels, current, {notVerifiedReason = ''} = {}) {
    const notVerified = notVerifiedReason !== '';
    const names = {
        suggested: labels.l('stepSuggested', 'Suggested'),
        filled: labels.l('stepFilled', 'Filled in, unsaved'),
        saved: labels.l('stepSaved', 'Saved'),
        verified: notVerified ? labels.l('stepNotVerified', 'Not verified') : labels.l('stepVerified', 'Verified'),
    };
    const index = STEPS.indexOf(current);
    return `<ol class="aqg-ladder" aria-label="${labels.h('ladderLabel', 'Progress of this change')}">${STEPS.map((step, i) => {
        const classes = [];
        if (i < index) {
            classes.push('is-done');
        } else if (i === index) {
            classes.push('is-current');
            if (step === 'verified') {
                classes.push(notVerified ? 'is-notverified' : 'is-verified');
            }
        }
        const attributes = `${classes.length > 0 ? ` class="${classes.join(' ')}"` : ''}${i === index ? ' aria-current="step"' : ''}`;
        return `<li${attributes}><span class="aqg-ladder__dot" aria-hidden="true"></span><span>${escapeHtml(names[step])}</span></li>`;
    }).join('')}</ol>`;
}

const SENT_KEYS = {
    image: ['sentImage', 'Image (scaled down)'],
    fileName: ['sentFileName', 'File name'],
    folderName: ['sentFolderName', 'Folder name'],
    metadataLanguage: ['sentLanguage', 'Language'],
    language: ['sentLanguage', 'Language'],
    existingValues: ['sentExisting', 'Existing metadata'],
    svgLabels: ['sentSvgLabels', 'Text labels of the SVG'],
    elementHeader: ['sentHeader', 'Header of this element'],
    elementText: ['sentText', 'First paragraph of this element'],
    fileDefault: ['sentFileDefault', 'File default alternative text'],
    pageTitle: ['sentPageTitle', 'Page title'],
    linkTarget: ['sentLink', 'Link target (page title or host)'],
    defaultLanguageText: ['sentDefaultText', 'Text of the default language (no image)'],
    filePath: ['notSentPath', 'File path'],
    userNames: ['notSentUsers', 'User names'],
    otherFiles: ['notSentOtherFiles', 'Other files and their uses'],
};

function listText(labels, keys) {
    return (Array.isArray(keys) ? keys : [])
        .map((key) => SENT_KEYS[key] ? labels.l(SENT_KEYS[key][0], SENT_KEYS[key][1]) : '')
        .filter((text) => text !== '')
        .join(', ');
}

/** Provider, model, token usage and the "What was sent?" disclosure of one suggestion. */
export function provenanceHtml(labels, data) {
    const tokens = Number(data?.usage?.inputTokens ?? 0) + Number(data?.usage?.outputTokens ?? 0);
    const tokenText = tokens > 0 ? labels.h('tokens', '~ %d tokens', tokens.toLocaleString(document.documentElement.lang || undefined)) : '';
    // The model's display name as AI settings show it ("Claude Haiku 5.5"), not its technical id.
    const provider = [data?.providerLabel, data?.modelLabel || data?.model].filter(Boolean).map(escapeHtml).join(' · ');
    return `<div class="aqg-media-provenance">
        <span class="aqg-media-provenance__model">${provider}</span>${tokenText !== '' ? `<span class="aqg-media-provenance__tokens">${tokenText}</span>` : ''}
        <details class="aqg-media-provenance__sent">
            <summary>${labels.h('whatWasSent', 'What was sent?')}</summary>
            <p>${escapeHtml(listText(labels, data?.sent))}</p>
            <p>${labels.h('notSent', 'Not sent:')} ${escapeHtml(listText(labels, data?.notSent))}</p>
        </details>
    </div>`;
}

/** A polite live region of the page, created once. */
export function announcer(scope = document.body) {
    let region = scope.querySelector(':scope > .aqg-media-live');
    if (!region) {
        region = document.createElement('div');
        region.className = 'aqg-media-live visually-hidden';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        scope.appendChild(region);
    }
    return (message) => {
        region.textContent = '';
        window.setTimeout(() => {
            region.textContent = message;
        }, 50);
    };
}
