import { FREE_SELECTORS } from '@priebera/a11y-quality-gate/backend/core/constants.js';

const initializedRoots = new WeakMap();

export const initializeLocalPageScan = (module, root = document) => {
    if (!module || typeof module.handleRescan !== 'function' || !root?.addEventListener) {
        return false;
    }

    const existingState = initializedRoots.get(root);
    if (existingState) {
        existingState.module = module;
        return false;
    }

    const state = {
        module,
        activeButtons: new WeakSet(),
    };

    const handleClick = async (event) => {
        const target = event.target instanceof Element
            ? event.target
            : event.target?.parentElement;
        const button = target?.closest?.(FREE_SELECTORS.rescanButton);

        if (!(button instanceof HTMLButtonElement) || !root.contains(button)) {
            return;
        }

        event.preventDefault();

        // aria-disabled: a row's icon button stays focusable while another content scan runs.
        if (button.disabled || button.getAttribute('aria-disabled') === 'true' || state.activeButtons.has(button)) {
            return;
        }

        state.activeButtons.add(button);

        try {
            await state.module.handleRescan(button);
        } finally {
            state.activeButtons.delete(button);
        }
    };

    root.addEventListener('click', handleClick, true);
    initializedRoots.set(root, state);

    return true;
};
