# ผลทดสอบ Production 1.0.0 — 10 ตุลาคม 2569

## สภาพแวดล้อมและขอบเขต

ติดตั้ง Ubuntu 24.04.5 WSL image จาก releases.ubuntu.com และตรวจ SHA256 กับ SHA256SUMS ของผู้ผลิต ใช้ distro ชั่วคราว `SMH-Production-Test` แยกจาก `Ubuntu-24.04`/`docker-desktop` เดิม Native Docker Engine 29.8.2 ภายใน Linux, Compose plugin, Linux ext4, source `/opt/smh-learn` และ Compose production มี 6 services

ใช้ HTTPS proxy Nginx ภายใน WSL พอร์ต18443 กับ certificate ชั่วคราวเพื่อทดสอบ secure cookieจากWindows ไม่มีการยกฐานข้อมูล/บัญชี/สื่อ DEV เข้า Production ผู้ใช้และเนื้อหาทั้งหมดสร้างเพื่อทดสอบในชุดแยกและลบการติดตั้งเมื่อจบ

## ข้อผิดพลาดที่พบและแก้

1. Docker context เดิมมีโอกาสรวม DEV storage: ปิด storage ใน .dockerignore และตรวจ image ไม่มี uploads/.env/demo
2. ดาวน์โหลดเทมเพลตไม่ได้: source ZIP และ image ขาด docs/templates แก้ allowlist รวม Excel 6 ไฟล์และให้ build ตรวจว่ามีครบ
3. ติดตั้งซ้ำแล้วหน้า PHP 502: Nginx เก็บ IP PHP container เดิม แก้ resolver Docker 127.0.0.11 valid5s และ fastcgi backendผ่านตัวแปร ทดสอบ recreateเฉพาะappโดยnginxไม่เปลี่ยนและ/loginกลับ200
4. วิดีโอ/PDFอัปโหลดแล้วอ่านไม่ได้: PHP UID33สร้างไฟล์ private แต่Nginx UID101อ่านไม่ได้ ให้Nginx workerใช้smh-web UID33 พร้อมคงprivate permissions ไม่เปิดmediaเป็นไฟล์สาธารณะ ทดสอบPDF200และMP4 Range206
5. Installerเดิมตรวจcontainerhealthyอย่างเดียว: เพิ่มverify-production-http.py ตรวจ/upและ/loginที่ผ่านPHPจริงหลังเริ่มบริการ

## ผลที่ทดสอบจริง

| งาน | ผล |
| --- | --- |
| ติดตั้งใหม่ | 13 migrations, 6 services, root adminsเพียงบัญชีเดียว ไม่มีdemo |
| บัญชีสูงสุด | loginรหัสแรก บังคับเปลี่ยน ใช้รหัสใหม่หลังติดตั้งซ้ำไม่ถูกreset |
| Excelพื้นฐาน5ชนิด | ดาวน์โหลด/upload/preview/commitผ่าน UI; แผนก/ตำแหน่ง/หมวด/ร้านอย่างละ1 ผู้ใช้10คน |
| ผู้ใช้ | รวม11คน ไม่มีdemo เลข0นำหน้าครบ บทบาทเริ่มต้นlearner password hash learn123456 บังคับเปลี่ยน |
| ข้อมูลซ้ำ/ผิด | นำเข้าผู้ใช้ซ้ำข้าม10 ไม่เปลี่ยนเดิม; เทมเพลตผิดหัวข้อปฏิเสธก่อนcommit |
| ร้านนำเข้า | เริ่มPENDING ไม่มอบสิทธิ์ผู้รับคูปองอัตโนมัติ |
| ข้อสอบ | ดาวน์โหลดเทมเพลต ข้อPretest4ตัวเลือก/Posttest6ตัวเลือก นำเข้าและล้างร่างเพื่อนำเข้าใหม่ได้ |
| หลักสูตร | สร้าง2บท อัปโหลดMP420วินาที+PDFบทแรก บทอ่านแยกบทสอง เผยแพร่และเปิดรอบ |
| ผู้เรียน | บังคับเปลี่ยนรหัส, admin/imports403, ก่อนPretestสื่อถูกกั้น409, Pretest0%ปลดล็อก |
| เรียนเรียงบท | PDFแสดงใต้คลิป, รับชมจริง80%, ยืนยันอ่าน, ปุ่มถัดไปdisabled→enabled, บทสองครบ100% |
| Posttest | ถูกฉ/Fได้100%, จบหลักสูตร, ใช้ครบ1/1ปุ่มซ้ำปิดและAPIปฏิเสธ422 |
| โบนัส | จบ20+ผ่าน30+คะแนนสูง10 รวม60, Ledgerแต่ละโบนัสครั้งเดียว |
| คูปอง | แลก500เป็น5บาท อายุ90วัน รับโดยร้านที่อนุมัติ, รหัสใช้ซ้ำปฏิเสธ, รหัสปลอม422 |
| ส่งเบิก | เลือกคูปองสร้างใบ1รายการ5บาท, PDF200ลายเซ็น%PDF, CSV200 ยังไม่จ่ายเงินจริง |
| Backup/restore | encrypted archive verified; กู้คืนprojectใหม่ เปรียบเทียบ47ตาราง/18ไฟล์ตรงกัน; APP_KEYเดิมถอดรหัสคูปองได้ |
| ติดตั้งซ้ำ | ข้อมูลและรหัสใหม่คงอยู่, หน้าPHP200; recreateเฉพาะappแล้วNginxเดิมกลับใช้งานได้ |
| รายงานผ่านworker | ส่งออกXLSX/CSV/PDF รายงานผลเรียนจริง ดาวน์โหลด200 ตรวจลายเซ็นไฟล์PK/%PDF |
| การป้องกันrestore | manifestผิดปฏิเสธก่อนกู้คืน ฐานข้อมูลไม่ว่างปฏิเสธการเขียนทับ |
| Release readiness | ops:release-checkผ่าน20ข้อ; ops:check --queue --backupผ่านทั้ง8ข้อในชุดทดสอบ |

