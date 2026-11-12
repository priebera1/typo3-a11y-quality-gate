/**
 * Overview page search (Content scan and Frontend scan tables).
 *
 * The search stays a server search: the module renders the filtered table, counts and pagination exactly as a
 * normal page load would. Two things made typing feel slow and lose keystrokes:
 * - the whole module root was replaced, including the search field being typed into, so focus fell to <body>
 *   after every update and the next keys went nowhere;
 * - the request of a replaced field could no longer be aborted, so an older answer could still overwrite a newer one.
 *
 * syncPreservingForm() applies the new render everywhere except inside the form the person is typing in, and
 * SearchRequestSequencer keeps only the newest answer per search field name.
 */

/**
 * Replaces `current` with `next` except for the subtree `keep` and the attributes of its ancestors. The counterpart
 * of `keep` in `next` is found by its id (or the id of its first field with one); every other node along the way is
 * taken from the new render. Returns false when no counterpart exists or the ancestor chains differ, so the caller
 * can fall back to a full replace.
 */
export function syncPreservingForm(current, next, keep) {
    if (!current || !next || !keep || !current.contains(keep)) {
        return false;
    }
    const counterpart = findCounterpart(next, keep);
    if (!counterpart) {
        return false;
    }
    const currentChain = chain(current, keep);
    const nextChain = chain(next, counterpart);
    if (currentChain.length !== nextChain.length || currentChain.some((node, index) => node.tagName !== nextChain[index].tagName)) {
        return false;
    }
    // Walk from the root down to the kept form; at each level take the siblings of the kept branch from the new render.
    for (let level = 0; level < currentChain.length - 1; level++) {
        replaceSiblings(currentChain[level], currentChain[level + 1], nextChain[level], nextChain[level + 1]);
    }

    return true;
}

function findCounterpart(next, keep) {
    // getAttribute(): on a form, `.id` returns a field named "id" (the Overview forms carry the page id that way).
    const ownId = keep.getAttribute('id') || '';
    const id = ownId || keep.querySelector('[id]')?.getAttribute('id') || '';
    if (id === '') {
        return null;
    }
    const match = Array.from(next.querySelectorAll('[id]')).find((element) => element.getAttribute('id') === id);
    if (!match) {
        return null;
    }

    return ownId ? match : match.closest(keep.tagName.toLowerCase());
}

/** The elements from `root` down to `node`, both included. */
function chain(root, node) {
    const nodes = [];
    for (let element = node; element; element = element.parentElement) {
        nodes.unshift(element);
        if (element === root) {
            return nodes;
        }
    }

    return [];
}

// The ancestors of the form keep their attributes: they carry client state such as the selected source tab (the
// server renders the Frontend scan panel `hidden`), and hiding them would drop the focus.
function replaceSiblings(current, keptChild, next, nextKept) {
    Array.from(current.childNodes).forEach((node) => {
        if (node !== keptChild) {
            node.remove();
        }
    });
    let afterKept = false;
    Array.from(next.childNodes).forEach((node) => {
        if (node === nextKept) {
            afterKept = true;
            return;
        }
        if (afterKept) {
            current.appendChild(node);
        } else {
            current.insertBefore(node, keptChild);
        }
    });
}

/**
 * Keeps one in-flight request per search field name and drops any answer that is not the newest one.
 */
export class SearchRequestSequencer {
    constructor() {
        this.latest = new Map();
        this.controllers = new Map();
        this.appliedUrls = new Map();
    }

    /**
     * @returns {{signal: AbortSignal, isCurrent: () => boolean, done: (appliedUrl: ?string) => void} | null}
     *   null when that URL is already shown and nothing is in flight
     */
    begin(name, url) {
        if (this.appliedUrls.get(name) === url && !this.controllers.has(name)) {
            return null;
        }
        this.controllers.get(name)?.abort();
        const controller = new AbortController();
        const ticket = (this.latest.get(name) ?? 0) + 1;
        this.latest.set(name, ticket);
        this.controllers.set(name, controller);

        return {
            signal: controller.signal,
            isCurrent: () => this.latest.get(name) === ticket,
            done: (appliedUrl) => {
                if (this.latest.get(name) === ticket) {
                    this.controllers.delete(name);
                    if (appliedUrl) {
                        this.appliedUrls.set(name, appliedUrl);
                    }
                }
            },
        };
    }

    /** Records the URL the page was loaded with, the first time a field searches. */
    markShownOnce(name, url) {
        if (!this.appliedUrls.has(name)) {
            this.appliedUrls.set(name, new URL(url).toString());
        }
    }
}
