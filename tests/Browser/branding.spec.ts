import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import type { BrandingSettings } from '../../resources/js/Types/foundation';

type Settings = BrandingSettings & { organization_name: string; session_timeout_minutes: number };
const textKeys = [
    'product_name',
    'organization_name',
    'header_title',
    'header_description',
    'banner_text',
    'login_heading',
    'login_description',
    'footer_text',
    'support_email',
    'support_phone',
    'support_url',
] as const;
const assetKinds = ['logo', 'banner', 'favicon'] as const;
const signIn = async (page: Page, password: string, change = false) => {
    await page.goto('/login');
    await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill('0000000000001');
    await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill(password);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
    if (change) {
        await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill(password);
        await page
            .getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/)
            .fill('DevReview123!');
        await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill('DevReview123!');
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่', exact: true }).click();
    }
    await expect(page).toHaveURL(/\/dashboard$/);
};
test('Branding: admin previews and saves assets, guest login uses settings and original branding is restored', async ({
    page,
}, info) => {
    test.setTimeout(240000);
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'dev:seed-demo', '--reset-passwords'],
        { cwd: process.cwd(), stdio: 'pipe', timeout: 60000 },
    );
    let original: Settings | undefined;
    const originalFiles: Partial<Record<(typeof assetKinds)[number], Buffer>> = {};
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (
            message.text().includes('404') &&
            message.location().url.endsWith('/branding-qa-missing-page')
        )
            return;
        if (message.type() === 'error') errors.push(message.text());
    });
    const capture = (name: string) =>
        page.screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `branding-${name}-${info.project.name}.png`,
            ),
            fullPage: false,
        });
    const csrfHeaders = async () => {
        const csrf = (await page.context().cookies()).find(
            (cookie) => cookie.name === 'XSRF-TOKEN',
        );
        expect(csrf).toBeTruthy();
        return {
            'X-XSRF-TOKEN': decodeURIComponent(csrf!.value),
            Referer: 'http://127.0.0.1:8088/admin/settings',
        };
    };
    const readSettings = async (): Promise<Settings> => {
        const response = await page.request.get('/admin/settings');
        expect(response.status()).toBe(200);
        // Inertia 3's installed Blade directive emits JSON in this script element.
        const match = (await response.text()).match(
            /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/,
        );
        expect(match).toBeTruthy();
        const payload = JSON.parse(match![1]!);
        expect(payload.component).toBe('Admin/Settings');
        return payload.props.settings as Settings;
    };
    try {
        await signIn(page, 'smh123456', true);
        await page.goto('/admin/settings');
        original = await readSettings();
        for (const kind of assetKinds) {
            const url = original[`${kind}_url`];
            if (url) {
                const asset = await page.request.get(url);
                expect(asset.status()).toBe(200);
                originalFiles[kind] = await asset.body();
            }
        }
        await page.locator('[name="product_name"]').fill('Cobalt Learning QA');
        await page.locator('[name="header_title"]').fill('พื้นที่เรียนรู้ขององค์กร');
        await page.locator('[name="banner_text"]').fill('เรียนรู้วันนี้ เติบโตไปด้วยกัน');
        await page.locator('[name="login_heading"]').fill('เริ่มต้นการเรียนรู้');
        await page.locator('[name="support_url"]').fill('https://example.org/help');
        const bytes = readFileSync('public/images/cobalt-hero.webp');
        for (const kind of assetKinds)
            await page
                .locator(`#${kind}_file`)
                .setInputFiles({ name: 'qa-brand.webp', mimeType: 'image/webp', buffer: bytes });
        await expect(page.locator('.branding-preview')).toContainText('Cobalt Learning QA');
        await expect(page.locator('.branding-preview-nav img')).toHaveAttribute('src', /^blob:/);
        await capture('preview');
        await page.getByRole('button', { name: 'บันทึกการตั้งค่า', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกการตั้งค่าแล้ว');
        await expect(page.locator('.branding-preview-nav img')).toHaveAttribute(
            'src',
            /\/branding\/logo\//,
        );
        await page.goto('/dashboard');
        await expect(page.locator('main h1')).toContainText('พื้นที่เรียนรู้ขององค์กร');
        await expect(page.locator('.course-hero-art img')).toHaveAttribute(
            'src',
            /\/branding\/banner\//,
        );
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute(
            'href',
            /\/branding\/favicon\//,
        );
        await expect(page.locator('.site-footer a[href="https://example.org/help"]')).toBeVisible();
        await capture('dashboard');
        const missingPage = await page.goto('/branding-qa-missing-page');
        expect(missingPage?.status()).toBe(404);
        await expect(page).toHaveTitle('ไม่พบหน้าหรือรายการนี้ | Cobalt Learning QA');
        await expect(page.locator('.error-brand .brand-logo')).toHaveAttribute(
            'src',
            /\/branding\/logo\//,
        );
        await expect(page.locator('.error-brand .brand-logo')).toBeVisible();
        await expect
            .poll(() =>
                page.locator('.error-brand .brand-logo').evaluate((element) => {
                    const image = element as HTMLImageElement;
                    return image.complete && image.naturalWidth > 0;
                }),
            )
            .toBe(true);
        await expect(page.locator('.site-footer a[href="https://example.org/help"]')).toBeVisible();
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute(
            'href',
            /\/branding\/favicon\//,
        );
        await capture('error');
        await page.getByRole('link', { name: 'กลับภาพรวม', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        await page.getByRole('button', { name: 'เปิดบัญชีของฉัน', exact: true }).click();
        await page.getByRole('button', { name: 'ออกจากระบบ', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await expect(
            page.getByRole('heading', { name: 'เริ่มต้นการเรียนรู้', exact: true }),
        ).toBeVisible();
        await expect(page.locator('.brand-logo').first()).toHaveAttribute(
            'src',
            /\/branding\/logo\//,
        );
        await capture('login');
        await signIn(page, 'DevReview123!');
        await page.goto('/admin/settings');
        await page.locator('.branding-upload').first().getByRole('checkbox').check();
        await expect(page.locator('.branding-preview-nav img')).toHaveCount(0);
        await page.getByRole('button', { name: 'บันทึกการตั้งค่า', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกการตั้งค่าแล้ว');
        expect((await readSettings()).logo_url).toBeNull();
        expect(errors).toEqual([]);
    } finally {
        if (original) {
            // A failed guest-login assertion must still restore Settings as an authenticated admin.
            const authResponse = await page.request.get('/admin/settings');
            if (new URL(authResponse.url()).pathname === '/login')
                await signIn(page, 'DevReview123!');
            // Restore the exact previous text and original bytes through the authorized Settings API.
            const multipart: Record<
                string,
                string | { name: string; mimeType: string; buffer: Buffer }
            > = {
                _method: 'put',
                session_timeout_minutes: String(original.session_timeout_minutes),
                show_sidebar_art: original.show_sidebar_art ? '1' : '0',
            };
            for (const key of textKeys) multipart[key] = original[key] ?? '';
            for (const kind of assetKinds) {
                if (originalFiles[kind]) {
                    const ext =
                        originalFiles[kind]!.subarray(0, 4).toString() === 'RIFF'
                            ? 'webp'
                            : originalFiles[kind]![0] === 0x89
                              ? 'png'
                              : 'jpg';
                    multipart[`${kind}_file`] = {
                        name: `restore.${ext}`,
                        mimeType: ext === 'jpg' ? 'image/jpeg' : `image/${ext}`,
                        buffer: originalFiles[kind]!,
                    };
                } else multipart[`remove_${kind}`] = '1';
            }
            const restore = await page.request.post('/admin/settings', {
                headers: await csrfHeaders(),
                multipart,
            });
            expect(restore.status()).toBe(200);
            const settings = await readSettings();
            for (const key of textKeys) expect(settings[key]).toBe(original[key]);
            for (const kind of assetKinds)
                expect(Boolean(settings[`${kind}_url`])).toBe(Boolean(original[`${kind}_url`]));
            await page.goto('/admin/settings');
            await capture('restored');
        }
        execFileSync(
            'docker',
            [
                'compose',
                'exec',
                '-T',
                'app',
                'php',
                'artisan',
                'dev:seed-demo',
                '--reset-passwords',
            ],
            { cwd: process.cwd(), stdio: 'pipe', timeout: 60000 },
        );
    }
});
