import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import os from 'node:os';

const password = 'DevReview123!';
const artisan = (...args: string[]) =>
    execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', ...args], {
        cwd: process.cwd(),
        stdio: 'pipe',
        timeout: 60000,
    })
        .toString()
        .trim();
const login = async (page: Page, username: string, initial = true) => {
    await page.goto('/login');
    await page.getByLabel('เลขบัตรประชาชน / เลขประจำตัว').fill(username);
    await page.getByLabel(/^รหัสผ่าน\s*\*?$/).fill(initial ? 'smh123456' : password);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ', exact: true }).click();
    if (initial) {
        await expect(page).toHaveURL(/\/password\/change$/);
        await page.getByLabel(/^รหัสผ่านปัจจุบัน\s*\*?$/).fill('smh123456');
        await page.getByLabel(/^รหัสผ่านใหม่ \(อย่างน้อย 10 ตัวอักษร\)\s*\*?$/).fill(password);
        await page.getByLabel(/^ยืนยันรหัสผ่านใหม่\s*\*?$/).fill(password);
        await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่' }).click();
    }
    await expect(page).toHaveURL(/\/dashboard$/);
};
const logout = async (page: Page) => {
    await page.getByRole('button', { name: 'เปิดบัญชีของฉัน', exact: true }).click();
    await page.getByRole('button', { name: 'ออกจากระบบ', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
};

test('Phase 2–6: course, assessment, scoped reports, queued exports and point ledger', async ({
    page,
}, info) => {
    test.setTimeout(600000);
    expect(info.project.use.baseURL).toBe('http://127.0.0.1:8088');
    const code = `SMH-E2E-${info.project.name}`;
    const title = `การทดสอบอัตโนมัติ ${code}`;
    const main = JSON.parse(artisan('dev:learning-status')) as {
        course_id: number;
        category_id: number;
        version_id: number;
        question_count: number;
    };
    artisan('dev:reset-learning-qa', code);
    artisan('dev:seed-demo', '--reset-passwords');
    const source = path.resolve('demo-learn/วิชา ระเบียบวิธีวิจัย');
    const pdf = path.join(
        source,
        readdirSync(source).find((name) => name.toLowerCase().endsWith('.pdf'))!,
    );
    const template = path.resolve('docs/templates/objective-exam-template.xlsx');
    const errors: string[] = [];
    const providerDiagnostics: { text: string; url: string }[] = [];
    let youtubeRequested = false;
    page.on('request', (request) => {
        if (request.url().startsWith('https://www.youtube.com/embed/')) youtubeRequested = true;
    });
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (e) => {
        if (e.type() !== 'error' && e.type() !== 'warning') return;
        const url = e.location().url;
        const fromYoutube = url.startsWith('https://www.youtube.com/');
        const knownProviderWarning =
            e.type() === 'warning' &&
            ((fromYoutube &&
                [
                    "Unrecognized feature: 'web-share'.",
                    'No available adapters.',
                    "Failed to execute 'postMessage' on 'DOMWindow': The target origin provided ('https://www.youtube.com') does not match the recipient window's origin ('http://127.0.0.1:8088').",
                ].includes(e.text())) ||
                (youtubeRequested &&
                    url === '' &&
                    e.text() ===
                        'The powerPreference option is currently ignored when calling requestAdapter() on Windows. See https://crbug.com/369219127'));
        if (knownProviderWarning) providerDiagnostics.push({ text: e.text(), url });
        else errors.push(e.text());
    });
    page.on('response', (r) => {
        if (r.status() >= 400) errors.push(`${r.status()} ${r.url()}`);
    });
    const screenshot = async (name: string) => {
        await expect(page.locator('html')).not.toHaveClass(/nprogress-busy/);
        await expect(page.locator('main h1')).toBeVisible();
        await expect(page.locator('vite-error-overlay')).toHaveCount(0);
        for (const canvas of await page.locator('.pdf-canvas').all()) {
            await expect(canvas).toHaveAttribute('data-rendered', 'true');
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
        await page.screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `phase4-${name}-${info.project.name}.png`,
            ),
            fullPage: !name.startsWith('exam'),
        });
        if (name === 'completed') {
            await page
                .locator('main .surface')
                .first()
                .screenshot({
                    path: path.join(
                        process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                        `phase4-summary-${info.project.name}.png`,
                    ),
                });
        }
    };
    const importQuestions = async () => {
        const download = page.waitForEvent('download', { timeout: 15000 });
        await page.getByRole('link', { name: 'ดาวน์โหลดแม่แบบ Excel' }).click();
        expect((await download).suggestedFilename()).toBe('SMH-Objective-Exam-Template.xlsx');
        await page
            .getByLabel('ไฟล์ Excel .xlsx (ไม่เกิน 10 MB / 1,000 ข้อ)')
            .setInputFiles(template);
        await page.getByRole('button', { name: 'ตรวจไฟล์และแสดงตัวอย่าง' }).click();
        await expect(
            page.getByRole('heading', { name: 'ตรวจรายการก่อนนำเข้า 3 ข้อ' }),
        ).toBeVisible();
        await expect(page.locator('.choice-preview').first().locator('li')).toHaveCount(4);
        await expect(page.locator('.choice-preview').nth(1).locator('li')).toHaveCount(5);
        await expect(page.locator('.choice-preview').nth(2).locator('li')).toHaveCount(6);
        await expect(page.locator('.choice-preview').nth(2)).toContainText('ฉ (F).');
        await screenshot('excel-preview');
        await page.getByRole('button', { name: 'ยืนยันนำเข้า 3 ข้อ' }).click();
        await expect(page.getByRole('heading', { name: 'ตรวจรายการก่อนนำเข้า 3 ข้อ' })).toHaveCount(
            0,
        );
        await expect(
            page.getByRole('heading', { name: 'PRETEST: 2 ข้อ · ฉบับร่าง' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'POSTTEST: 2 ข้อ · ฉบับร่าง' }),
        ).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(3);
    };
    let scopeChanged = false;
    let priorScope = false;
    const takeExam = async (correct: boolean | null, name: string) => {
        await expect(page).toHaveURL(/\/attempts\/\d+$/);
        await expect(page).toHaveTitle(/Pretest ก่อนเรียน|Posttest หลังเรียน/);
        const questions = page.locator('.exam-question');
        await expect(questions.first()).toBeVisible();
        const count = await questions.count();
        for (let index = 0; index < count; index++) {
            await page.locator('.question-numbers button').nth(index).click();
            const question = questions.nth(index);
            const text = await question.locator('.question-text').innerText();
            const answer = text.includes('ไฟล์ Excel')
                ? 'A'
                : text.includes('จำนวนครั้ง')
                  ? 'F'
                  : 'E';
            const options = question.locator('.exam-choice');
            const optionCount = await options.count();
            expect([4, 5, 6]).toContain(optionCount);
            for (let option = 0; option < optionCount; option++) {
                await expect(options.nth(option).locator('strong')).toHaveText(
                    `${'กขคงจฉ'[option]}.`,
                );
            }
            const selected = correct === null ? 'A' : correct ? answer : answer === 'A' ? 'B' : 'A';
            await question.locator(`input[value="${selected}"]`).check();
            if (optionCount === 6) {
                await question.screenshot({
                    path: path.join(
                        process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                        `${name}-six-choices-${info.project.name}.png`,
                    ),
                });
            }
        }
        await expect(page.getByRole('button', { name: 'ส่งข้อสอบและดูผล' })).toBeEnabled();
        await page.evaluate(() => window.scrollTo(0, 0));
        await screenshot(`exam-${name}`);
        await page.getByRole('button', { name: 'ส่งข้อสอบและดูผล' }).click();
        await page.getByRole('button', { name: 'ยืนยันส่งข้อสอบ', exact: true }).click();
        await expect(page.locator('.exam-result h2')).toContainText('บันทึกผลสอบแล้ว');
        if (correct !== null)
            await expect(page.locator('.exam-result .result-score')).toHaveText(
                correct ? '100.00%' : '0.00%',
            );
        await screenshot(`exam-${name}-result`);
        await page.getByRole('link', { name: 'กลับไปเรียนและดูผลสรุป' }).click();
    };
    try {
        await login(page, '0000000000001');
        await page.goto('/manage/courses');
        await page.getByText('เพิ่มหลักสูตร', { exact: true }).click();
        await page
            .getByLabel('หมวดหลักสูตร', { exact: true })
            .selectOption(String(main.category_id));
        await page.getByLabel('รหัสหลักสูตร').fill(code);
        await page.locator('input[name="course-title"]').fill(title);
        await page.getByRole('button', { name: 'สร้างฉบับร่าง' }).click();
        await expect(page).toHaveURL(/\/manage\/courses\/\d+\/edit$/);
        const fixtureId = Number(page.url().match(/courses\/(\d+)/)![1]);
        await page.getByRole('button', { name: 'บทเรียน', exact: true }).click();
        await page.getByText('เพิ่มหมวดบทเรียน', { exact: true }).first().click();
        await page.locator('input[name="new-module-title"]').fill('ทดสอบเอกสารประกอบ');
        await page.getByRole('button', { name: 'เพิ่มหมวดบทเรียน', exact: true }).click();
        await page
            .locator('summary')
            .filter({ hasText: /^เพิ่มบทเรียน$/ })
            .click();
        await page.locator('input[name^="new-lesson-title-"]').fill('บททดสอบพร้อมเอกสาร');
        await page.getByRole('button', { name: 'เพิ่มบทเรียน', exact: true }).click();
        await page
            .locator('.editor-outline button')
            .filter({ hasText: 'บททดสอบพร้อมเอกสาร' })
            .click();
        await page.getByText('แนบเนื้อหาและเอกสาร', { exact: true }).click();
        await page.locator('input[name^="content-title-"]').fill('บทความทดสอบระบบ');
        await page.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('article');
        await page
            .getByLabel('บทความ (รองรับหัวข้อ ย่อหน้า และรายการ HTML)')
            .fill('<p>ทดสอบระบบเรียน <strong>อ่านแล้วจึงยืนยัน</strong></p>');
        await page.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(page.getByText('บทความทดสอบระบบ', { exact: true })).toBeVisible();
        await page.locator('input[name^="content-title-"]').fill('PDF ประกอบบททดสอบ');
        await page.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('document');
        await page.getByLabel('เลือกไฟล์ (ไม่เกิน 100 MB)').setInputFiles(pdf);
        await page.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(page.getByText('PDF ประกอบบททดสอบ', { exact: true })).toBeVisible();
        const addLesson = async (name: string, index: number) => {
            const form = page
                .locator('form')
                .filter({ has: page.locator('input[name^="new-lesson-title-"]') });
            await form.locator('input[name^="new-lesson-title-"]').fill(name);
            await form.getByRole('button', { name: 'เพิ่มบทเรียน', exact: true }).click();
            const editor = page.locator('.lesson-editor').nth(index);
            await page.locator('.editor-outline button').filter({ hasText: name }).click();
            await editor.getByText('แนบเนื้อหาและเอกสาร', { exact: true }).click();
            return editor;
        };
        const remoteEditor = await addLesson('บท 2 วิดีโอลิงก์ไม่มีเอกสาร', 1);
        await remoteEditor
            .locator('input[name^="content-title-"]')
            .fill('MP4 URL ทดสอบการรับชมจริง');
        await remoteEditor.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('video');
        await remoteEditor.getByLabel('แหล่งวิดีโอ').selectOption('remote');
        await remoteEditor
            .getByLabel('ลิงก์ HTTPS')
            .fill('https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4');
        await remoteEditor.getByLabel('ความยาววิดีโอจริง (วินาที ปัดขึ้น)').fill('6');
        await remoteEditor.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(
            remoteEditor.getByText('MP4 URL ทดสอบการรับชมจริง', { exact: true }),
        ).toBeVisible();
        await remoteEditor
            .locator('input[name^="content-title-"]')
            .fill('YouTube ทดสอบตัวเล่นจริง');
        await remoteEditor.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('video');
        await remoteEditor.getByLabel('แหล่งวิดีโอ').selectOption('youtube');
        await remoteEditor
            .getByLabel('ลิงก์ HTTPS')
            .fill('https://www.youtube.com/watch?v=M7lc1UVf-VE');
        await remoteEditor.getByLabel('ความยาววิดีโอจริง (วินาที ปัดขึ้น)').fill('1344');
        await remoteEditor.getByLabel('ต้องเรียนเนื้อหานี้เพื่อจบบท').uncheck();
        await remoteEditor.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(
            remoteEditor.getByText('YouTube ทดสอบตัวเล่นจริง', { exact: true }),
        ).toBeVisible();
        const imageEditor = await addLesson('บท 3 ภาพและลิงก์ประกอบ', 2);
        const png = await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 360;
            canvas.height = 160;
            const ctx = canvas.getContext('2d')!;
            ctx.fillStyle = '#e8f7f3';
            ctx.fillRect(0, 0, 360, 160);
            ctx.fillStyle = '#0d735b';
            ctx.font = '24px sans-serif';
            ctx.fillText('SMH Learning — Test image', 15, 85);
            return canvas.toDataURL('image/png').split(',')[1]!;
        });
        await imageEditor.locator('input[name^="content-title-"]').fill('ภาพประกอบบททดสอบ');
        await imageEditor.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('image');
        await imageEditor.getByLabel('เลือกไฟล์ (ไม่เกิน 100 MB)').setInputFiles({
            name: 'lesson-image.png',
            mimeType: 'image/png',
            buffer: Buffer.from(png, 'base64'),
        });
        await imageEditor.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(imageEditor.getByText('ภาพประกอบบททดสอบ', { exact: true })).toBeVisible();
        await imageEditor.locator('input[name^="content-title-"]').fill('ลิงก์ประกอบบททดสอบ');
        await imageEditor.getByLabel('ชนิดเนื้อหา', { exact: true }).selectOption('link');
        await imageEditor
            .getByLabel('ลิงก์ HTTPS')
            .fill('https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/video');
        await imageEditor.getByLabel('ต้องเรียนเนื้อหานี้เพื่อจบบท').uncheck();
        await imageEditor.getByRole('button', { name: 'แนบเนื้อหา', exact: true }).click();
        await expect(imageEditor.getByText('ลิงก์ประกอบบททดสอบ', { exact: true })).toBeVisible();
        await expect(imageEditor.getByText('ภาพประกอบบททดสอบ', { exact: true })).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'โครงสร้างบทเรียน · 3 บท', exact: true }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'เผยแพร่เวอร์ชัน', exact: true }).click();
        await page.getByRole('button', { name: 'ยืนยันเผยแพร่เวอร์ชัน', exact: true }).click();
        await page.getByRole('button', { name: 'ภาพรวม', exact: true }).click();
        await expect(page.getByText('เนื้อหาเวอร์ชันนี้ตรึงแล้ว', { exact: false })).toBeVisible();
        await page.getByRole('button', { name: 'รอบเรียนและผู้เรียน', exact: true }).click();
        await page
            .locator('summary')
            .filter({ hasText: /^เพิ่มรอบเรียน$/ })
            .click();
        await page.locator('input[name="session-name"]').fill('รอบทดสอบเบราว์เซอร์');
        await page.getByLabel('วันที่สิ้นสุด').fill('2027-10-07');
        await page.getByLabel('วิดีโอที่ต้องดู (%)').fill('100');
        await expect(page.getByLabel('จำนวนครั้งสอบ Posttest รวมทั้งหมด')).toHaveValue('1');
        await page.getByRole('button', { name: 'สร้างรอบเรียน', exact: true }).click();
        await expect(
            page.getByRole('heading', { name: 'รอบทดสอบเบราว์เซอร์', exact: true }),
        ).toBeVisible();
        await screenshot('course-editor');
        await page.getByRole('button', { name: 'ข้อสอบ', exact: true }).click();
        await page
            .getByRole('link', { name: 'นำเข้าข้อสอบ Excel และตั้งค่าชุดสอบ', exact: true })
            .click();
        await importQuestions();
        for (const settings of await page.locator('.session-editor').all()) {
            await settings.locator('select').selectOption('published');
            await settings.getByRole('button', { name: 'บันทึกการตั้งค่าชุดสอบ' }).click();
        }
        await screenshot('question-bank');
        await page.goto(`/manage/courses/${fixtureId}/edit`);
        await page.getByRole('button', { name: 'รอบเรียนและผู้เรียน', exact: true }).click();
        await page.getByRole('button', { name: 'เปิดรอบเรียน', exact: true }).click();
        await page.getByRole('button', { name: 'ยืนยันเปิดรอบเรียน', exact: true }).click();
        await expect(page.getByRole('button', { name: 'ปิดรอบเรียน', exact: true })).toBeVisible();
        if (main.question_count === 0) {
            await page.goto(`/versions/${main.version_id}/questions`);
            await importQuestions();
        }
        await page.goto(`/categories/${main.category_id}/edit`);
        const instructor = page.getByRole('checkbox', { name: 'ผู้ใช้งาน02 ระบบ', exact: true });
        priorScope = await instructor.isChecked();
        await instructor.check();
        await page.getByRole('button', { name: 'บันทึกหมวดและผู้คุม' }).click();
        scopeChanged = true;
        await logout(page);
        await login(page, '0000000000002');
        await page.goto(`/manage/courses/${main.course_id}/edit`);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('วิชา ระเบียบวิธีวิจัย');
        await screenshot('instructor');
        await page.getByRole('button', { name: 'ข้อสอบ', exact: true }).click();
        await page
            .getByRole('link', { name: 'นำเข้าข้อสอบ Excel และตั้งค่าชุดสอบ', exact: true })
            .click();
        await expect(page.locator('tbody tr')).toHaveCount(20);
        await page.goto(`/manage/courses/${fixtureId}/edit`);
        await page.getByRole('button', { name: 'รอบเรียนและผู้เรียน', exact: true }).click();
        await page.getByLabel('จำนวนครั้ง Posttest รวมทั้งหมด (ค่าเริ่มต้น 1)').fill('3');
        await page.getByRole('button', { name: 'บันทึกจำนวนครั้งสอบ' }).click();
        await expect(page.getByLabel('จำนวนครั้ง Posttest รวมทั้งหมด (ค่าเริ่มต้น 1)')).toHaveValue(
            '3',
        );
        await logout(page);
        await login(page, '0000000000001', false);
        await page.goto(`/categories/${main.category_id}/edit`);
        await page
            .getByRole('checkbox', { name: 'ผู้ใช้งาน02 ระบบ', exact: true })
            .setChecked(priorScope);
        await page.getByRole('button', { name: 'บันทึกหมวดและผู้คุม' }).click();
        scopeChanged = false;
        await logout(page);
        const number = info.project.name.startsWith('desktop')
            ? '5'
            : info.project.name.startsWith('tablet')
              ? '6'
              : '7';
        await login(page, `000000000000${number}`);
        await page.goto(`/courses/${main.course_id}`);
        if (await page.getByRole('button', { name: 'สมัครเรียน', exact: true }).count())
            await page.getByRole('button', { name: 'สมัครเรียน', exact: true }).click();
        else await page.getByRole('link', { name: 'เข้าสู่บทเรียน', exact: true }).click();
        await expect(page).toHaveURL(/\/learning\/\d+$/);
        const learningUrl = page.url();
        if (await page.getByRole('button', { name: 'ทำ Pretest ก่อนเรียน' }).count()) {
            await expect(page.locator('video')).toHaveCount(0);
            await page.getByRole('button', { name: 'ทำ Pretest ก่อนเรียน' }).click();
            await takeExam(null, 'main-pretest');
        }
        const video = page.locator('video');
        await expect(page).toHaveTitle(/วิชา ระเบียบวิธีวิจัย/);
        await expect
            .poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState))
            .toBeGreaterThanOrEqual(1);
        expect(await video.evaluate((v: HTMLVideoElement) => v.duration)).toBeGreaterThan(1000);
        const mediaUrl = await video.getAttribute('src');
        expect(await video.evaluate((v: HTMLVideoElement) => v.controls)).toBe(false);
        await expect(page.locator('.pdf-canvas').first()).toHaveAttribute('data-rendered', 'true');
        const range = await page
            .context()
            .request.get(mediaUrl!, { headers: { Range: 'bytes=0-1023' } });
        expect(range.status()).toBe(206);
        expect((await range.body()).length).toBe(1024);
        expect(range.headers()['content-type']).toContain('video/mp4');
        const initialPosition = await video.evaluate((v: HTMLVideoElement) => v.currentTime);
        const heartbeat = page.waitForResponse(
            (response) =>
                response.url().endsWith('/heartbeat') &&
                response.request().method() === 'POST' &&
                response.status() === 200 &&
                Number(response.request().postDataJSON().position) >= initialPosition + 4,
            { timeout: 25000 },
        );
        await video.evaluate((v: HTMLVideoElement) => {
            v.muted = true;
        });
        await page.getByRole('button', { name: 'เล่นวิดีโอ', exact: true }).click();
        await heartbeat;
        await expect
            .poll(
                async () =>
                    Number(
                        await page
                            .getByRole('progressbar', { name: 'ความก้าวหน้าการรับชม', exact: true })
                            .getAttribute('value'),
                    ),
                { timeout: 20000 },
            )
            .toBeGreaterThan(0);
        await video.evaluate((v: HTMLVideoElement) => v.pause());
        const continuous = await video.evaluate((v: HTMLVideoElement) => v.currentTime);
        await video.evaluate((v: HTMLVideoElement) => {
            v.currentTime += 30;
        });
        await expect
            .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
            .toBeLessThanOrEqual(continuous + 0.5);
        await video.evaluate((v: HTMLVideoElement) => {
            v.currentTime = Math.max(0, v.currentTime - 5);
        });
        await expect
            .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
            .toBeGreaterThanOrEqual(continuous - 0.5);
        await screenshot('video');
        const saved = await video.evaluate((v: HTMLVideoElement) => v.currentTime);
        await page.reload();
        await expect
            .poll(() => video.evaluate((v: HTMLVideoElement) => v.currentTime))
            .toBeGreaterThanOrEqual(Math.max(0.1, saved - 5));
        const docs = page.locator('.lesson-choice').filter({ hasText: 'เอกสารการเรียน' });
        const outlineToggle = page.getByRole('button', { name: /^รายการบทเรียน ·/ });
        if (await outlineToggle.isVisible()) {
            await outlineToggle.click();
            await expect(
                page
                    .getByRole('dialog', { name: 'รายการบทเรียน', exact: true })
                    .locator('.lesson-choice')
                    .filter({ hasText: 'เอกสารการเรียน' })
                    .first(),
            ).toBeDisabled();
            await page.keyboard.press('Escape');
        } else {
            await expect(docs.first()).toBeVisible();
            await expect(docs.first()).toBeDisabled();
        }
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeDisabled();
        const pdfLink = page.getByRole('link', { name: 'เปิดเอกสาร PDF', exact: true });
        const pdfResponse = await page.context().request.get((await pdfLink.getAttribute('href'))!);
        expect(pdfResponse.status()).toBe(200);
        expect((await pdfResponse.body()).subarray(0, 4).toString()).toBe('%PDF');
        const sha = createHash('sha256').update(readFileSync(pdf)).digest('hex');
        const directMedia = await page
            .context()
            .request.get(`/_protected_media/media/${sha.slice(0, 2)}/${sha}.pdf`);
        expect(directMedia.status()).toBe(404);
        const download = page.waitForEvent('download', { timeout: 15000 });
        await page.getByRole('link', { name: 'ดาวน์โหลดเอกสาร', exact: true }).click();
        expect((await download).suggestedFilename()).toMatch(/\.pdf$/i);
        if (await page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).count()) {
            await page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).click();
            await expect(
                page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }),
            ).toHaveCount(0);
        }
        await screenshot('document');
        expect(
            (await page.context().request.get(`/versions/${main.version_id}/questions`)).status(),
        ).toBe(403);
        await page.goto(`/courses/${fixtureId}`);
        await page.getByRole('button', { name: 'สมัครเรียน', exact: true }).click();
        await expect(page.getByRole('button', { name: 'เปิดอ่านบทความ' })).toHaveCount(0);
        await page.getByRole('button', { name: 'ทำ Pretest ก่อนเรียน' }).click();
        await takeExam(false, 'fixture-pretest');
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeDisabled();
        await expect(page.locator('.lesson-choice').nth(1)).toBeDisabled();
        await page.getByRole('button', { name: 'เปิดอ่านบทความ', exact: true }).click();
        await expect(page.locator('.article-body strong')).toHaveText('อ่านแล้วจึงยืนยัน');
        await page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).first().click();
        await expect(
            page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }),
        ).toHaveCount(1);
        expect(
            await page
                .getByRole('progressbar', { name: 'ความก้าวหน้าหลักสูตร', exact: true })
                .getAttribute('value'),
        ).toBe('0');
        const file = await page
            .context()
            .request.get(
                (await page
                    .getByRole('link', { name: 'เปิดเอกสาร PDF', exact: true })
                    .getAttribute('href'))!,
            );
        expect(file.status()).toBe(200);
        await page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).click();
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeEnabled();
        await expect(
            page.getByRole('progressbar', { name: 'ความก้าวหน้าหลักสูตร', exact: true }),
        ).toHaveAttribute('value', '33.33');
        await screenshot('sequential-unlocked');
        const fixtureLearning = page.url().split('?')[0]!;
        await page.goto(`/courses/${fixtureId}`);
        const documentSection = page.locator('section').filter({
            has: page.getByRole('heading', {
                name: 'เอกสารประกอบการเรียนทั้งหลักสูตร',
                exact: true,
            }),
        });
        await expect(
            documentSection.getByRole('link', { name: 'ดาวน์โหลด', exact: true }),
        ).toHaveCount(2);
        await expect(
            documentSection.getByRole('link', { name: 'เปิดลิงก์', exact: true }),
        ).toHaveCount(1);
        const imageDownload = page.waitForEvent('download');
        await documentSection.getByRole('link', { name: 'ดาวน์โหลด', exact: true }).nth(1).click();
        expect((await imageDownload).suggestedFilename()).toBe('lesson-image.png');
        await screenshot('all-documents');
        await page.goto(fixtureLearning);
        await expect(page.locator('.lesson-main h2')).toHaveText('บท 2 วิดีโอลิงก์ไม่มีเอกสาร');
        await expect(page.locator('.pdf-canvas')).toHaveCount(0);
        const remote = page.locator('video');
        await expect
            .poll(() => remote.evaluate((element: HTMLVideoElement) => element.readyState), {
                timeout: 30000,
            })
            .toBeGreaterThanOrEqual(1);
        expect(
            Math.ceil(await remote.evaluate((element: HTMLVideoElement) => element.duration)),
        ).toBe(6);
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeDisabled();
        await remote.evaluate((element: HTMLVideoElement) => {
            element.muted = true;
        });
        await page
            .locator('.learning-video')
            .filter({ has: page.locator('video') })
            .getByRole('button', { name: 'เล่นวิดีโอ', exact: true })
            .click();
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeEnabled({
            timeout: 25000,
        });
        await expect(
            page.getByRole('progressbar', { name: 'ความก้าวหน้าการรับชม', exact: true }).first(),
        ).toHaveAttribute('value', '100');
        await screenshot('remote-video-completed');
        await page.getByRole('button', { name: 'ดูซ้ำตั้งแต่ต้น', exact: true }).click();
        await expect
            .poll(() => remote.evaluate((element: HTMLVideoElement) => element.currentTime))
            .toBeLessThan(0.5);
        await expect(page.getByRole('button', { name: 'บทถัดไป', exact: true })).toBeEnabled();
        const youtubePlayer = page
            .locator('.learning-video')
            .filter({ has: page.locator('.youtube-frame') });
        await expect(youtubePlayer.locator('.youtube-frame')).toHaveAttribute(
            'data-ready',
            'true',
            { timeout: 30000 },
        );
        const youtubeSrc = await youtubePlayer.locator('iframe').getAttribute('src');
        expect(youtubeSrc).toContain('controls=0');
        expect(youtubeSrc).toContain('disablekb=1');
        await youtubePlayer.getByRole('button', { name: 'ปิดเสียง', exact: true }).click();
        const youtubeHeartbeat = page.waitForResponse(
            (response) =>
                response.url().endsWith('/heartbeat') &&
                response.status() === 200 &&
                Number(response.request().postDataJSON().duration) > 1300 &&
                Number(response.request().postDataJSON().position) >= 4,
            { timeout: 30000 },
        );
        await youtubePlayer.getByRole('button', { name: 'เล่นวิดีโอ', exact: true }).click();
        await youtubeHeartbeat;
        await youtubePlayer.getByRole('button', { name: 'หยุดชั่วคราว', exact: true }).click();
        await expect(youtubePlayer.getByRole('progressbar')).not.toHaveAttribute('value', '0');
        await youtubePlayer.locator('.youtube-frame').scrollIntoViewIfNeeded();
        await youtubePlayer.locator('.youtube-frame').screenshot({
            path: path.join(
                process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                `phase4-youtube-frame-${info.project.name}.png`,
            ),
        });
        await screenshot('youtube-playing');
        await page.getByRole('button', { name: 'บทถัดไป', exact: true }).click();
        await expect(page.locator('.lesson-main h2')).toHaveText('บท 3 ภาพและลิงก์ประกอบ');
        await expect
            .poll(() =>
                page
                    .locator('.learning-image')
                    .evaluate((image: HTMLImageElement) => image.naturalWidth),
            )
            .toBe(360);
        await expect(
            page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).first(),
        ).toBeEnabled();
        await screenshot('image-attachment');
        await page.getByRole('button', { name: 'ยืนยันว่าอ่านแล้ว', exact: true }).first().click();
        await expect(
            page.getByText('เรียนครบตามเกณฑ์แล้ว ต้องส่ง Posttest อย่างน้อย 1 ครั้งก่อนจบหลักสูตร'),
        ).toBeVisible();
        await expect(
            page.getByRole('progressbar', { name: 'ความก้าวหน้าหลักสูตร', exact: true }),
        ).toHaveAttribute('value', '100');
        const enrollmentId = Number(page.url().match(/learning\/(\d+)/)![1]);
        const csrf = (await page.context().cookies()).find(
            (cookie) => cookie.name === 'XSRF-TOKEN',
        )!;
        const simultaneous = await Promise.all(
            [1, 2].map(() =>
                page.context().request.post(`/learning/${enrollmentId}/exams/start`, {
                    data: { exam_type: 'POSTTEST' },
                    headers: {
                        'X-XSRF-TOKEN': decodeURIComponent(csrf.value),
                        Accept: 'application/json',
                    },
                    maxRedirects: 0,
                }),
            ),
        );
        expect(simultaneous.map((response) => response.status())).toEqual([302, 302]);
        expect(simultaneous[0]!.headers()['location']).toBe(simultaneous[1]!.headers()['location']);
        await page.goto(simultaneous[0]!.headers()['location']!);
        await takeExam(false, 'posttest-zero');
        await expect(
            page.getByText('เรียนจบตามเกณฑ์ของรอบเรียนนี้แล้ว', { exact: true }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'ทำ Posttest อีกครั้ง', exact: true }).click();
        await takeExam(true, 'posttest-best');
        await page.getByRole('button', { name: 'ทำ Posttest อีกครั้ง', exact: true }).click();
        await takeExam(false, 'posttest-lower');
        await expect(
            page.getByText('คะแนน Posttest สูงสุด 100.00%', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'ทำ Posttest อีกครั้ง', exact: true }),
        ).toBeDisabled();
        await screenshot('completed');
        await page.goto(learningUrl);
        await expect
            .poll(() => page.locator('video').evaluate((v: HTMLVideoElement) => v.readyState))
            .toBeGreaterThanOrEqual(1);
        await page.getByRole('button', { name: 'หน้าถัดไป', exact: true }).click();
        await expect(page.locator('.pdf-reader')).toHaveAttribute('aria-busy', 'false');
        await expect(page.locator('.pdf-canvas')).toHaveAttribute('aria-label', /หน้า 2$/);
        await screenshot('pdf-next-page');
        await page.getByRole('button', { name: 'หน้าก่อน', exact: true }).click();
        await expect(page.locator('.pdf-reader')).toHaveAttribute('aria-busy', 'false');
        await expect(
            page.locator('.lesson-choice').filter({ has: page.getByText('บท3', { exact: true }) }),
        ).toBeDisabled();
        await screenshot('final-learning');
        await page.goto('/points');
        await expect(page).toHaveTitle(/แต้มของฉัน/);
        await expect(page.getByTestId('point-balance')).toHaveText('60');
        await expect(page.locator('tbody tr')).toHaveCount(3);
        await screenshot('points');
        await page.goto(`/reports?audience=mine&course_id=${fixtureId}`);
        await expect(page).toHaveTitle(/รายงานผลการเรียน/);
        await expect(page.locator('.report-table tbody tr')).toHaveCount(1);
        await expect(page.locator('.report-table tbody tr').first()).toContainText('100');
        await screenshot('reports-individual');
        for (const format of ['xlsx', 'csv', 'pdf']) {
            await page
                .getByRole('button', { name: `ส่งออก ${format.toUpperCase()}`, exact: true })
                .click();
            await expect
                .poll(
                    async () => {
                        await page
                            .getByRole('button', { name: 'ตรวจสถานะไฟล์', exact: true })
                            .click();
                        return page
                            .getByRole('link', {
                                name: `ดาวน์โหลด ${format.toUpperCase()}`,
                                exact: true,
                            })
                            .count();
                    },
                    { timeout: 45000, intervals: [500, 1000, 2000] },
                )
                .toBe(1);
            const downloaded = page.waitForEvent('download');
            await page
                .getByRole('link', { name: `ดาวน์โหลด ${format.toUpperCase()}`, exact: true })
                .click();
            const file = await downloaded;
            expect(await file.failure()).toBeNull();
            await file.saveAs(
                path.join(
                    process.env.SMH_SCREENSHOT_DIR ?? os.tmpdir(),
                    `phase5-individual-${info.project.name}.${format}`,
                ),
            );
        }
        await screenshot('reports-exports');
        await logout(page);
        await login(page, '0000000000001', false);
        await page.goto('/admin/points');
        await expect(page).toHaveTitle(/จัดการแต้ม/);
        await expect(page.locator('.rule-card')).toHaveCount(3);
        await screenshot('points-admin');
        for (const [points, action] of [
            ['5', 'ปรับแต้ม'],
            ['-5', 'ชดเชยแต้ม'],
        ]) {
            const target = page.getByLabel('บุคลากร', { exact: true });
            const value = await target
                .locator('option')
                .filter({ hasText: `ผู้ใช้งาน0${number} ระบบ` })
                .getAttribute('value');
            await target.selectOption(value!);
            await page.getByLabel('แต้มที่เพิ่ม / ลด').fill(points!);
            await page
                .getByLabel('เหตุผล (อย่างน้อย 5 ตัวอักษร)')
                .fill(`QA ${code} ${action}ผ่านหน้าจอ`);
            const adjustmentRequest = page.waitForRequest(
                (request) =>
                    request.url().includes('/admin/points/users/') && request.method() === 'POST',
            );
            await page.getByRole('button', { name: 'บันทึกการปรับแต้ม', exact: true }).click();
            await page.getByRole('button', { name: 'ยืนยันปรับแต้ม', exact: true }).click();
            const savedRequest = await adjustmentRequest;
            const body = savedRequest.postDataJSON();
            await expect(
                page.getByText('บันทึกการปรับแต้มและเหตุผลแล้ว', { exact: true }),
            ).toBeVisible();
            const adjustmentCsrf = (await page.context().cookies()).find(
                (cookie) => cookie.name === 'XSRF-TOKEN',
            )!;
            const repeated = await Promise.all(
                [1, 2].map(() =>
                    page.context().request.post(savedRequest.url(), {
                        data: body,
                        headers: {
                            'X-XSRF-TOKEN': decodeURIComponent(adjustmentCsrf.value),
                            Accept: 'application/json',
                        },
                        maxRedirects: 0,
                    }),
                ),
            );
            expect(repeated.map((response) => response.status())).toEqual([302, 302]);
        }
        await expect(page.locator('tbody tr')).toHaveCount(5);
        await screenshot('points-adjusted');
        await page.goto(`/reports?course_id=${fixtureId}&type=question&exam_type=POSTTEST`);
        await expect(page.locator('.report-table tbody tr')).toHaveCount(2);
        await screenshot('reports-questions');
        for (const type of ['course', 'department']) {
            await page.getByRole('button', { name: 'ตัวกรองรายงาน', exact: true }).click();
            await page.getByLabel('รูปแบบรายงาน').selectOption(type);
            await page.getByRole('button', { name: 'แสดงรายงาน', exact: true }).click();
            await expect(page.locator('.report-table tbody tr')).toHaveCount(1);
            await screenshot(`reports-${type}`);
        }
        await page.goto('/dashboard');
        await page.getByRole('button', { name: 'งานตามสิทธิ์ของฉัน', exact: true }).click();
        await expect(
            page.getByRole('heading', { name: 'ผลการเรียนตามขอบเขตสิทธิ์' }),
        ).toBeVisible();
        await screenshot('dashboard-analytics');
        expect(errors).toEqual([]);
    } finally {
        await info.attach('youtube-provider-diagnostics', {
            body: JSON.stringify(providerDiagnostics, null, 2),
            contentType: 'application/json',
        });
        if (scopeChanged) {
            await login(page, '0000000000001', false);
            await page.goto(`/categories/${main.category_id}/edit`);
            await page
                .getByRole('checkbox', { name: 'ผู้ใช้งาน02 ระบบ', exact: true })
                .setChecked(priorScope);
            await page.getByRole('button', { name: 'บันทึกหมวดและผู้คุม' }).click();
        }
        artisan('dev:reset-learning-qa', code);
        artisan('dev:seed-demo', '--reset-passwords');
    }
});
