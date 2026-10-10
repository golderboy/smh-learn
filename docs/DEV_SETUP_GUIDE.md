# พัฒนาบน PC — แยกจาก Production

คู่มือนี้สำหรับนักพัฒนา Windows/PowerShell ที่มี Docker Desktop Linux containers และ Node.js24 ขึ้นไป ใช้ compose.yaml, ฐานข้อมูล smh_learning_dev/test และเว็บ loopback8088 ไม่ใช้ฐานข้อมูล Production

```powershell
.\scripts\setup-dev.ps1
docker compose build app
docker compose run --rm --no-deps -e COMPOSER_ALLOW_SUPERUSER=1 app composer install --no-interaction
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm --no-deps app php artisan key:generate --env=testing
npm.cmd ci --cache .cache/npm
npm.cmd run build
docker compose up -d --wait
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan db:seed
```

setup-dev.ps1 สร้างเฉพาะ env ที่ยังไม่มี ไม่รีเซ็ตรหัสผ่านเดิม ไม่มี demo media ใน source release นักพัฒนาเพิ่มเนื้อหาผ่าน UI ด้วยตนเอง การทดสอบ E2E ที่ต้องใช้ fixture ให้ตรวจข้อกำหนดใน tests/Browser ก่อน ไม่เรียก dev:import-research-course เมื่อไม่มี source media

```powershell
docker compose exec -T app composer lint
docker compose exec -T app composer analyse
docker compose exec -T app php artisan test --compact
npm.cmd run lint
npm.cmd run format:check
npm.cmd run test:unit
npm.cmd run build
```

ดู [คู่มือAdmin](manuals/admin-guide.html), [ผู้เรียน](manuals/user-guide.html) และ [Production](INSTALL_ALMALINUX9.md) บัญชี admins ที่มีแล้วใช้รหัสล่าสุดของเจ้าของ ห้ามล้างข้อมูล/รีเซ็ตรหัสเพียงเพราะเปิดไม่ได้
