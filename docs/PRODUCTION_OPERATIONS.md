# ดูแล SMH Learning Production 1.0

คำสั่งในเอกสารนี้รันจาก `/opt/smh-learn` บน Linux ด้วย helper:

```bash
dc() { sudo docker compose --env-file .env.production -f compose.production.yaml "$@"; }
```

## พื้นที่เก็บข้อมูลและความลับ

| รายการ | ที่เก็บ |
| --- | --- |
| DB | named volume `<SMH_PROJECT_NAME>_mariadb-data` |
| วิดีโอ เอกสาร ภาพองค์กร รายงาน | `<SMH_PROJECT_NAME>_storage-data` |
| Redis/session/queue | `<SMH_PROJECT_NAME>_redis-data` |
| APP_KEY / DB secrets / config | `.env.production` บน host (600) |
| Backup เข้ารหัส | `.backups/*.tar.gz.gpg` + metadata JSON |

เก็บ `.env.production` และ passphrase ของ backup นอก Git APP_KEY ต้องคงเดิมในการอัปเดต/กู้คืน ไม่ใช้ `.env` ของ DEV กุญแจสำหรับถอด backup ต้องเก็บสำเนาแยกจากที่เก็บ archive และส่ง backup ไปพื้นที่ offsite ที่องค์กรอนุมัติ

## Backup แบบสอดคล้องและเข้ารหัส

สคริปต์ใช้ฐานข้อมูล Production ที่ไม่มี demo เท่านั้น เปิด maintenance และหยุด worker/scheduler ชั่วคราว แล้ว dump SQL, คัดลอก storage/app, inventory และ runtime env ไว้ใน archive เข้ารหัส AES256 ด้วย GnuPG สคริปต์เปรียบเทียบ row/file hashes ก่อน-หลังและถอด ciphertext กลับมาเทียบ hash ก่อนระบุ verified; ไม่ถือเป็นการทดสอบ restore DB

```bash
sudo install -d -m 0700 /etc/smh-learning
sudo sh -c 'umask 077; openssl rand -base64 48 > /etc/smh-learning/backup.pass'
sudo python3 scripts/backup-production.py --passphrase-file /etc/smh-learning/backup.pass
dc exec -T app php artisan ops:check --queue --backup --tls --json
```

สำรอง passphrase แยกอย่างปลอดภัย ห้ามสร้างทับเมื่อมี backup เดิม ห้ามพิมพ์ passphrase/APP_KEY/token ลง chat หรือ command line หาก backup ล้ม ให้ตรวจว่า maintenance/worker คืนสถานะแล้วก่อนเปิดบริการ และตรวจ lock `.backups/.production-backup.lock` ก่อนลบด้วยตนเอง

ตั้ง daily backup ด้วย units ที่ให้มา หลังทดสอบ backup สำเร็จ:

```bash
sudo install -m 0644 docker/production/smh-backup.service docker/production/smh-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smh-backup.timer
sudo systemctl list-timers smh-backup.timer
```

เวลาเริ่มต้น 02:30 Asia/Bangkok หาก host ใช้ timezone อื่นให้ตรวจ `timedatectl` หรือปรับ OnCalendar ให้ตรง เวลา backup มี downtime และต้องจัดตารางตามนโยบายองค์กร

Retention เป้าหมาย 7 daily / 4 weekly / 12 monthly และ offsite เป็นหน้าที่งานจัดเก็บที่องค์กรกำหนด สคริปต์นี้ **ไม่ลบ backup อัตโนมัติ** ให้กำหนด RPO/RTO/retention และผู้รับ alert ก่อนเปิดใช้จริง

## ทดสอบ restore ในระบบแยกก่อนเปิดจริง

ไม่ restore ทับ Production ที่รับข้อมูลใหม่แล้วโดยไม่ได้ตัดสินใจกับเจ้าของข้อมูล ใช้เครื่อง/โฟลเดอร์/project/database/port แยก ตัวอย่างสร้าง `/opt/smh-learn-restore` และ project `smh-learning-restore-drill`:

```bash
git clone --branch v1.0.0 https://github.com/golderboy/smh-learn.git /opt/smh-learn-restore
cd /opt/smh-learn-restore
python3 scripts/setup-production.py --domain restore.example.org --port 18081 --project smh-learning-restore-drill
```

