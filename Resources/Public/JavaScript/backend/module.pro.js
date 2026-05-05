import { A11yProBackendModule } from './pro/pro-module.js';

const bootstrapA11yBackendModule = () => {
    new A11yProBackendModule();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}