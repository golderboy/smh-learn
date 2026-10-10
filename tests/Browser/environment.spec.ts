import { test, expect } from '@playwright/test';
import path from 'node:path';
import os from 'node:os';

test('DEV page loads and checks the real backend without console or asset errors', async ({
    page,
}, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') errors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
    });

    await page.goto('/dev');
    await expect(page).toHaveURL('http://127.0.0.1:8088/dev');
    await expect(page).toHaveTitle('สภาพแวดล้อม DEV | SMH Learning');
    await expect(page.getByRole('heading', { name: 'สภาพแวดล้อมสำหรับพัฒนา' })).toBeVisible();
    await expect(page.locator('vite-error-overlay')).toHaveCount(0);
    const statusResponse = page.waitForResponse((response) =>
        response.url().endsWith('/dev/status'),
    );
    await page.getByRole('button', { name: 'ตรวจการเชื่อมต่อ', exact: true }).click();
    expect((await statusResponse).status()).toBe(200);
    await expect(page.getByRole('status')).toContainText('เชื่อมต่อบริการทั้งหมดสำเร็จ');
    await expect(page.getByRole('listitem')).toHaveCount(3);
    for (const item of await page.getByRole('listitem').all())
        await expect(item).toContainText('ผ่าน');
    const dimensions = await page.evaluate(() => ({
        content: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
    expect(errors).toEqual([]);
    const screenshotDir = process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir();
    await page.screenshot({
        path: path.join(screenshotDir, `smh-learning-${testInfo.project.name}.png`),
        fullPage: true,
    });
});
