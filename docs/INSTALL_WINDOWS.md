# ติดตั้ง Windows 11 / WSL2 — Production 1.0

คู่มือผู้เริ่มต้นพร้อมภาพ: [PDF](manuals/install-windows.pdf) / [HTML](manuals/install-windows.html)

ใช้ Ubuntu 24.04 ใน WSL2 และ native Docker Engine ภายใน distro แยกสำหรับ SMH ไม่ใช้ XAMPP และไม่ผสม Docker Desktop integration กับ native Engine ใน distro เดียวกัน

เตรียม WSL ตาม [Microsoft](https://learn.microsoft.com/en-us/windows/wsl/install) และ [Ubuntu](https://documentation.ubuntu.com/wsl/latest/guides/install-ubuntu-wsl2/) หากมี Ubuntu-24.04 ที่ใช้งานอื่นอยู่ ห้ามใช้ทับ ให้ IT เตรียม distro แยก

```powershell
# PowerShell: สิทธิ์ Administrator สำหรับการเปิด WSL ครั้งแรก
wsl --install --no-distribution
wsl --update
# รีบูตเมื่อระบบร้องขอ แล้วใช้ PowerShell ของบัญชีผู้ใช้งานจริง
wsl --set-default-version 2
wsl --list --verbose
wsl --install -d Ubuntu-24.04
wsl -d Ubuntu-24.04
```

ตั้งบัญชี Linux/รหัส sudo ของตน ตรวจ `ps -p 1 -o comm=` เป็น systemd ถ้าไม่ใช่ ให้เพิ่ม `[boot]`/`systemd=true` ใน `/etc/wsl.conf` โดยคงค่าเดิม แล้ว `wsl --terminate Ubuntu-24.04` เฉพาะ distro นั้น เปิดใหม่ ห้าม `wsl --shutdown` เพราะกระทบทุก Linux รวมงานอื่น

ทำขั้นติดตั้ง Docker/แอป/HTTPS ตาม [คู่มือ Windows ฉบับเต็ม](manuals/install-windows.html) ซึ่งมีคำสั่ง Linux ให้ครบ เก็บ source ใน `/opt/smh-learn` และ data ใน Linux named volumes ไม่ใช้ `/mnt/c` เก็บฐานข้อมูล

**WSL อาจหยุดเมื่อไม่มี session เปิด แม้ systemd ทำงาน** จากการทดสอบต้องเปิด Ubuntu terminal ค้างด้วย `sleep infinity` แล้วเปิดอีก terminal เพื่อทำงาน อย่าอ้างว่า enable systemd แล้วทำงาน 24 ชั่วโมงได้อัตโนมัติ

localhost จาก Windows ใช้ได้ตาม [WSL networking](https://learn.microsoft.com/en-us/windows/wsl/networking) ต้องใช้ HTTPS ที่เชื่อถือได้และชื่อจริงตรง APP_URL การใช้จาก LAN/Internet ต้องจัด WSL networking/Windows Firewall/forwarding/DNS โดย IT และตรวจหลังรีบูตจริง WSL IP อาจเปลี่ยน

ทดสอบติดตั้ง native Linux และเปิดจาก Windows ผ่าน loopback HTTPS จริงแล้ว การเปิดใช้ต่อเนื่องหลัง Windows logoff/reboot, LAN, certificate สาธารณะ และติดตั้ง WSL บน Windows ใหม่ ยังไม่ได้ยืนยันจากการรันจริง ดู [ผลทดสอบ](PRODUCTION_1_0_VERIFICATION.md)
