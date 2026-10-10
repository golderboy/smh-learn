# ติดตั้ง Ubuntu 24.04 LTS — Production 1.0

คู่มือผู้เริ่มต้นพร้อมภาพ: [PDF](manuals/install-ubuntu.pdf) / [HTML](manuals/install-ubuntu.html)

ใช้ Ubuntu 24.04 x86_64 เครื่องเฉพาะ มี sudo, DNS และ HTTPS ขององค์กร ขั้น Docker Engine/Compose และแอปทดสอบจริงใน Ubuntu 24.04.5 บน WSL2 ส่วน UFW, โดเมนสาธารณะ, Certbot และรีบูตเครื่องจริง ยังไม่ได้ยืนยันจากการรันจริง

ติดตั้ง Docker ตาม [เอกสารทางการ Ubuntu](https://docs.docker.com/engine/install/ubuntu/) ใช้แพ็กเกจ `docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin` และ host tools `git python3 nginx openssl gnupg certbot ca-certificates curl` ไม่ใช้ Compose แบบเก่า `docker-compose` ไม่ต้องลง PHP/Node/DB บน host

```bash
sudo systemctl enable --now docker
sudo docker run --rm hello-world
sudo install -d -m 0750 -o "$USER" -g "$(id -gn)" /opt/smh-learn
git clone --branch v1.0.0 https://github.com/golderboy/smh-learn.git /opt/smh-learn
cd /opt/smh-learn
python3 scripts/setup-production.py --domain learning.example.org
sudo sh scripts/install-production.sh
```

แทนโดเมนด้วยชื่อจริง จากนั้นทำขั้น HTTPS ใน [คู่มือภาพ](manuals/install-ubuntu.html) โดยใช้ `host-nginx.conf.example` สำหรับ ACME bootstrap และ `host-nginx-tls.conf.example` หลังได้ certificate ตั้ง firewall ปิด HTTPS ยกเว้น IP ผู้ดูแลจนเปลี่ยนรหัส `admins` แรกเสร็จ ไม่เปิดพอร์ต backend/DB/Redis ให้เครือข่าย

บัญชีตั้งต้น `admins` / `P@ssw0rd112233` บังคับเปลี่ยนก่อนใช้งาน ผู้ใช้นำเข้าใช้ `learn123456` และบังคับเปลี่ยน การ seed/tิดตั้งซ้ำไม่รีเซ็ตรหัสเดิม

```bash
dc() { sudo docker compose --env-file .env.production -f compose.production.yaml "$@"; }
dc ps
dc exec -T nginx nginx -t
dc exec -T app php artisan ops:release-check --json
dc exec -T app php artisan ops:check --queue --tls --json
```

ตรวจครบก่อนเปิดทั่วไป ตั้ง [สำรองและกู้คืน](PRODUCTION_OPERATIONS.md) อ่าน [ผลทดสอบและข้อจำกัด](PRODUCTION_1_0_VERIFICATION.md) `.env.production`/APP_KEY/passphrase ห้ามเข้า Git และห้ามเปลี่ยน APP_KEY เมื่อมีข้อมูลแล้ว
