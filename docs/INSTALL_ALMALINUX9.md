# ติดตั้ง SMH Learning Production 1.0.0 บน AlmaLinux 9

## 1. สิ่งที่ต้องเตรียม

องค์กรละหนึ่งระบบ/ฐานข้อมูล บน AlmaLinux 9 x86_64 ใช้ Docker Engine + Compose และ Nginx HTTPS proxy บน host ไม่ต้องติดตั้ง PHP/Composer/Node ลง host คง SELinux Enforcing

เริ่มจัดเครื่องที่ 4 vCPU / RAM 8 GB / SSD 100 GB แล้วเพิ่มพื้นที่ตามวิดีโอและ backup **ไม่ใช่ผลรับรองโหลดพร้อมกัน 1,000 คน** ต้องทดสอบจำนวนผู้ดูวิดีโอ/ผู้สอบพร้อมกันตามการใช้งานจริง

ต้องมี sudo, NTP ถูกต้อง, โดเมน DNS ชี้เครื่อง และพอร์ต 80/443 หากเป็นชื่อภายใน ให้ใช้ certificate/CA ขององค์กรแทน Let's Encrypt และเพิ่ม trusted CA ลง image ก่อนตรวจ TLS

รหัสตั้งต้นเป็นข้อมูลสาธารณะ จึงจำกัด 443 เฉพาะ IP ผู้ดูแลจนเปลี่ยนรหัสเสร็จ ใน HTTP bootstrap จะยังไม่เปิดเว็บให้ login

## 2. เครื่องมือบน host

คำสั่งนี้สำหรับเครื่องใหม่ ตรวจ `cat /etc/almalinux-release`, `getenforce` และแพ็กเกจ Docker/Podman เดิมก่อน หากมี workload ของระบบอื่น ให้ผู้ดูแลจัดความเข้ากันได้ก่อน ไม่ลบแพ็กเกจ/volume ของระบบอื่น

```bash
sudo dnf install -y git python3 nginx firewalld openssl gnupg2 dnf-plugins-core policycoreutils-python-utils
sudo dnf config-manager --set-enabled crb
sudo dnf install -y epel-release
sudo dnf install -y certbot
sudo dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
sudo docker compose version
sudo setsebool -P httpd_can_network_connect 1
```

