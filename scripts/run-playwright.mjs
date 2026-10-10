import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cli = require.resolve('@playwright/test/cli');
const result = spawnSync(process.execPath, [cli, ...process.argv.slice(2)], {
    cwd: projectRoot,
    stdio: 'inherit',
    env: {
        ...process.env,
        PLAYWRIGHT_BROWSERS_PATH:
            process.env.PLAYWRIGHT_BROWSERS_PATH ??
            path.join(projectRoot, '.cache', 'ms-playwright'),
    },
});
if (result.error) console.error(result.error.message);
process.exit(result.status ?? 1);
