let postHandler = null;

export const setAjaxPostHandler = (handler) => {
    postHandler = handler;
};

export const resetAjaxPostHandler = () => {
    postHandler = null;
};

export default class AjaxRequest {
    constructor(endpoint) {
        this.endpoint = endpoint;
    }

    post(payload) {
        if (typeof postHandler !== 'function') {
            throw new Error('Missing AjaxRequest test handler.');
        }

        return postHandler(this.endpoint, payload);
    }
}
