import { A11yFreeBackendModule } from './free/free-module.js';

const bootstrapA11yBackendModule = () => {
    new A11yFreeBackendModule();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}