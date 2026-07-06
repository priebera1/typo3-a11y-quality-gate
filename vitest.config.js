import {fileURLToPath, URL} from 'node:url';
import {defineConfig} from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@typo3/core/ajax/ajax-request.js': fileURLToPath(
                new URL('./Tests/JavaScript/stubs/ajax-request.js', import.meta.url),
            ),
        },
    },
});
