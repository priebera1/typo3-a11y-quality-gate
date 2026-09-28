let postHandler = null;
let getHandler = null;

export const setAjaxGetHandler = (handler) => {
    getHandler = handler;
};

export const resetAjaxGetHandler = () => {
    getHandler = null;
};

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

    get() {
        if (typeof getHandler !== 'function') {
            throw new Error('Missing AjaxRequest GET test handler.');
        }

        return getHandler(this.endpoint);
    }
}
