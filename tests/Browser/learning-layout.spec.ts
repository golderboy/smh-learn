import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import os from 'node:os';
const artisan = (...args: string[]) =>
    execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', ...args], {
        cwd: process.cwd(),
        stdio: 'pipe',
        timeout: 60000,
    })
        .toString()
        .trim();
test('Learning layout: video has the main column, outline aligns on desktop and becomes a drawer on small screens', async ({
    page,
}, info) => {
    test.setTimeout(120000);
    artisan('dev:seed-demo', '--reset-passwords');
    const main = JSON.parse(artisan('dev:learning-status')) as { course_id: number };
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    try {
        await page.goto('/login');
        await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill('0000000000001');
        await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill('smh123456');
        await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
        await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill('smh123456');
        await page
            .getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/)
            .fill('DevReview123!');
        await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill('DevReview123!');
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        await page.goto(`/courses/${main.course_id}`);
        await page.getByRole('link', { name: 'เข้าสู่บทเรียน', exact: true }).first().click();
        await expect(page).toHaveURL(/\/learning\/\d+$/);
        const outline = page.locator('.desktop-lesson-outline');
        const toggle = page.getByRole('button', { name: /^รายการบทเรียน ·/ });
        const content = page.locator('.lesson-main');
        await expect(content).toBeVisible();
        const completedSteps = await page
            .locator('.learning-steps .complete')
            .evaluateAll((elements) => {
                const luminance = (colour: string) => {
                    const channels = colour
                        .match(/[\d.]+/g)!
                        .slice(0, 3)
                        .map((value) => Number(value) / 255)
                        .map((value) =>
                            value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4,
                        );
                    return channels[0]! * 0.2126 + channels[1]! * 0.7152 + channels[2]! * 0.0722;
                };
                return elements.map((element) => {
                    const style = getComputedStyle(element);
                    const text = luminance(style.color),
                        background = luminance(style.backgroundColor);
                    return {
                        label: element.textContent,
                        ratio:
                            (Math.max(text, background) + 0.05) /
                            (Math.min(text, background) + 0.05),
                    };
                });
            });
        for (const step of completedSteps)
            expect(step.ratio, step.label ?? 'Completed learning step').toBeGreaterThanOrEqual(4.5);
        const geometry = await page.locator('.learning-columns').evaluate((element) => {
            const container = element.getBoundingClientRect();
            const content = element.querySelector('.lesson-main')!.getBoundingClientRect();
            const outline = element
                .querySelector('.desktop-lesson-outline')!
                .getBoundingClientRect();
            return {
                width: container.width,
                contentWidth: content.width,
                mainX: content.x,
                mainY: content.y,
                outlineX: outline.x,
                outlineY: outline.y,
            };
        });
        if (info.project.name === 'desktop-chromium') {
            await expect(toggle).not.toBeVisible();
            await expect(outline).toBeVisible();
            expect(geometry.mainX).toBeGreaterThan(geometry.outlineX);
            expect(Math.abs(geometry.mainY - geometry.outlineY)).toBeLessThanOrEqual(2);
            expect(geometry.contentWidth).toBeGreaterThan(geometry.width * 0.55);
        } else {
            await expect(outline).not.toBeVisible();
            await expect(toggle).toBeVisible();
            expect(geometry.contentWidth).toBeGreaterThanOrEqual(geometry.width - 2);
            await toggle.click();
            await expect(
                page.getByRole('dialog', { name: 'รายการบทเรียน', exact: true }),
            ).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(toggle).toBeFocused();
        }
        await page.evaluate(() => document.fonts.ready);
        for (const canvas of await page.locator('.pdf-canvas').all())
            await expect(canvas).toHaveAttribute('data-rendered', 'true');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
        await page.screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `learning-layout-${info.project.name}.png`,
            ),
            fullPage: false,
        });
        await info.attach('learning-geometry', {
            body: JSON.stringify(geometry, null, 2),
            contentType: 'application/json',
        });
        expect(errors).toEqual([]);
    } finally {
        artisan('dev:seed-demo', '--reset-passwords');
    }
});
