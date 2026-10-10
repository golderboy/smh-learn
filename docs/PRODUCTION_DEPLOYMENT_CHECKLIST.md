# Checklist ก่อน STAGING / PRODUCTION

เอกสารเตรียมย้ายระบบ Phase 0–6 จาก PC เท่านั้น ยังไม่ Deploy จริง และ ThaiD จะทดสอบจริงหลังมีเซิร์ฟเวอร์/RP/HTTPS ตามที่ผู้ใช้กำหนด

## ข้อมูลที่เจ้าของระบบต้องกำหนด

- [ ] เซิร์ฟเวอร์/OS/CPU/RAM/disk/network/domain/HTTPS และผู้ดูแล
- [ ] จำนวนบัญชีเริ่มต้น/เติบโต (เป้าหมายออกแบบ 1,000 คน), concurrent learners/exam submits/video viewers และ p95/error-rate ที่ยอมรับ
- [ ] เวลาเก็บข้อมูล/ไฟล์/รายงาน/audit และสิทธิ์ผู้ดูแลตามนโยบายองค์กร
- [ ] RPO (ข้อมูลย้อนหลังที่สูญหายได้) / RTO (เวลาคืนระบบได้) / ตำแหน่ง backup เข้ารหัสและ offsite / ผู้ถือ APP_KEY และกุญแจ backup
- [ ] ผู้ใช้จริง Admin/Instructor/หัวหน้าแผนก/ผู้เรียนสำหรับ UAT และผู้อนุมัติเปิดใช้งาน

## แยก environment และ build

- [ ] DEV/STAGING/PRODUCTION ใช้ DB, Redis prefix/session cookie, uploads, APP_KEY, secrets และ endpoint คนละชุด ไม่ชี้ทั้งสามชุดเข้าข้อมูลเดียวกัน
- [ ] Build ตาม lock files: Node 24 / PHP 8.4 / Laravel dependencies ที่ผ่าน suite ใช้ `npm ci`/`npm run build` และ `composer install --no-dev --prefer-dist --optimize-autoloader` สำหรับ release
- [ ] แอป release ไม่ bind source DEV, `.env`, demo-learn, node_modules, tests, .backups หรือ Git เข้า public document root; Nginx root ต้องเป็น `public` และ internal media ต้องอ่าน uploads ที่ private
- [ ] ตั้งค่าจาก `.env.production.example` ด้วย secrets จริงนอก Git APP_ENV=production APP_DEBUG=false HTTPS secure HttpOnly encrypted session SameSite=lax, DB application user ไม่ใช่ root
- [ ] เซิร์ฟเวอร์ต้องมี PHP extensions ตาม `composer check-platform-reqs`, writable storage/bootstrap cache โดย service user, upload limit, php-fpm/OPcache ที่เหมาะกับ RAM ใช้ immutable release หากตั้ง opcache.validate_timestamps=0
- [ ] HTTPS redirect/SSL renewal/trusted host/trusted reverse proxy ต้องตรง topology จริง ห้าม trust proxy ทุก IP โดยไม่มีเหตุผล; ตรวจ secure cookie และ HSTS จาก browser จริง
- [ ] ไม่รันคำสั่ง `dev:*` บน Production ไม่ยกบัญชีจำลอง 10 คนขึ้นจริง การตั้งบุคลากรจริงและ Admin ต้องตรวจตัวตน/สิทธิ์กับองค์กรก่อน
- [ ] รัน additive `php artisan migrate --force` หลัง backup/restore-tested จาก schema ที่มีทะเบียน migrations ห้าม `migrate:fresh`, wipe หรือ down -v
- [ ] หลัง migrate ใช้ `php artisan db:seed --force` (`DatabaseSeeder` เรียกเฉพาะ `FoundationSeeder` และ `RootAdministratorSeeder`) เพื่อสร้าง catalog และบัญชีตั้งต้น `admins`; ไม่ใช้ `DemoUserSeeder` บน Production
- [ ] เจ้าของบัญชี `admins` เปลี่ยนรหัสผ่านเริ่มต้นก่อนเปิดบริการ บัญชีนี้ห้ามแก้ชื่อ ลดสิทธิ์ ปิด หรือนำเข้า Excel ทับ และ seeder รอบถัดไปต้องไม่รีเซ็ตรหัสผ่าน
- [ ] นำเข้าข้อมูลจริงจากเทมเพลตทั้ง 5 หัวข้อ ตรวจแผนก/ตำแหน่งก่อนผู้ใช้ ผู้ใช้ใหม่เป็นผู้เรียน รหัสแรก `learn123456` และข้ามเลขประจำตัวที่มีอยู่แล้ว ดู `MASTER_DATA_IMPORT_GUIDE.md`
- [ ] `php artisan config:cache`, `route:cache`, `view:cache` ต้องผ่านและไม่รวมค่า DEV จาก config cache เก่า จากนั้น restart worker/scheduler ให้ใช้ release ใหม่
- [ ] `php artisan ops:release-check --json` ผ่านบน environment จริง, ไม่มี /dev diagnostics/Vite hot server/demo accounts, local login/session revoke/media scope ยังทำงาน

