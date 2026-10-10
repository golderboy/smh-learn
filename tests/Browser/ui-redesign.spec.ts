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
test('UI redesign: routes, drawers, keyboard focus, unsaved forms and responsive recovery', async ({
    page,
}, info) => {
    test.setTimeout(600000);
    artisan('dev:seed-demo', '--reset-passwords');
    const main = JSON.parse(artisan('dev:learning-status')) as {
        course_id: number;
        category_id: number;
        version_id: number;
    };
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        // Deliberately navigate to a real 404 below; keep all other console errors visible.
        if (
            message.text().includes('404') &&
            message.location().url.endsWith('/ui-qa-missing-page')
        )
            return;
        if (message.type() === 'error' || message.type() === 'warning') errors.push(message.text());
    });
    const screenshot = (name: string) =>
        page.screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `ui-${name}-${info.project.name}.png`,
            ),
            fullPage: false,
        });
    const checks: { route: string; title: string; width: number }[] = [];
    const check = async () => {
        await expect(page.locator('html')).not.toHaveClass(/nprogress-busy/);
        await expect(page.locator('main h1')).toBeVisible();
        await expect(page.locator('vite-error-overlay')).toHaveCount(0);
        const widthCheck = await page.evaluate(() => ({
            content: document.documentElement.scrollWidth,
            viewport: innerWidth,
            zoom: document.documentElement.style.zoom,
            overflow: [...document.querySelectorAll('main *')]
                .map((element) => ({
                    tag: element.tagName,
                    classes: element.className,
                    right: element.getBoundingClientRect().right,
                    width: element.getBoundingClientRect().width,
                }))
                .filter((element) => element.right > innerWidth + 1 && element.width > 0)
                .slice(0, 12),
        }));
        expect(widthCheck.content <= widthCheck.viewport, JSON.stringify(widthCheck)).toBe(true);
        const textContrasts = await page
            .locator('thead th, .metrics-row > div:nth-child(3) > span')
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
                return elements
                    .filter((element) => element.getBoundingClientRect().width > 0)
                    .map((element) => {
                        const style = getComputedStyle(element);
                        const background =
                            element.tagName === 'SPAN'
                                ? getComputedStyle(element.parentElement!).backgroundColor
                                : style.backgroundColor;
                        const text = luminance(style.color),
                            fill = luminance(background);
                        return {
                            text: element.textContent?.trim(),
                            ratio: (Math.max(text, fill) + 0.05) / (Math.min(text, fill) + 0.05),
                        };
                    });
            });
        for (const contrast of textContrasts)
            expect(contrast.ratio, contrast.text).toBeGreaterThanOrEqual(4.5);
        checks.push({
            route: new URL(page.url()).pathname,
            title: await page.title(),
            width: await page.evaluate(() => innerWidth),
        });
    };
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
        await screenshot('password');
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        await page.goto('/dashboard');
        await check();
        await screenshot('dashboard-personal');
        if (info.project.name === 'desktop-chromium') {
            await page.setViewportSize({ width: 1536, height: 1024 });
            await page.evaluate(() => document.fonts.ready);
            await screenshot('dashboard-reference-size');
            await page.setViewportSize({ width: 1440, height: 900 });
        }
        const colouredAreas = await page
            .locator(
                '.hero-primary, .personal-kpi, .dashboard-panel, .nav-link[aria-current="page"]',
            )
            .evaluateAll((elements) =>
                elements
                    .filter((element) => element.getBoundingClientRect().width > 0)
                    .map((element) => ({
                        colour: getComputedStyle(element).backgroundColor,
                        image: getComputedStyle(element).backgroundImage,
                    })),
            );
        expect(colouredAreas.length).toBeGreaterThanOrEqual(6);
        for (const area of colouredAreas) {
            expect(area.colour).not.toBe('rgb(255, 255, 255)');
            expect(area.colour !== 'rgba(0, 0, 0, 0)' || area.image !== 'none').toBe(true);
        }
        await page.getByRole('button', { name: 'งานตามสิทธิ์ของฉัน', exact: true }).click();
        await expect(page.getByLabel('ข้อมูลภาพรวมองค์กร')).toBeVisible();
        await screenshot('dashboard-work');
        const account = page.getByRole('button', { name: 'เปิดบัญชีของฉัน', exact: true });
        await account.focus();
        await account.click();
        await expect(page.getByRole('dialog', { name: 'บัญชีของฉัน', exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(account).toBeFocused();
        const menu = page.getByRole('button', { name: 'เปิดเมนู', exact: true });
        if (await menu.isVisible()) {
            await menu.focus();
            await menu.click();
            const drawer = page.getByRole('dialog', { name: 'เมนู', exact: true });
            await expect(drawer).toBeVisible();
            await page.keyboard.press('Shift+Tab');
            expect(
                await drawer.evaluate((element) => element.contains(document.activeElement)),
            ).toBe(true);
            await page.keyboard.press('Escape');
            await expect(menu).toBeFocused();
        }
        const routes = [
            '/learning',
            '/courses',
            '/manage/courses',
            `/courses/${main.course_id}`,
            `/manage/courses/${main.course_id}/edit`,
            `/versions/${main.version_id}/questions`,
            '/categories',
            '/categories/create',
            `/categories/${main.category_id}`,
            `/categories/${main.category_id}/edit`,
            '/admin/users',
            '/admin/users/create',
            '/admin/organization/departments',
            '/admin/organization/positions',
            '/admin/roles',
            '/admin/audit',
            '/admin/settings',
            '/profile',
            '/department',
            '/points',
            '/admin/points',
            '/dev',
        ];
        for (const route of routes) {
            await page.goto(route);
            await check();
            await screenshot(new URL(page.url()).pathname.replace(/\//g, '-'));
        }
        await page.goto('/admin/users/create');
        await page.locator('input[name="employee_code"]').fill('UI-QA-INVALID');
        await page.locator('input[name="username"]').fill('abc');
        await page.locator('input[name="first_name"]').fill('ทดสอบ');
        await page.locator('input[name="last_name"]').fill('รูปแบบผิด');
        await page.getByRole('button', { name: 'บันทึกบัญชี', exact: true }).click();
        await expect(page.locator('.notice.error[role="alert"]')).toBeFocused();
        await expect(page.locator('input[name="username"]')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        await expect(page.locator('input[name="username"]')).toHaveValue('abc');
        await screenshot('validation-error');
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('link', { name: 'กลับรายการ', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);
        await page.goto('/admin/users');
        const edit = await page.locator('main a[href$="/edit"]').first().getAttribute('href');
        expect(edit).toBeTruthy();
        await page.goto(edit!);
        await check();
        await screenshot('user-edit');
        await page.goto(`/categories/${main.category_id}/edit`);
        const selectedCount = await page.locator('.selected-people button').count();
        await page.getByLabel('ค้นหาผู้คุมในรายชื่อ').fill('ชื่อที่ไม่มีอยู่');
        await expect(page.locator('.checkbox-grid input')).toHaveCount(0);
        await expect(page.locator('.selected-people button')).toHaveCount(selectedCount);
        await page.goto('/profile');
        await page.getByLabel('เบอร์โทรศัพท์').fill('0000009999');
        page.once('dialog', (dialog) => dialog.dismiss());
        const profileMenu = page.getByRole('button', { name: 'เปิดเมนู', exact: true });
        if (await profileMenu.isVisible()) await profileMenu.click();
        await page.getByRole('link', { name: 'ภาพรวม', exact: true }).click();
        await expect(page).toHaveURL(/\/profile$/);
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'เปิดบัญชีของฉัน', exact: true }).click();
        await page.getByRole('link', { name: 'ข้อมูลส่วนตัว', exact: true }).click();
        await expect(page.getByLabel('เบอร์โทรศัพท์')).not.toHaveValue('0000009999');
        await page.goto('/reports');
        await page.getByRole('button', { name: 'ตัวกรองรายงาน', exact: true }).click();
        await page.getByLabel('สถานะ', { exact: true }).selectOption('COMPLETED');
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'ส่งออก XLSX', exact: true })).toBeDisabled();
        await page.getByRole('button', { name: 'ตัวกรองรายงาน · มีการแก้ไข', exact: true }).click();
        await expect(page.getByLabel('สถานะ', { exact: true })).toHaveValue('COMPLETED');
        await page.getByRole('button', { name: 'แสดงรายงาน', exact: true }).click();
        await expect(
            page.getByRole('dialog', { name: 'ตัวกรองรายงาน', exact: true }),
        ).not.toBeVisible();
        await expect(page.getByRole('button', { name: 'ส่งออก XLSX', exact: true })).toBeEnabled();
        await screenshot('report-filter-applied');
        await page.goto('/ui-qa-missing-page');
        await expect(
            page.getByRole('heading', { name: 'ไม่พบหน้าหรือรายการนี้', exact: true }),
        ).toBeVisible();
        await check();
        await screenshot('recovery-404');
        await page.getByRole('link', { name: 'กลับภาพรวม', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        for (const width of [320, 360, 1024, 1280, 1920]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/admin/users');
            await check();
        }
        await page.setViewportSize({ width: 768, height: 450 });
        await page.goto('/reports');
        await check();
        await page.evaluate(() => {
            document.documentElement.style.zoom = '2';
        });
        await check();
        await screenshot('zoom-200');
        await page.evaluate(() => {
            document.documentElement.style.zoom = '';
        });
        await info.attach('ui-route-coverage', {
            body: JSON.stringify(checks, null, 2),
            contentType: 'application/json',
        });
        await info.attach('computed-typography', {
            body: JSON.stringify(
                await page.evaluate(() => ({
                    body: getComputedStyle(document.body).fontFamily,
                    size: getComputedStyle(document.body).fontSize,
                    primary: getComputedStyle(document.documentElement).getPropertyValue(
                        '--color-primary',
                    ),
                })),
            ),
            contentType: 'application/json',
        });
        expect(errors).toEqual([]);
    } finally {
        artisan('dev:seed-demo', '--reset-passwords');
    }
});
