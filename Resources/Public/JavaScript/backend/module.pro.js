import { A11yProBackendModule } from './pro/pro-module.js';
import { initializeLocalPageScan } from './core/local-page-scan.js';

const bootstrapA11yBackendModule = () => {
    const module = new A11yProBackendModule();
    initializeLocalPageScan(module);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}