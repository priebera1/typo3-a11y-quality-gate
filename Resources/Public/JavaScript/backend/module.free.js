import { A11yFreeBackendModule } from './free/free-module.js';
import { initializeLocalPageScan } from './core/local-page-scan.js';

const bootstrapA11yBackendModule = () => {
    const module = new A11yFreeBackendModule();
    initializeLocalPageScan(module);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}