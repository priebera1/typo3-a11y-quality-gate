import { A11yProBackendModule } from '@priebera/a11y-quality-gate/backend/pro/pro-module.js';
import { initializeLocalPageScan } from '@priebera/a11y-quality-gate/backend/core/local-page-scan.js';
import { initializeScrollableTables } from '@priebera/a11y-quality-gate/backend/core/scrollable-tables.js';
import { initializePriorityFixOwner } from '@priebera/a11y-quality-gate/backend/pro/priority-fix-owner.js';

const bootstrapA11yBackendModule = () => {
    const module = new A11yProBackendModule();
    initializeLocalPageScan(module);
    initializeScrollableTables();
    initializePriorityFixOwner();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}