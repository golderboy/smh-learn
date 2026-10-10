# SMH Learning — Production 1.0.0

ระบบเรียนรู้องค์กรสำหรับติดตั้งแยกฐานข้อมูลของแต่ละหน่วยงาน ใช้ Laravel 13, Vue 3, Inertia และ Tailwind CSS หน้าตา Cobalt Coral และปรับชื่อองค์กร โลโก้ แบนเนอร์/ข้อความได้จาก Settings

**คู่มือติดตั้งหลัก: [AlmaLinux 9 + Docker + HTTPS](docs/INSTALL_ALMALINUX9.md)**

**คู่มือภาพประกอบสำหรับผู้เริ่มต้น:** [AlmaLinux](docs/manuals/install-almalinux.pdf) · [Ubuntu](docs/manuals/install-ubuntu.pdf) · [Windows / WSL2](docs/manuals/install-windows.pdf) · [Admin และร้านสวัสดิการ](docs/manuals/admin-guide.pdf) · [ผู้เรียน](docs/manuals/user-guide.pdf)

ดาวน์โหลด source ZIP จาก [GitHub Releases](https://github.com/golderboy/smh-learn/releases/tag/v1.0.0) แล้วเปิด `docs/manuals/index.html` เพื่ออ่านแบบ HTML คัดลอกคำสั่ง และคลิกขยายภาพแบบออฟไลน์

Repository: [golderboy/smh-learn](https://github.com/golderboy/smh-learn)

## ความสามารถรุ่น 1.0

- บุคลากร แผนก ตำแหน่ง หมวดหลักสูตร และ Instructor ตามหมวดที่ Admin มอบหมาย
- นำเข้าข้อมูลพื้นฐาน Excel 5 หัวข้อพร้อมเทมเพลต ตรวจข้อมูลก่อนยืนยัน และข้ามเลขประจำตัวซ้ำ
- วิดีโออัปโหลด / MP4 URL / YouTube เอกสาร PDF / ภาพ / ลิงก์ และบังคับเรียนเรียงบท
- ข้อสอบปรนัย 4–6 ตัวเลือก คำตอบเดียว นำเข้า Excel ต้องทำ Pretest ก่อนเรียนและ Posttest หลังเรียน
- Instructor กำหนดจำนวนครั้ง Posttest ในระบบ ค่าเริ่มต้นรวมทั้งหมด 1 ครั้ง ใช้คะแนนสูงสุด จบได้เมื่อสอบอย่างน้อย 1 ครั้งโดยไม่บังคับคะแนนผ่าน
- Dashboard/รายงานตามสิทธิ์ ส่งออก XLSX / CSV / PDF ระบบแต้ม และปรับยอดพร้อม Audit
- แลกแต้มเป็นคูปองของร้านที่กำหนด ใช้ครั้งเดียว อายุ 90 วัน ร้านที่ Admin อนุมัติรับคูปองและสร้างใบสรุปส่งเบิกได้
- บัญชีสูงสุด admins ได้รับการป้องกันจากการแก้ไขโดยบัญชีอื่น เจ้าของเปลี่ยนรหัสผ่านของตนเองได้
- โครงสร้าง ThaiD เตรียมไว้และปิดใช้งาน ต้องรับรองการเชื่อมจริงก่อนเปิด

## ติดตั้งจาก GitHub

หลังติดตั้ง Docker Engine / Compose ตามคู่มือ AlmaLinux 9:

```bash
git clone --branch v1.0.0 https://github.com/golderboy/smh-learn.git
cd smh-learn
python3 scripts/setup-production.py --domain learning.example.org
sudo sh scripts/install-production.sh
```

เปลี่ยนโดเมนก่อนรัน และทำขั้นตอน Nginx/HTTPS/Firewall ในคู่มือก่อนเข้าสู่ระบบ ไม่ต้องติดตั้ง PHP, Composer, Node หรือ MariaDB บน host เพราะ build/runtime อยู่ใน Docker

บัญชีติดตั้งใหม่: `admins` / `P@ssw0rd112233` **บังคับเปลี่ยนก่อนใช้ระบบ** ผู้ใช้ที่นำเข้ามีรหัสแรก `learn123456` และบทบาทผู้เรียน การติดตั้งซ้ำไม่รีเซ็ตรหัสผ่านเดิม

Production ใช้ `compose.production.yaml` เท่านั้น ส่วน `compose.yaml` ใช้ DEV บน PC ห้ามใช้ .env/ฐานข้อมูล/บัญชีจำลอง/ไฟล์ DEV แทนการติดตั้งใหม่

## เอกสาร

- [ติดตั้งบน AlmaLinux 9](docs/INSTALL_ALMALINUX9.md)
- [ติดตั้งบน Ubuntu 24.04 LTS](docs/INSTALL_UBUNTU.md)
- [ติดตั้งบน Windows 11 ผ่าน WSL2](docs/INSTALL_WINDOWS.md)
- [ดูแลระบบ สำรอง/กู้คืน อัปเดต และย้อนรุ่น](docs/PRODUCTION_OPERATIONS.md)
- [ผลและขอบเขตการทดสอบ Production 1.0](docs/PRODUCTION_1_0_VERIFICATION.md)
- [นำเข้าข้อมูลพื้นฐาน](docs/MASTER_DATA_IMPORT_GUIDE.md)
- [ตั้งค่าภาพและข้อความเว็บไซต์](docs/COBALT_CORAL_SETTINGS_GUIDE.md)
- [ThaiD](docs/THAID_INTEGRATION_GUIDE.md)
- [Checklist ก่อนเปิดใช้งาน](docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md)
- [UAT](docs/UAT_PHASE0_6.md)
- [พัฒนาบน Windows](docs/DEV_SETUP_GUIDE.md)
- [รายการเปลี่ยนแปลง](CHANGELOG.md)

## ตรวจโค้ดระหว่างพัฒนา

```powershell
docker compose exec -T app composer lint
docker compose exec -T app composer analyse
docker compose exec -T app php artisan test --compact
npm.cmd run lint
npm.cmd run format:check
npm.cmd run test:unit
npm.cmd run build
```

PHPUnit ใช้ฐานข้อมูลทดสอบแยก ไม่ใช้ฐานข้อมูล Production
