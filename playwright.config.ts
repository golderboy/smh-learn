import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import os from 'node:os';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: 'list',
    expect: { timeout: 15000 },
    outputDir: path.join(os.tmpdir(), 'smh-learning-playwright-results'),
    use: {
        actionTimeout: 15000,
        navigationTimeout: 30000,
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8088',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'desktop-chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'tablet-chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 768, height: 1024 } },
        },
        {
            name: 'mobile-chromium',
            use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } },
        },
    ],
});
