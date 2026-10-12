// Records each modal: `advanced()` returns an event target standing in for TYPO3's modal element.
export const modalCalls = [];

export default {
    sizes: {small: 'small', default: 'default', medium: 'medium', large: 'large', full: 'full'},
    advanced: (configuration) => {
        const element = new EventTarget();
        modalCalls.push({configuration, element});
        return element;
    },
    dismiss: () => undefined,
};
