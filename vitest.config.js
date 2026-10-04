import {fileURLToPath, URL} from 'node:url';
import {defineConfig} from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            // AQG modules import each other by their import-map name, so TYPO3 adds its cache-busting version.
            '@priebera/a11y-quality-gate/': fileURLToPath(new URL('./Resources/Public/JavaScript/', import.meta.url)),
            '@typo3/core/ajax/ajax-request.js': fileURLToPath(
                new URL('./Tests/JavaScript/stubs/ajax-request.js', import.meta.url),
            ),
            '@typo3/backend/modal.js': fileURLToPath(
                new URL('./Tests/JavaScript/stubs/modal.js', import.meta.url),
            ),
            '@typo3/backend/severity.js': fileURLToPath(
                new URL('./Tests/JavaScript/stubs/severity.js', import.meta.url),
            ),
        },
    },
});