ใช้ RPM repository ตระกูล RHEL 9 ตาม [Docker RHEL](https://docs.docker.com/engine/install/rhel/); การใช้บน AlmaLinux เป็นการใช้ความเข้ากันได้กับ RHEL ไม่ใช่อ้างว่า Docker รับรอง AlmaLinux โดยตรง ดู [AlmaLinux repositories](https://wiki.almalinux.org/repos/Extras.html) และ [SELinux/Nginx ของ Red Hat](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/9/html/deploying_web_servers_and_reverse_proxies/setting-up-and-configuring-nginx_deploying-web-servers-and-reverse-proxies)

ไม่ปิด SELinux และไม่ใช้ chmod 777 Production ใช้ Docker named volumes หากปรับเป็น bind mount ต้องจัด SELinux label และ owner เพิ่ม

## 3. Firewall ระหว่างเริ่มติดตั้ง

แทน IP ตัวอย่างด้วย IP จริงของผู้ดูแล ตรวจ zone และ SSH ปัจจุบันก่อนเปิด firewalld เพื่อไม่ล็อกตัวเองออก

```bash
sudo systemctl enable --now firewalld
sudo firewall-cmd --get-active-zones
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="203.0.113.10/32" port port="443" protocol="tcp" accept'
sudo firewall-cmd --reload
```

อย่าเพิ่ม https สำหรับทุก IP จนเปลี่ยนรหัส admins แล้ว พอร์ต backend เปิดเพียง `127.0.0.1:8080` และ DB/Redis ไม่ publish พอร์ต

## 4. ดึงรุ่นและสร้างค่าติดตั้ง

```bash
sudo install -d -m 0750 -o "$USER" -g "$USER" /opt/smh-learn
git clone --branch v1.0.0 https://github.com/golderboy/smh-learn.git /opt/smh-learn
cd /opt/smh-learn
python3 scripts/setup-production.py --domain learning.example.org
chmod 600 .env.production
sudo sh scripts/install-production.sh
```

เปลี่ยนโดเมนก่อนรัน generator สร้าง APP_KEY และรหัส DB แบบสุ่ม ไม่พิมพ์ secrets และปฏิเสธการเขียนทับไฟล์เดิม ห้ามเปลี่ยน APP_KEY หลังมีข้อมูล เพราะคูปองและข้อมูลเข้ารหัสเดิมต้องใช้ key เดิม

Installer build ตาม lock files เริ่ม MariaDB/Redis, migrate เพิ่ม schema, seed catalog/บัญชี admins แล้วเริ่ม 6 services ไม่มีการล้างฐานข้อมูลหรือสร้างข้อมูลจำลอง การ seed ซ้ำไม่รีเซ็ตรหัสผ่าน

คำสั่งต่อไปใช้ helper นี้ใน shell ที่อยู่ `/opt/smh-learn`:

```bash
dc() { sudo docker compose --env-file .env.production -f compose.production.yaml "$@"; }
dc ps
dc exec -T app composer check-platform-reqs --no-dev
dc exec -T nginx nginx -t
```

## 5. HTTPS ก่อน login

ตัวอย่างใช้ Let's Encrypt webroot สำหรับโดเมนสาธารณะ หากใช้ certificate องค์กร ให้ใช้ certificate/key ที่องค์กรจัดให้และจัด trusted chain แทน

แทนคำ `YOUR_DOMAIN` และ `YOUR_EMAIL` ด้านล่างด้วยค่าจริง ไม่ใช้ placeholder ตามตัวอย่าง:

```bash
sudo install -d -m 0755 /var/www/letsencrypt
sudo restorecon -Rv /var/www/letsencrypt
sed 's/learning.example.org/YOUR_DOMAIN/g' docker/production/host-nginx.conf.example | sudo tee /etc/nginx/conf.d/smh-learning.conf >/dev/null
sudo nginx -t
sudo systemctl enable --now nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d YOUR_DOMAIN --email YOUR_EMAIL --agree-tos --no-eff-email
sed 's/learning.example.org/YOUR_DOMAIN/g' docker/production/host-nginx-tls.conf.example | sudo tee /etc/nginx/conf.d/smh-learning.conf >/dev/null
sudo nginx -t
sudo systemctl reload nginx
curl -I https://YOUR_DOMAIN/up
sudo certbot renew --dry-run
```

Bootstrap HTTP รับเฉพาะ ACME; หน้าอื่นคืน 503 หลังใช้ TLS template HTTP จึงเปลี่ยนเป็น redirect ไป HTTPS หากเปลี่ยน APP_PORT ให้แก้ proxy_pass ใน template ด้วย

```bash
sudo install -m 0644 docker/production/smh-certbot-renew.service docker/production/smh-certbot-renew.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smh-certbot-renew.timer
sudo systemctl list-timers smh-certbot-renew.timer
```

Host proxy เขียนทับ forwarded headers เป็นค่าจาก proxy เอง ส่วน backend ตั้ง HTTPS สำหรับ topology นี้ ห้ามเปิด 8080 ให้ LAN/Internet หรือใช้ backend นี้เป็น HTTP Production ไม่ trust proxy ทุก IP ใน Laravel

## 6. บัญชีและข้อมูลหน่วยงาน

เข้า `https://โดเมนจริง/login` จาก IP ผู้ดูแล:

1. `admins` / `P@ssw0rd112233` และเปลี่ยนรหัสทันที ระบบไม่อนุญาต Dashboard ก่อนเปลี่ยน
2. Settings: ชื่อหน่วยงาน โลโก้ แบนเนอร์ header/ข้อความ และข้อมูลติดต่อ
3. นำเข้าข้อมูลพื้นฐาน: แผนก → ตำแหน่ง → หมวด → บุคลากร → ร้านสวัสดิการ
4. ผู้ใช้ใหม่ใช้เลขประจำตัวเป็น username รหัสแรก `learn123456` บทบาทผู้เรียน และบังคับเปลี่ยนรหัส ข้ามเลขประจำตัวซ้ำ
5. Admin กำหนดแอดมินรอง/Instructor และหมวดเอง ร้านที่นำเข้ารออนุมัติและไม่เพิ่มสิทธิ์ผู้รับคูปองอัตโนมัติ
6. สร้างหลักสูตร/บท/สื่อ Pretest/Posttest และจำนวนครั้งสอบในระบบ ไม่ใช้ฐานข้อมูล DEV

ดู [คู่มือนำเข้าข้อมูล](MASTER_DATA_IMPORT_GUIDE.md) บัญชี admins ที่มีอยู่แล้วใช้รหัสล่าสุดของเจ้าของ ไม่ใช่รหัสตั้งต้นที่กลับมาใหม่เมื่อ seed

## 7. ตรวจและเปิดบริการ

```bash
dc exec -T app php artisan ops:release-check --json
dc exec -T app php artisan ops:check --queue --tls --json
dc logs --tail 50 app queue-worker scheduler
sudo ss -lntp
sudo firewall-cmd --list-all
```

รอ scheduler ผ่านรอบนาทีอย่างน้อยหนึ่งรอบก่อนตรวจ heartbeat; `protected_admin_ready` ไม่ผ่านจนเจ้าของเปลี่ยนรหัส และ `tls` ต้องใช้ certificate จริงที่เหลืออายุอย่างน้อย 14 วัน

ทำ UAT: login/logout, root protection, department scope, สื่อส่วนตัว, Pretest → เรียน → Posttest → คะแนน/แต้ม, Excel และคูปองใช้ครั้งเดียว จากนั้น [ตั้ง backup และทดสอบ restore](PRODUCTION_OPERATIONS.md) แล้วตรวจ `ops:check --queue --backup --tls --json`

เมื่อยอมรับ UAT และเปลี่ยนรหัส admins แล้วจึงเปิด HTTPS ทั่วไปตามนโยบาย หากใช้ภายในให้เปิดเฉพาะ subnet แทน:

```bash
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

## 8. แก้ปัญหา

| อาการ | ตรวจ |
| --- | --- |
| 502 | `dc ps`, `curl -I http://127.0.0.1:8080/up`, `sudo ausearch -m AVC -ts recent`; ตรวจ SELinux boolean ไม่ปิด SELinux |
| 419 / กลับ login | HTTPS, APP_URL/โดเมนตรงกัน, Redis และ certificate; ไม่ปิด secure cookie |
| Storage เขียนไม่ได้ | `dc exec -T app id` ควร UID 33; ตรวจ named volume owner ไม่ใช้ chmod 777 |
| DB login ไม่ผ่านหลังแก้ env | Volume เก็บรหัสเดิม การแก้ env ไม่หมุนรหัส DB; จัด rotation ไม่ลบ volume |
| Worker/Scheduler ล้ม | ตรวจ logs/DB/Redis/APP_KEY แล้ว recreate service ของรุ่นเดียวกัน |
| ไฟล์ใหญ่เกิน | รับสูงสุด 100 MB; หากเปลี่ยนต้องแก้ PHP และ Nginx ทั้งสองชั้น |
| ขอ certificate ไม่ผ่าน | ตรวจ DNS A/AAAA, 80 และ ACME; โดเมนภายในใช้ CA องค์กร |

อ้างอิง: [Laravel deployment](https://laravel.com/docs/13.x/deployment), [Certbot webroot](https://eff-certbot.readthedocs.io/en/stable/using.html#webroot)

การทดสอบชุดนี้เป็น Linux containers บน PC **ยังไม่ได้ยืนยันจากการรันจริงบนเซิร์ฟเวอร์ AlmaLinux 9** รวม firewalld/SELinux/systemd และ certificate ของหน่วยงาน