หลักฐานภายในเครื่องจัดทำ: output/wsl-production-install.log, output/wsl-production-reinstall-3.log, output/wsl-import-database-verification.json, output/wsl-backup-restore.log, output/wsl-data-restore-proof.json, output/playwright/manuals ภาพสำหรับคู่มือรวมอยู่ในdocs/manuals/assets/screenshots ไม่รวมฐานข้อมูล/credentials/backupในGitHub

ก่อนทดสอบติดตั้งรอบนี้ได้ตรวจ PHP136tests/1936assertions, PHPStan/Pint, frontend build/typecheck/lint/format, Vitest23tests และdependency audits ผ่าน การแก้รอบนี้จำกัดDocker/Nginx/installer/packaging/docs ตรวจbuildและrunจริงในLinuxเพิ่มเติม

## ขอบเขตที่ยังต้องตรวจปลายทาง

AlmaLinux9จริงพร้อมSELinux/firewalld, UbuntuServerจริง/UFW, Windowsใหม่/การรีบูตหรือlogoff/บริการตลอด24ชั่วโมง, publicDNS/Let’sEncrypt/renewal, CAขององค์กร, LAN/Internetและระบบเครือข่าย, ผู้ใช้พร้อมกัน1,000คน และThaiDองค์กร **ยังไม่ได้ยืนยันจากการรันจริง** ไม่ใช้ผลWSLแทนการรับรองเหล่านี้

คู่มือให้ขั้นตอนปลายทางและผลที่ต้องเห็น ต้องผ่านops:release-check, ops:check --queue --backup --tls และตรวจรับโดยเจ้าของระบบก่อนเปิดทั่วไป TLSทดสอบชั่วคราวไม่ได้ผ่านเกณฑ์certificateเชื่อถือได้/อายุ14วันของProductionจริง

## ติดตั้งจาก GitHub และถอนชุดทดสอบ

ดึงsourceกลับจาก https://github.com/golderboy/smh-learn.git ด้วยgit clone ในWSL โดยไม่ใช้credentialของWindows สร้างprojectใหม่`smh-release-github-test`จากฐานข้อมูลว่าง Build/installผ่าน, platform/Nginx/queue/schedulerผ่าน,เริ่มจากadminsบัญชีเดียว จากนั้นloginบังคับเปลี่ยนรหัสและนำเข้า5หัวข้อผ่านUIครบอีกครั้ง ผู้ใช้10คน+adminsรวม11 เลข0นำหน้าครบ บทบาทlearner/รหัสแรก/บังคับเปลี่ยนถูกต้อง ร้านPENDINGและไม่มีoperatorอัตโนมัติ

หลังผ่านทดสอบ ลบcontainers/networks/volumesของ3projectที่ตรวจชื่อก่อนลบ (`smh-production-wsl-test`, `smh-learning-restore-drill`, `smh-release-github-test`) แล้วterminate/unregisterเฉพาะ`SMH-Production-Test` ตรวจregistryและVHDแล้วถูกลบ เก็บ`Ubuntu-24.04`/`docker-desktop`เดิมไว้ เว็บDEV`http://127.0.0.1:8088/up`กลับ200และDocker Desktopเดิมยังทำงาน ไม่มีการresetรหัสหรือฐานข้อมูลDEV

หลักฐานเพิ่ม: output/wsl-github-install.log, output/wsl-github-import-ui.log, output/wsl-github-import-database-verification.json, output/wsl-restore-guards.log, output/wsl-cleanup.log, output/wsl-uninstall-verification.json ไม่รวมไฟล์ทดสอบเหล่านี้ในGitHub

คู่มือภาพประกอบมีPDFและHTMLครบ5เล่ม: AlmaLinux15หน้า, Ubuntu15หน้า, Windows16หน้า, Admin16หน้า, ผู้เรียน12หน้า ตรวจภาพทุกหน้าและข้อความไทย ไม่มีหน้าว่าง HTMLเปิดภาพเต็มและคัดลอกคำสั่งได้ ฟอนต์Sarabun/OFLรวมในsource

รุ่นเผยแพร่ใช้tag`v1.0.0`พร้อมsourceZIP/คู่มือในGitHub Releases ดูผลbuild/installบนrunnerLinuxของtagได้ที่ [GitHub Actions](https://github.com/golderboy/smh-learn/actions) การตรวจWSLข้างต้นใช้sourceที่ดึงจากrepositoryก่อนเพิ่มรายงานถอนการติดตั้งและปรับภาพคู่มือครั้งสุดท้าย ไม่เปลี่ยนโค้ดruntimeระหว่างนั้น
