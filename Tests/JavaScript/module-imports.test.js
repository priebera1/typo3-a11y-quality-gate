import {readdirSync, readFileSync, statSync} from 'node:fs';
import {join, relative} from 'node:path';
import {fileURLToPath} from 'node:url';
import {describe, expect, it} from 'vitest';

// TYPO3 adds its cache-busting version only to modules it resolves through the import map. A relative import
// (`./pro/pro-module.js`) is fetched by plain URL without that version, so after an upgrade a browser could combine a
// new entry module with week-old nested modules. AQG modules therefore import each other by import-map name.
const root = fileURLToPath(new URL('../../Resources/Public/JavaScript/', import.meta.url));

const files = (dir) => readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);
    return statSync(path).isDirectory() ? files(path) : (path.endsWith('.js') ? [path] : []);
});

describe('AQG JavaScript module imports', () => {
    it('never imports a sibling module by relative URL', () => {
        const relativeImports = files(root).flatMap((file) => {
            const source = readFileSync(file, 'utf8');
            return [...source.matchAll(/(?:from\s+|import\s*\(\s*)['"](\.\.?\/[^'"]+)['"]/g)]
                .map((match) => `${relative(root, file)}: ${match[1]}`);
        });

        expect(relativeImports).toEqual([]);
    });

    it('imports AQG modules only by names that exist', () => {
        const missing = files(root).flatMap((file) => {
            const source = readFileSync(file, 'utf8');
            return [...source.matchAll(/from\s+['"]@priebera\/a11y-quality-gate\/([^'"]+)['"]/g)]
                .filter((match) => {
                    try {
                        return !statSync(join(root, match[1])).isFile();
                    } catch {
                        return true;
                    }
                })
                .map((match) => `${relative(root, file)}: ${match[1]}`);
        });

        expect(missing).toEqual([]);
    });
});
