import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const runPhp = (...args: string[]) =>
    execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', ...args], {
        cwd: process.cwd(),
        stdio: 'pipe',
        timeout: 60000,
    })
        .toString()
        .trim();
const fixture = (mode: string, key: string) =>
    JSON.parse(runPhp('tests/Support/master-import-browser.php', mode, key));
const layout = async (page: Page) => {
    await expect(page.locator('main h1')).toBeVisible();
    await expect(page.locator('vite-error-overlay')).toHaveCount(0);
    expect(await page.title()).toBeTruthy();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    for (const color of await page
        .locator('main .btn:visible')
        .evaluateAll((elements) =>
            elements.map((element) => getComputedStyle(element).backgroundColor),
        ))
        expect(color).not.toBe('rgb(255, 255, 255)');
};
test('Master imports: five templates, actual imports, duplicate skip, protected root and replacing draft exams', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(300000);
    const output = process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir();
    mkdirSync(output, { recursive: true });
    runPhp('artisan', 'dev:seed-demo', '--reset-passwords');
    const key = `MI-${randomBytes(6).toString('hex')}`;
    const data = fixture('prepare', key) as { username: string; version_id: number };
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) errors.push(message.text());
    });
    try {
        await page.goto('/login');
        await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill('0000000000001');
        await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill('smh123456');
        await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
        await expect(page).toHaveURL(/\/password\/change$/);
        await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill('smh123456');
        await page
            .getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/)
            .fill('ImportReview123!');
        await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill('ImportReview123!');
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่', exact: true }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        const names: Record<string, string> = {
            departments: 'แผนก',
            positions: 'ตำแหน่ง',
            categories: 'หมวดหลักสูตร',
            users: 'ผู้ใช้งาน',
            stores: 'ร้านค้าสวัสดิการ',
        };
        for (const kind of ['departments', 'positions', 'categories', 'users', 'stores']) {
            await page.goto(`/admin/imports?kind=${kind}`);
            await expect(
                page.getByRole('heading', { name: 'นำเข้าข้อมูลพื้นฐาน', exact: true }),
            ).toBeVisible();
            await layout(page);
            const download = page.waitForEvent('download');
            await page
                .getByRole('link', { name: `ดาวน์โหลดเทมเพลต${names[kind]}`, exact: true })
                .click();
            expect((await download).suggestedFilename()).toBe(`learning-${kind}-template.xlsx`);
            await page
                .getByLabel(`ไฟล์ Excel ${names[kind]}`)
                .setInputFiles(
                    path.join(
                        process.cwd(),
                        '.cache',
                        'master-import-browser',
                        key,
                        `${kind}.xlsx`,
                    ),
                );
            await page
                .getByRole('button', { name: 'ตรวจไฟล์และแสดงตัวอย่าง', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: 'ตรวจรายการก่อนนำเข้า', exact: true }),
            ).toBeVisible();
            await expect(
                page.getByRole('button', { name: 'ยืนยันนำเข้า 1 รายการ', exact: true }),
            ).toBeEnabled();
            if (kind === 'users') {
                await expect(
                    page.getByRole('table').filter({ hasText: 'แถว Excel' }),
                ).toContainText(data.username);
                await expect(
                    page.getByRole('table').filter({ hasText: 'แถว Excel' }),
                ).toContainText('ข้ามรายการซ้ำ');
                await page
                    .getByRole('heading', { name: 'ตรวจรายการก่อนนำเข้า', exact: true })
                    .scrollIntoViewIfNeeded();
                await page.screenshot({
                    path: path.join(output, `imports-preview-${info.project.name}.png`),
                });
            }
            await page.getByRole('button', { name: 'ยืนยันนำเข้า 1 รายการ', exact: true }).click();
            await expect(page.getByRole('status')).toContainText('นำเข้าสำเร็จ 1 รายการ');
            await expect(
                page.getByRole('heading', { name: 'ตรวจรายการก่อนนำเข้า', exact: true }),
            ).toHaveCount(0);
        }
        await page.goto('/admin/imports?kind=users');
        await page
            .getByLabel('ไฟล์ Excel ผู้ใช้งาน')
            .setInputFiles(
                path.join(process.cwd(), '.cache', 'master-import-browser', key, 'users.xlsx'),
            );
        await page.getByRole('button', { name: 'ตรวจไฟล์และแสดงตัวอย่าง', exact: true }).click();
        await expect(
            page.getByRole('button', { name: 'ยืนยันนำเข้า 0 รายการ', exact: true }),
        ).toBeEnabled();
        await expect(page.getByRole('table').filter({ hasText: 'แถว Excel' })).toContainText(
            'ข้ามรายการซ้ำ',
        );
        await page.getByRole('button', { name: 'ยืนยันนำเข้า 0 รายการ', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('ไม่มีรายการใหม่');
        await page.goto(`/versions/${data.version_id}/questions`);
        const questionDownload = page.waitForEvent('download');
        await page.getByRole('link', { name: 'ดาวน์โหลดเทมเพลตข้อสอบ Excel', exact: true }).click();
        expect((await questionDownload).suggestedFilename()).toBe(
            'SMH-Objective-Exam-Template.xlsx',
        );
        for (const round of [1, 2]) {
            await page
                .getByLabel('ไฟล์ Excel .xlsx (ไม่เกิน 10 MB / 1,000 ข้อ)')
                .setInputFiles(
                    path.join(process.cwd(), 'docs/templates/objective-exam-template.xlsx'),
                );
            await page
                .getByRole('button', { name: 'ตรวจไฟล์และแสดงตัวอย่าง', exact: true })
                .click();
            await page.getByRole('button', { name: 'ยืนยันนำเข้า 3 ข้อ', exact: true }).click();
            await expect(page.getByRole('heading', { name: /^PRETEST: 2 ข้อ/ })).toBeVisible();
            await expect(page.locator('main')).toContainText('DEMO-PRE001');
            if (round === 1) {
                await page
                    .getByRole('button', { name: 'ล้างข้อสอบเดิมเพื่อนำเข้าใหม่', exact: true })
                    .click();
                const dialog = page.getByRole('dialog', {
                    name: 'ยืนยันล้างข้อสอบของเวอร์ชันร่าง',
                });
                await expect(dialog).toBeVisible();
                await dialog.getByRole('button', { name: 'ยืนยันล้างข้อสอบ', exact: true }).click();
                await expect(dialog).not.toBeVisible();
                await expect(page.locator('main')).toContainText('ยังไม่มีข้อสอบ');
            }
        }
        await layout(page);
        await page
            .getByRole('heading', { name: 'นำเข้าข้อสอบปรนัย Excel', exact: true })
            .scrollIntoViewIfNeeded();
        await page.screenshot({
            path: path.join(output, `exam-reimport-${info.project.name}.png`),
        });
        const result = fixture('check', key);
        expect(result).toMatchObject({
            ok: true,
            users: 1,
            store_status: 'PENDING',
            active_questions: 3,
            archived_questions: 3,
        });
        await page.goto('/admin/users?search=admins');
        await expect(page.locator('main')).toContainText('แอดมินตั้งต้น · บัญชีป้องกัน');
        await expect(
            page
                .getByRole('row')
                .filter({ hasText: 'SYSTEM-ROOT' })
                .getByRole('link', { name: /แก้ไข/ }),
        ).toHaveCount(0);
        if (result.root_needs_first_sign_in) {
            const rootContext = await browser.newContext(info.project.use);
            const rootPage = await rootContext.newPage();
            await rootPage.goto('/login');
            await rootPage.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill('admins');
            await rootPage.getByLabel(/^รหัสผ่าน\s*\*?$/).fill('P@ssw0rd112233');
            await rootPage.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
            await expect(rootPage).toHaveURL(/\/password\/change$/);
            await rootContext.close();
        }
        await page.goto('/admin/imports?kind=users');
        await layout(page);
        await page.screenshot({
            path: path.join(output, `imports-ready-${info.project.name}.png`),
        });
        expect(errors).toEqual([]);
    } finally {
        fixture('cleanup', key);
        runPhp('artisan', 'dev:seed-demo', '--reset-passwords');
    }
});
