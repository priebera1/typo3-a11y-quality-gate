/**
 * Highlighted / Original switch of a Frontend page screenshot (audit C15). Both images are the same capture and share
 * one coordinate space; the switch replaces the shown image and what the enlarge button opens.
 *
 * @param {HTMLElement} button a `[data-action="a11y-screenshot-variant"]` button
 */
export function switchScreenshotVariant(button) {
    const url = String(button?.dataset?.variantUrl || '').trim();
    const shot = button?.closest('.aqg-shot');
    if (url === '' || !(shot instanceof HTMLElement)) {
        return;
    }

    const trigger = shot.querySelector('[data-action="a11y-open-screenshot-modal"]');
    const image = trigger?.querySelector('img');
    if (image instanceof HTMLImageElement) {
        image.src = url;
    }
    if (trigger instanceof HTMLElement) {
        trigger.dataset.imageUrl = url;
    }
    shot.querySelectorAll('[data-action="a11y-screenshot-variant"]').forEach((candidate) => {
        candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false');
    });
}
