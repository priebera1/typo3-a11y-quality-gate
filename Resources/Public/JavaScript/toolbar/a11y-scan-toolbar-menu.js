import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Icons from '@typo3/backend/icons.js';
import Viewport from '@typo3/backend/viewport.js';

let A11yToolbarSelector;
(function (selector) {
    selector.element = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem';
    selector.icon = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-icon .t3js-icon';
    selector.menu = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .dropdown-menu';
    selector.dropdownToggle = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-link.dropdown-toggle';
})(A11yToolbarSelector || (A11yToolbarSelector = {}));

class A11yScanToolbarMenu {
    constructor() {
        this.originalIcon = null;

        document.querySelector(A11yToolbarSelector.dropdownToggle)?.addEventListener('click', () => {
            this.updateMenu();
        });

        Viewport.Topbar.Toolbar.registerEvent(() => {
            this.updateMenu();
        });
    }

    async updateMenu() {
        const iconElement = document.querySelector(A11yToolbarSelector.icon);
        if (!iconElement) {
            return;
        }

        if (!this.originalIcon) {
            this.originalIcon = iconElement.cloneNode(true);
        }

        await this.showSpinnerIcon();

        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.a11y_toolbar_render).get();
            const html = await response.resolve();

            const menu = document.querySelector(A11yToolbarSelector.menu);
            if (menu) {
                menu.innerHTML = html;
            }
        } catch (error) {
            console.warn('[A11Y] Toolbar menu refresh failed', error);
        } finally {
            this.restoreOriginalIcon();
        }
    }

    async showSpinnerIcon() {
        const currentIcon = document.querySelector(A11yToolbarSelector.icon);
        if (!currentIcon) {
            return;
        }

        const spinnerIcon = await Icons.getIcon('spinner-circle', Icons.sizes.small);
        currentIcon.replaceWith(document.createRange().createContextualFragment(spinnerIcon));
    }

    restoreOriginalIcon() {
        const currentIcon = document.querySelector(A11yToolbarSelector.icon);
        if (!currentIcon || !this.originalIcon) {
            return;
        }

        currentIcon.replaceWith(this.originalIcon.cloneNode(true));
    }
}

export default new A11yScanToolbarMenu();