ใช้ helper ที่ตรวจ manifest/SHA256, ปฏิเสธ tar path/link ที่ไม่ปลอดภัย, ตรวจว่า target ไม่มี services ที่กำลังทำงานและฐานข้อมูลว่าง, คัดเฉพาะ APP_KEY เดิมเพื่อถอดข้อมูล, คง DB credentials/port/project ใหม่ และเปรียบเทียบ row/file hashes หลัง restore:

```bash
sudo docker compose --env-file .env.production -f compose.production.yaml build app nginx
sudo python3 scripts/restore-production.py \
  --archive /PATH/TO/BACKUP.tar.gz.gpg \
  --manifest /PATH/TO/BACKUP.json \
  --passphrase-file /etc/smh-learning/backup.pass
sudo python3 scripts/verify-production-http.py
```

แทน /PATH/TO ด้วยไฟล์จริง ไม่รัน install-production.sh ก่อน restore เพราะ installer สร้างตารางไว้ ทำให้ target ไม่ว่าง หาก helper ปฏิเสธข้อมูลที่มีอยู่ ห้ามลบเพื่อฝืน ให้สร้าง target แยกชุดใหม่ หลัง restore ต้องตั้ง HTTPS ของ target, login ด้วยรหัสล่าสุดใน backup, เปิดสื่อจริงและตรวจรายงาน/แต้ม/คูปองตาม [ผลการทดสอบ](PRODUCTION_1_0_VERIFICATION.md)

เมื่อผ่านจะเห็น restored:true, tables_verified, files_verified และ original_app_key_preserved:true เก็บเวลาวัด RTO/RPO และผลตรวจรับ ระบบทดสอบรอบนี้กู้คืน 47 ตาราง/18 ไฟล์ พร้อมถอดรหัสคูปองได้จริง Backup ไม่สำรอง Redis sessions ผู้ใช้จึงต้อง login ใหม่

## Monitor

```bash
dc ps
dc exec -T app php artisan ops:check --queue --backup --tls --json
dc exec -T app php artisan ops:release-check --json
dc logs --tail 100 app queue-worker scheduler
```

ใช้ exit code ของ ops:check ส่ง alert ผ่านระบบขององค์กร; เฝ้าดู failed_jobs, disk, queue depth, scheduler, อายุ backup และ certificate `/up` ใช้ตรวจว่าเว็บทำงาน ไม่แทนการตรวจฐานข้อมูล/worker/backup ทั้งหมด

Docker logs หมุน 10 MB × 5 files ต่อ service ส่วน Laravel daily log หมุน 14 วัน Nginx metrics ไม่เก็บ request URI/query/coupon code/OAuth code แต่หากเพิ่ม proxy/monitor ภายนอกเอง ต้องตรวจ logging เพิ่ม

## อัปเดตและย้อนรุ่น

1. ทดสอบ tag ใหม่ในระบบแยกและตรวจ migration compatibility ก่อน
2. ทำ encrypted backup และ restore drill ที่ใช้ได้ เก็บ image/tag เดิมไว้
3. `git fetch --tags` และ checkout tag ใหม่ โดยคง `.env.production`/volumes/APP_KEY เดิม
4. เปลี่ยน SMH_APP_IMAGE/SMH_WEB_IMAGE เป็น tag ของรุ่นใหม่ก่อน build เพื่อไม่เขียนทับ image รุ่นก่อน
5. เปิด maintenance, หยุด worker/scheduler, build images และรัน `migrate --force` ผ่าน image ใหม่
6. `dc up -d --force-recreate --wait` เพื่อเริ่ม app/web/worker/scheduler ของรุ่นเดียวกัน แล้ว `dc exec -T app php artisan up`
7. ตรวจ release-check, ops:check และ smoke workflows แล้วเปิดรับผู้ใช้

Rollback แอปใช้ checkout tag เก่าและ image tags เก่าที่เก็บไว้แล้ว recreate ทุก service; ทำได้เมื่อ schema ยัง compatible ไม่ทำ migrate:rollback/down/fresh หรือคืน DB อัตโนมัติ หาก schema ไม่ compatible ต้องให้เจ้าของข้อมูลตัดสินใจเรื่องข้อมูลใหม่หลัง backup ก่อนกู้คืน

ห้าม `docker compose down -v` บนข้อมูลที่ต้องเก็บ และห้ามเปลี่ยน SMH_PROJECT_NAME หลังมีข้อมูล เพราะจะไปใช้ volume อีกชุด การแก้ secrets/APP_URL ต้อง recreate ทุก PHP service เพื่อสร้าง config cache จากค่าชุดเดียวกัน
