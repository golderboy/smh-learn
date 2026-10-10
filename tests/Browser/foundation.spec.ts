import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import os from 'node:os';

const reviewPassword = 'DevReview123!';
const readSessionCookieName = () =>
    execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            '-r',
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config("session.cookie");',
        ],
        { cwd: process.cwd(), stdio: 'pipe', timeout: 60000 },
    )
        .toString()
        .trim();
const resetDemoPasswords = () =>
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'dev:seed-demo', '--reset-passwords'],
        { cwd: process.cwd(), stdio: 'pipe', timeout: 60000 },
    );
const signIn = async (page: Page, username: string, password: string, change = false) => {
    await page.goto('/login');
    await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill(username);
    await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill(password);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
    if (change) {
        await expect(page).toHaveURL(/\/password\/change$/);
        await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill(password);
        await page
            .getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/)
            .fill(reviewPassword);
        await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill(reviewPassword);
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่' }).click();
    }
    await expect(page).toHaveURL(/\/dashboard$/);
};
const visit = async (page: Page, url: string) => {
    await page.goto(url);
    await expect(page.locator('main h1')).toBeVisible();
};
const signOut = async (page: Page) => {
    await page.getByRole('button', { name: 'เปิดบัญชีของฉัน', exact: true }).click();
    await page.getByRole('button', { name: 'ออกจากระบบ', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
};
const checkLayout = async (page: Page) => {
    await expect(page.locator('html')).not.toHaveClass(/nprogress-busy/);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await expect(page.locator('vite-error-overlay')).toHaveCount(0);
};

test('Phase 1: admin CRUD, category scope, first password change and learner profile work on the real server', async ({
    page,
}, testInfo) => {
    test.setTimeout(600000);
    expect(testInfo.project.use.baseURL).toBe('http://127.0.0.1:8088');
    const sessionCookieName = readSessionCookieName();
    expect(sessionCookieName).not.toBe('');
    resetDemoPasswords();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') errors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
    });
    const screenshot = async (name: string) =>
        page.screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `phase1-${name}-${testInfo.project.name}.png`,
            ),
            fullPage: true,
        });
    try {
        await page.goto('/login');
        await expect(page).toHaveTitle('เข้าสู่ระบบ | SMH Learning');
        await expect(page.getByRole('button', { name: 'เข้าสู่ระบบด้วย ThaiD' })).toBeDisabled();
        await checkLayout(page);
        await screenshot('login');
        await signIn(page, '0000000000001', 'smh123456', true);
        await expect(page).toHaveTitle('ภาพรวม | SMH Learning');
        await page.getByRole('button', { name: 'งานตามสิทธิ์ของฉัน', exact: true }).click();
        await expect(page.getByLabel('ข้อมูลภาพรวมองค์กร')).toContainText('11');
        const menuToggle = page.getByRole('button', { name: 'เปิดเมนู', exact: true });
        if (await menuToggle.isVisible()) {
            await menuToggle.click();
            await expect(menuToggle).toHaveAttribute('aria-expanded', 'true');
            await page.getByRole('link', { name: 'บัญชีบุคลากร', exact: true }).click();
            await expect(page).toHaveURL(/\/admin\/users$/);
            await expect(menuToggle).toHaveAttribute('aria-expanded', 'false');
            await visit(page, '/dashboard');
        }
        await checkLayout(page);
        await screenshot('admin');

        await visit(page, '/admin/users');
        await expect(page.locator('tbody tr')).toHaveCount(11);
        await page.getByRole('link', { name: 'แก้ไข ผู้ใช้งาน05 ระบบ', exact: true }).click();
        await expect(page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว')).toHaveValue('0000000000005');
        await page.getByLabel(/^ชื่อ\s*\*?$/).fill('ผู้ใช้งาน05ตรวจ');
        await page.getByRole('button', { name: 'บันทึกบัญชี', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกบัญชีบุคลากรแล้ว');
        await page.getByRole('link', { name: 'แก้ไข ผู้ใช้งาน05ตรวจ ระบบ', exact: true }).click();
        await page.getByLabel(/^ชื่อ\s*\*?$/).fill('ผู้ใช้งาน05');
        await page.getByRole('button', { name: 'บันทึกบัญชี', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);
        await page.getByRole('link', { name: 'แก้ไข ผู้ใช้งาน06 ระบบ', exact: true }).click();
        await page.getByRole('button', { name: 'รีเซ็ตรหัสผ่านผู้ใช้นี้' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByLabel('เหตุผลที่รีเซ็ต').fill('ตรวจ Browser บน PC');
        await page.getByRole('button', { name: 'ยืนยันรีเซ็ต', exact: true }).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await expect(page.getByRole('status')).toContainText('รีเซ็ตรหัสผ่านเริ่มต้นแล้ว');

        await visit(page, '/admin/organization/departments');
        // A stopped prior run may leave only this synthetic fixture's QA suffix.
        const demoDepartment = page.getByRole('row').filter({
            has: page.getByRole('cell', { name: /^(?:รหัส )?DEMO-A$/ }),
        });
        await demoDepartment.getByRole('button', { name: /^แก้ไข แผนก ก(?: ตรวจ)?$/ }).click();
        await page.getByLabel(/^ชื่อแผนก\s*\*?$/).fill('แผนก ก ตรวจ');
        await page.getByRole('button', { name: 'บันทึกแผนก', exact: true }).click();
        await expect(page.getByRole('cell', { name: /^(?:ชื่อแผนก )?แผนก ก ตรวจ$/ })).toBeVisible();
        await page.getByRole('button', { name: 'แก้ไข แผนก ก ตรวจ', exact: true }).click();
        await page.getByLabel(/^ชื่อแผนก\s*\*?$/).fill('แผนก ก');
        await page.getByRole('button', { name: 'บันทึกแผนก', exact: true }).click();
        await expect(page.getByRole('cell', { name: /^(?:ชื่อแผนก )?แผนก ก$/ })).toBeVisible();

        await visit(page, '/admin/settings');
        const originalOrganization = await page.getByLabel('ชื่อองค์กร').inputValue();
        await page.getByLabel('ชื่อองค์กร').fill('องค์กรทดสอบ SMH ตรวจ');
        await page.getByLabel('หมดอายุเมื่อไม่ใช้งาน (นาที)').fill('240');
        await page.getByRole('button', { name: 'บันทึกการตั้งค่า' }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกการตั้งค่าแล้ว');
        await expect(page.getByRole('button', { name: 'บันทึกการตั้งค่า' })).toBeEnabled();
        const sessionCookie = (await page.context().cookies()).find(
            (cookie) => cookie.name === sessionCookieName,
        );
        expect(sessionCookie).toBeDefined();
        expect(sessionCookie!.httpOnly).toBe(true);
        expect(sessionCookie!.expires - Date.now() / 1000).toBeGreaterThan(239 * 60);
        expect(sessionCookie!.expires - Date.now() / 1000).toBeLessThanOrEqual(241 * 60);
        await page.getByLabel('ชื่อองค์กร').fill(originalOrganization);
        await page.getByLabel('หมดอายุเมื่อไม่ใช้งาน (นาที)').fill('120');
        await page.getByRole('button', { name: 'บันทึกการตั้งค่า' }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกการตั้งค่าแล้ว');
        await expect(page.getByRole('button', { name: 'บันทึกการตั้งค่า' })).toBeEnabled();
        await visit(page, '/dashboard');
        await checkLayout(page);
        await screenshot('admin');
        await visit(page, '/admin/roles');
        await expect(page.getByRole('heading', { name: 'บทบาทและสิทธิ์' })).toBeVisible();

        await visit(page, '/categories');
        await page.getByRole('link', { name: 'แก้ไข เทคโนโลยีสารสนเทศ', exact: true }).click();
        await page.getByLabel('ผู้ใช้งาน03 ระบบ', { exact: true }).check();
        await page.getByRole('button', { name: 'บันทึกหมวดและผู้คุม' }).click();
        await expect(page).toHaveURL(/\/categories$/);
        await signOut(page);
        await signIn(page, '0000000000003', 'smh123456', true);
        await visit(page, '/categories');
        await expect(page.locator('tbody tr')).toHaveCount(3);
        await expect(page.getByRole('link', { name: 'บัญชีบุคลากร', exact: true })).toHaveCount(0);
        await checkLayout(page);
        await screenshot('instructor');
        await signOut(page);

        await signIn(page, '0000000000001', reviewPassword);
        await visit(page, '/categories');
        await page.getByRole('link', { name: 'แก้ไข เทคโนโลยีสารสนเทศ', exact: true }).click();
        await page.getByLabel('ผู้ใช้งาน03 ระบบ', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'บันทึกหมวดและผู้คุม' }).click();
        await expect(page).toHaveURL(/\/categories$/);
        await visit(page, '/admin/audit');
        await expect(page.getByRole('heading', { name: 'ประวัติการดำเนินงาน' })).toBeVisible();
        await signOut(page);
        await signIn(page, '0000000000003', reviewPassword);
        await visit(page, '/categories');
        await expect(page.locator('tbody tr')).toHaveCount(2);
        await expect(
            page.getByRole('link', { name: 'เทคโนโลยีสารสนเทศ', exact: true }),
        ).toHaveCount(0);
        await signOut(page);

        await signIn(page, '0000000000005', 'smh123456', true);
        await expect(page.getByRole('link', { name: 'บัญชีบุคลากร', exact: true })).toHaveCount(0);
        await visit(page, '/profile');
        await expect(page.locator('main')).toContainText('0000000000005');
        await page.getByLabel('เบอร์โทรศัพท์').fill('0000001111');
        await page.getByRole('button', { name: 'บันทึกช่องทางติดต่อ' }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกข้อมูลส่วนตัวแล้ว');
        await expect(page.getByRole('button', { name: 'บันทึกช่องทางติดต่อ' })).toBeEnabled();
        await page.getByLabel('เบอร์โทรศัพท์').fill('');
        await page.getByRole('button', { name: 'บันทึกช่องทางติดต่อ' }).click();
        await expect(page.getByRole('status')).toContainText('บันทึกข้อมูลส่วนตัวแล้ว');
        await expect(page.getByRole('button', { name: 'บันทึกช่องทางติดต่อ' })).toBeEnabled();
        await checkLayout(page);
        await screenshot('learner');
        await signOut(page);
        expect(errors).toEqual([]);
    } finally {
        resetDemoPasswords();
    }
});
