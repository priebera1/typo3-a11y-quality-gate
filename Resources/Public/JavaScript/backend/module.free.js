import { A11yFreeBackendModule } from '@priebera/a11y-quality-gate/backend/free/free-module.js';
import { initializeLocalPageScan } from '@priebera/a11y-quality-gate/backend/core/local-page-scan.js';
import { initializeScrollableTables } from '@priebera/a11y-quality-gate/backend/core/scrollable-tables.js';

const bootstrapA11yBackendModule = () => {
    const module = new A11yFreeBackendModule();
    initializeLocalPageScan(module);
    initializeScrollableTables();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}