import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
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
const signIn = async (page: Page, number: number) => {
    await page.goto('/login');
    await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill(String(number).padStart(13, '0'));
    await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill('smh123456');
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
    await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill('smh123456');
    await page.getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/).fill('CouponQa123!');
    await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill('CouponQa123!');
    await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
};
test('Welfare coupons: admin approves, learner exchanges three offers, shop consumes once and exports a 35 baht claim', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(360000);
    artisan('dev:seed-demo', '--reset-passwords');
    const output = process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir();
    mkdirSync(output, { recursive: true });
    const errors: string[] = [];
    const observe = (target: Page) => {
        target.on('pageerror', (error) => errors.push(error.message));
        target.on('console', (message) => {
            // Invalid/used coupon lookups are deliberate HTTP 422 checks below.
            if (
                message.type() === 'error' &&
                message.text().includes('422') &&
                message.location().url.endsWith('/welfare/coupons/check')
            )
                return;
            if (['error', 'warning'].includes(message.type())) errors.push(message.text());
        });
    };
    const check = async (target: Page) => {
        await expect(target.locator('main h1')).toBeVisible();
        await expect(target.locator('vite-error-overlay')).toHaveCount(0);
        expect(await target.title()).toBeTruthy();
        expect(
            await target.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        ).toBe(true);
        const buttons = await target
            .locator('main .btn:visible')
            .evaluateAll((elements) =>
                elements.map((element) => getComputedStyle(element).backgroundColor),
            );
        for (const color of buttons) expect(color).not.toBe('rgb(255, 255, 255)');
    };
    const shot = async (target: Page, name: string) => {
        await check(target);
        await target.screenshot({
            path: path.join(output, `welfare-${name}-${info.project.name}.png`),
            fullPage: false,
        });
    };
    observe(page);
    await signIn(page, 1);
    const fixture = JSON.parse(artisan('dev:prepare-welfare-demo', '--qa-credit')) as {
        store_id: number;
        balance: number;
    };
    await page.goto('/admin/welfare');
    await page.getByRole('button', { name: 'เพิ่มร้าน', exact: true }).click();
    const add = page.getByRole('dialog', { name: 'เพิ่มร้านสวัสดิการ', exact: true });
    const unique = `QA-${info.project.name}-${Date.now()}`;
    await add.getByLabel(/^รหัสร้าน/).fill(unique);
    await add.getByLabel(/^ชื่อร้าน/).fill(`ร้านสวัสดิการทดสอบ ${info.project.name}`);
    await add.getByLabel('ผู้ใช้งาน10 ระบบ · DEMO010', { exact: true }).check();
    await add.getByRole('button', { name: 'บันทึกร้าน', exact: true }).click();
    await expect(add).not.toBeVisible();
    const storeCard = page.locator('.welfare-store-card').filter({ hasText: unique });
    await expect(storeCard).toContainText('รออนุมัติ');
    await storeCard.getByRole('button', { name: 'อนุมัติร้าน', exact: true }).click();
    const approval = page.getByRole('dialog', { name: 'อนุมัติร้านสวัสดิการ', exact: true });
    await approval.getByLabel(/^เหตุผล/).fill('อนุมัติร้านจำลองผ่าน Browser QA');
    await approval.getByRole('button', { name: 'ยืนยันอนุมัติร้าน', exact: true }).click();
    await expect(approval).not.toBeVisible();
    await expect(storeCard).toContainText('อนุมัติแล้ว');
    await page.getByRole('button', { name: 'รายการคูปอง', exact: true }).click();
    await page.getByRole('button', { name: 'เพิ่มรายการคูปอง', exact: true }).click();
    const offerDialog = page.getByRole('dialog', { name: 'เพิ่มรายการคูปอง', exact: true });
    await offerDialog
        .getByRole('combobox', { name: 'ร้านที่ใช้คูปอง', exact: true })
        .selectOption({ label: `ร้านสวัสดิการทดสอบ ${info.project.name}` });
    const qaOfferName = `คูปองตั้งค่าทดสอบ ${unique}`;
    await offerDialog.getByLabel(/^ชื่อรายการ/).fill(qaOfferName);
    await offerDialog.getByLabel('แต้มที่ใช้', { exact: true }).fill('600');
    await offerDialog.getByLabel(/^ส่วนลด \(บาท\)/).fill('12.34');
    await offerDialog.getByLabel('จำนวนคูปองเริ่มต้น', { exact: true }).fill('0');
    const endDate = '2026-12-31';
    await offerDialog.getByLabel('วันสุดท้ายที่เปิดแลก', { exact: true }).fill(endDate);
    await offerDialog.getByLabel('เปิดให้แลก', { exact: true }).uncheck();
    await offerDialog.getByRole('button', { name: 'บันทึกรายการคูปอง', exact: true }).click();
    await expect(offerDialog).not.toBeVisible();
    const qaOffer = page.locator('.coupon-history li').filter({ hasText: qaOfferName });
    await expect(qaOffer).toContainText('12.34 บาท');
    await qaOffer.getByRole('button', { name: 'แก้ไขรายการ', exact: true }).click();
    const editOffer = page.getByRole('dialog', { name: 'แก้ไขรายการคูปอง', exact: true });
    await expect(editOffer.getByLabel('วันสุดท้ายที่เปิดแลก', { exact: true })).toHaveValue(
        endDate,
    );
    await editOffer.getByRole('button', { name: 'ปิดหน้าต่าง', exact: true }).click();
    await qaOffer.getByRole('button', { name: 'เพิ่มจำนวน', exact: true }).click();
    const stock = page.getByRole('dialog', { name: 'เพิ่มจำนวนคูปองที่เปิดแลก', exact: true });
    await stock.getByLabel('จำนวนที่เพิ่ม', { exact: true }).fill('2');
    await stock.getByRole('button', { name: 'ยืนยันเพิ่มจำนวน', exact: true }).click();
    await expect(stock).not.toBeVisible();
    await expect(qaOffer).toContainText('คงเหลือ 2 ใบ');
    await page.getByRole('button', { name: 'ร้านที่เข้าร่วม', exact: true }).click();
    await shot(page, 'admin');
    const learnerContext = await browser.newContext({ ...info.project.use });
    const merchantContext = await browser.newContext({ ...info.project.use });
    const learner = await learnerContext.newPage();
    const merchant = await merchantContext.newPage();
    observe(learner);
    observe(merchant);
    try {
        await signIn(learner, 6);
        await learner.goto('/coupons');
        await expect(learner.getByTestId('coupon-balance')).toHaveText(
            fixture.balance.toLocaleString('th-TH'),
        );
        await shot(learner, 'catalog');
        const coupons: { code: string; id: string; url: string; discount: number }[] = [];
        for (const [points, discount] of [
            [500, 5],
            [1000, 10],
            [1500, 20],
        ]) {
            await learner.goto('/coupons');
            await learner
                .getByTestId(`offer-${points}`)
                .getByRole('button', { name: 'แลกคูปองนี้', exact: true })
                .click();
            const exchange = learner.getByRole('dialog', {
                name: 'ยืนยันแลกแต้มเป็นคูปอง',
                exact: true,
            });
            await expect(exchange).toContainText(`ส่วนลด ${discount.toFixed(2)} บาท`);
            await exchange.getByRole('button', { name: 'ยืนยันแลกคูปอง', exact: true }).click();
            await expect(learner).toHaveURL(/\/coupons\/[a-f0-9-]{36}$/);
            await learner.getByRole('button', { name: 'แสดงรหัสให้ร้าน', exact: true }).click();
            const code = await learner.getByTestId('coupon-code').innerText();
            expect(code.startsWith('CPN1-') && code.length === 37).toBe(true);
            coupons.push({
                code,
                id: new URL(learner.url()).pathname.split('/').pop()!,
                url: learner.url(),
                discount,
            });
            await learner.getByRole('button', { name: 'ซ่อนรหัส', exact: true }).click();
            if (points === 1500) await shot(learner, 'card');
        }
        await learner.goto('/points');
        await expect(learner.getByTestId('point-balance')).toHaveText(
            (fixture.balance - 3000).toLocaleString('th-TH'),
        );
        await expect(learner.locator('main')).toContainText('แลกแต้มเป็นคูปอง');
        await signIn(merchant, 10);
        await merchant.goto(`/welfare/store?store_id=${fixture.store_id}`);
        const beforeCount = Number(
            (await merchant.getByLabel('สรุปคูปอง').locator('strong').first().innerText()).replace(
                /\D/g,
                '',
            ),
        );
        await merchant.getByLabel(/^รหัสใช้คูปอง/).fill('CPN1-' + '0'.repeat(32));
        await merchant.getByRole('button', { name: 'ตรวจคูปอง', exact: true }).click();
        await expect(merchant.getByRole('alert')).toContainText('ไม่พบคูปอง');
        for (const coupon of coupons) {
            await merchant.getByLabel(/^รหัสใช้คูปอง/).fill(coupon.code);
            await merchant.getByRole('button', { name: 'ตรวจคูปอง', exact: true }).click();
            await expect(merchant.getByLabel('รายละเอียดคูปองที่ตรวจ')).toContainText(
                `${coupon.discount.toFixed(2)} บาท`,
            );
            await merchant.getByRole('button', { name: 'รับคูปองนี้', exact: true }).click();
            await merchant
                .getByRole('dialog', { name: 'ยืนยันรับคูปอง', exact: true })
                .getByRole('button', { name: 'ยืนยันรับคูปอง', exact: true })
                .click();
            await expect(
                merchant.getByRole('dialog', { name: 'ยืนยันรับคูปอง', exact: true }),
            ).not.toBeVisible();
            await expect(merchant.getByLabel(/^รหัสใช้คูปอง/)).toHaveValue('');
        }
        await expect(merchant.getByLabel('สรุปคูปอง').locator('strong').first()).toHaveText(
            `${beforeCount + 3} ใบ`,
        );
        await merchant.getByLabel(/^รหัสใช้คูปอง/).fill(coupons[0]!.code);
        await merchant.getByRole('button', { name: 'ตรวจคูปอง', exact: true }).click();
        await expect(merchant.getByRole('alert')).toContainText('ใช้ไปแล้ว');
        await merchant.getByLabel(/^รหัสใช้คูปอง/).fill('');
        for (const coupon of coupons)
            await merchant.getByLabel(`เลือกคูปอง ${coupon.id}`, { exact: true }).check();
        await shot(merchant, 'merchant');
        await merchant.getByRole('button', { name: 'สร้างใบสรุปส่งเบิก (3)', exact: true }).click();
        const claim = merchant.getByRole('dialog', { name: 'สร้างใบสรุปส่งเบิก', exact: true });
        await expect(claim).toContainText('รวม 35.00 บาท');
        await claim.getByLabel(/^หมายเหตุ/).fill(`QA ${info.project.name}`);
        await claim.getByRole('button', { name: 'ยืนยันสร้างใบสรุป', exact: true }).click();
        await expect(merchant).toHaveURL(/\/welfare\/claims\/[a-f0-9-]{36}$/);
        await expect(merchant.getByTestId('claim-total')).toHaveText('35.00');
        const claimUrl = merchant.url();
        await shot(merchant, 'claim');
        for (const format of ['PDF', 'CSV']) {
            const downloadEvent = merchant.waitForEvent('download');
            await merchant.getByRole('link', { name: `ดาวน์โหลด ${format}`, exact: true }).click();
            const download = await downloadEvent;
            const file = path.join(
                output,
                `welfare-claim-${info.project.name}.${format.toLowerCase()}`,
            );
            await download.saveAs(file);
            const bytes = readFileSync(file);
            if (format === 'PDF') expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
            else {
                expect(bytes.subarray(0, 3).toString('hex')).toBe('efbbbf');
                expect(bytes.toString('utf8')).toContain('35.00');
                for (const coupon of coupons)
                    expect(bytes.toString('utf8').includes(coupon.code)).toBe(false);
            }
        }
        await merchant.goto(`/welfare/store?store_id=${fixture.store_id}`);
        for (const coupon of coupons)
            await expect(
                merchant.getByLabel(`เลือกคูปอง ${coupon.id}`, { exact: true }),
            ).toHaveCount(0);
        await learner.goto(coupons[0]!.url);
        await expect(learner.locator('.online-coupon')).toContainText('รับส่วนลดแล้ว');
        await expect(
            learner.getByRole('button', { name: 'แสดงรหัสให้ร้าน', exact: true }),
        ).toHaveCount(0);
        await page.goto(claimUrl);
        await expect(page.getByTestId('claim-total')).toHaveText('35.00');
        await check(page);
        await check(merchant);
        await check(learner);
        expect(errors).toEqual([]);
    } finally {
        await learnerContext.close();
        await merchantContext.close();
    }
});
