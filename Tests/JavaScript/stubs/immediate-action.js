// TYPO3's ImmediateAction: keeps the callback so a test can run the action.
export default class ImmediateAction {
    constructor(callback) {
        this.callback = callback;
    }
}
