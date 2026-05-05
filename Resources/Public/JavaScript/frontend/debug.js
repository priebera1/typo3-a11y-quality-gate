class AqgFrontendDebug {
    constructor() {
        this.highlightTimeout = null;
        this.maxRetryAttempts = 6;
        this.retryDelay = 500;
        this.maxScrollSteps = 8;
        this.scrollStepRatio = 0.8;
        this.scrollDelay = 450;
        this.observerTimeout = 2500;
        this.mutationObserver = null;

        this.initHighlightFromUrl();
    }

    initHighlightFromUrl() {
        const isDebug = document.body?.dataset?.aqgDebug === '1';
        if (!isDebug) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const highlightParam = params.get('aqgh');

        if (!highlightParam) {
            return;
        }

        const uids = highlightParam
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean);

        if (uids.length === 0) {
            return;
        }

        window.setTimeout(() => {
            this.startHighlightFlow(uids);
        }, 700);
    }

    async startHighlightFlow(uids) {
        const foundImmediately = this.highlightByUids(uids);
        if (foundImmediately > 0) {
            return;
        }

        const foundByObserver = await this.waitForTargetsViaObserver(uids, this.observerTimeout);
        if (foundByObserver > 0) {
            return;
        }

        const foundAfterRetry = await this.tryHighlightWithRetry(uids);
        if (foundAfterRetry > 0) {
            return;
        }

        const foundAfterScroll = await this.progressiveScrollSearch(uids);
        if (foundAfterScroll > 0) {
            return;
        }

        console.warn('[AQG] Elements not found for uids', uids);
    }

    async tryHighlightWithRetry(uids) {
        for (let attempt = 0; attempt < this.maxRetryAttempts; attempt++) {
            await this.wait(this.retryDelay);

            const foundCount = this.highlightByUids(uids);
            if (foundCount > 0) {
                return foundCount;
            }
        }

        return 0;
    }

    async progressiveScrollSearch(uids) {
        const originalScrollY = window.scrollY;
        const maxScrollableY = Math.max(
            0,
            document.documentElement.scrollHeight - window.innerHeight
        );

        for (let step = 1; step <= this.maxScrollSteps; step++) {
            const nextY = Math.min(
                maxScrollableY,
                Math.round((maxScrollableY / this.maxScrollSteps) * step)
            );

            window.scrollTo({
                top: nextY,
                behavior: 'auto',
            });

            const foundImmediately = this.highlightByUids(uids, false);
            if (foundImmediately > 0) {
                this.scrollHighlightedElementIntoView();
                return foundImmediately;
            }

            const foundByObserver = await this.waitForTargetsViaObserver(uids, this.scrollDelay);
            if (foundByObserver > 0) {
                this.scrollHighlightedElementIntoView();
                return foundByObserver;
            }
        }

        window.scrollTo({
            top: originalScrollY,
            behavior: 'auto',
        });

        return 0;
    }

    async waitForTargetsViaObserver(uids, timeoutMs) {
        const foundBeforeObserve = this.highlightByUids(uids, false);
        if (foundBeforeObserve > 0) {
            return foundBeforeObserve;
        }

        return await new Promise((resolve) => {
            let resolved = false;

            const finish = (count = 0) => {
                if (resolved) {
                    return;
                }

                resolved = true;
                this.disconnectObserver();
                resolve(count);
            };

            const observerTarget = document.body || document.documentElement;
            if (!observerTarget) {
                finish(0);
                return;
            }

            this.disconnectObserver();

            this.mutationObserver = new MutationObserver(() => {
                const foundCount = this.highlightByUids(uids, false);
                if (foundCount > 0) {
                    finish(foundCount);
                }
            });

            this.mutationObserver.observe(observerTarget, {
                childList: true,
                subtree: true,
            });

            window.setTimeout(() => {
                finish(0);
            }, timeoutMs);
        });
    }

    disconnectObserver() {
        if (this.mutationObserver) {
            this.mutationObserver.disconnect();
            this.mutationObserver = null;
        }
    }

    highlightByUids(uids, scrollToFirst = true) {
        const targets = uids
            .map((uid) => document.querySelector(`.aqg-marker[data-aqg-uid="${uid}"]`))
            .filter(Boolean);

        if (targets.length === 0) {
            return 0;
        }

        this.removeExistingHighlight();

        targets.forEach((target, index) => {
            this.applyHighlight(target, index + 1);
        });

        if (scrollToFirst) {
            targets[0].scrollIntoView({
                behavior: 'auto',
                block: 'center',
                inline: 'nearest',
            });
        }

        if (this.highlightTimeout) {
            window.clearTimeout(this.highlightTimeout);
        }

        this.highlightTimeout = window.setTimeout(() => {
            this.removeExistingHighlight();
        }, 8000);

        return targets.length;
    }

    scrollHighlightedElementIntoView() {
        const firstTarget = document.querySelector('[data-aqg-highlight-active="1"]');
        if (!firstTarget) {
            return;
        }

        firstTarget.scrollIntoView({
            behavior: 'auto',
            block: 'center',
            inline: 'nearest',
        });
    }

    applyHighlight(target, number = null) {
        target.setAttribute('data-aqg-highlight-active', '1');
        target.style.outline = '4px solid #dc3545';
        target.style.backgroundColor = 'rgba(220, 53, 69, 0.08)';
        target.style.boxShadow = '0 0 0 4px rgba(220, 53, 69, 0.15)';
        target.style.transition = 'all 0.3s ease';

        const computedPosition = window.getComputedStyle(target).position;
        if (computedPosition === 'static') {
            target.style.position = 'relative';
        }

        const badge = document.createElement('div');
        badge.className = 'aqg-highlight-badge';
        badge.textContent = number ? `Accessibility issue #${number}` : 'Accessibility issue';
        badge.style.position = 'absolute';
        badge.style.top = '4px';
        badge.style.right = '4px';
        badge.style.zIndex = '999999';
        badge.style.padding = '2px 6px';
        badge.style.fontSize = '11px';
        badge.style.fontWeight = '600';
        badge.style.lineHeight = '1.4';
        badge.style.color = '#fff';
        badge.style.backgroundColor = '#dc3545';
        badge.style.borderRadius = '3px';
        badge.style.pointerEvents = 'none';

        target.appendChild(badge);
    }

    removeExistingHighlight() {
        document.querySelectorAll('[data-aqg-highlight-active="1"]').forEach((element) => {
            element.style.outline = '';
            element.style.backgroundColor = '';
            element.style.boxShadow = '';
            element.removeAttribute('data-aqg-highlight-active');
        });

        document.querySelectorAll('.aqg-highlight-badge').forEach((badge) => {
            badge.remove();
        });

        if (this.highlightTimeout) {
            window.clearTimeout(this.highlightTimeout);
            this.highlightTimeout = null;
        }

        this.disconnectObserver();
    }

    wait(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }
}

const bootstrapAqgFrontendDebug = () => {
    new AqgFrontendDebug();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapAqgFrontendDebug, { once: true });
} else {
    bootstrapAqgFrontendDebug();
}