## Security / backup / monitor

- [ ] รัน dependency audits และ security regression อีกครั้งก่อน release ตรวจ CSP resource report-only กับแหล่งสื่อจริงเพื่อเลือก policy ที่บังคับใช้ ไม่ให้ inline script/eval โดยไม่จำเป็น
- [ ] ตรวจ XSS/article sanitizer, unsafe URL/file spoofing, IDOR ของ media/export/attempt, CSRF, session reset, default password change, Instructor category revoke และ ledger transactions จาก UAT
- [ ] ใช้ shared initial password ตามข้อกำหนดปัจจุบันและบังคับเปลี่ยนก่อนใช้งาน จำกัดการแจกบัญชี/รหัสเริ่มต้น การเปลี่ยนนโยบายต้องตกลงกับผู้ใช้ ไม่เปลี่ยนเอง
- [ ] ตรวจทุก access/error log, tracing/APM/export ไม่เก็บ username PID/password/token/callback query/secret รวม reverse proxy นอกโค้ดโครงการด้วย
- [ ] DB/Redis ไม่เปิด public; secret runtime user/DB backup operator แยกและ least privilege; firewall/storage ACL/encryption ตามระบบจริง
- [ ] ตั้ง daily DB+uploads backup, 7 daily/4 weekly/12 monthly และ offsite; ตรวจ hashes/backup age; เก็บ APP_KEY/กุญแจ decrypt แยกอย่างปลอดภัย ห้ามคิดว่า checksum เท่ากับ encryption/authenticity
- [ ] restore สำเนาจริงไป environment แยก ตรวจ hashes/จำนวน users/courses/progress/attempts/score/ledger/audit/media แล้วทดสอบ login→เรียน→สอบ→export ที่กู้คืนอีกครั้ง เก็บหลักฐานเวลาเทียบ RTO/RPO
- [ ] ตั้ง scheduled backup/retention/restore-drill ของเซิร์ฟเวอร์จริง เครื่องมือ `ops-dev.py` ในรอบนี้ตั้ง guard ให้ใช้ DEV เท่านั้น ต้องจัด adapter/job และสิทธิ์ตรงเซิร์ฟเวอร์ก่อนใช้ข้อมูลจริง
- [ ] ตั้ง supervisor/container restart policy สำหรับ PHP-FPM/worker/scheduler/DB/Redis และ log rotation/พื้นที่พอ worker timeout180 ต้องต่ำกว่า Redis retry_after240
- [ ] Uptime `/up` + private `ops:check --queue --backup --tls`, CPU/RAM/disk/HTTP5xx+p95/queue backlog/failedjobs/scheduler/backup/SSL และเจ้าของ alert ผ่านการจำลองเหตุขัดข้อง

## Load / UAT / cutover / rollback

- [ ] ทดสอบ authenticated mixed workload ตามจำนวน concurrent ที่ตกลง ไม่ถือว่า 50 guest requests หรือ 1,000 enrollment fixtures เป็นหลักฐานการสอบพร้อมกัน 1,000 คน
- [ ] รวม exam autosave/submission/retake limit/idempotency, reports/queued exports และ video bandwidth/YouTube dependency พร้อมติดตาม metrics
- [ ] UAT ใน `UAT_PHASE0_6.md` ลงชื่อรับผลตามหน้าที่จริง แก้ defect ก่อนเปิด
- [ ] ระบุ release/build/migration IDs, downtime, backup ID ที่ restore ได้, ผู้อนุมัติ cutover และช่องทางแจ้งเหตุ
- [ ] Rollback application ไป release ก่อนหน้าทำได้เมื่อ schema compatible หากต้องย้อน DB ต้องตัดสินใจเรื่องข้อมูลใหม่หลัง backup กับเจ้าของข้อมูลก่อน ห้าม restore ทับข้อมูลใหม่เอง
- [ ] Smoke test หลัง deploy: login, permissions, required pretest, sequential lesson/media, posttest, highest score/points, exports, monitor/backup
- [ ] ThaiD เปิดหลัง production acceptance ของ integration ตาม `THAID_INTEGRATION_GUIDE.md` เท่านั้น ยังไม่ถือว่าเตรียม Client ID แล้วเท่ากับรับรอง login

ผู้จัดเตรียม: Codex (DEV implementation/technical checks)  
ผู้รับผิดชอบระบบ / ผู้อนุมัติ / วันที่ / Release ID / Server ID: ให้เจ้าขององค์กรบันทึกเมื่อดำเนินการจริง
