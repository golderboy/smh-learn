# ThaiD: โครงรอเชื่อมต่อบน Production

สถานะรอบ 8–9 ตุลาคม 2569: เตรียมโครงตามคู่มือกรมการปกครองรุ่น 1.1.0 แล้ว แต่ยังปิดการเข้าสู่ระบบด้วย ThaiD ทั้ง DEV และ Production จนกว่าจะพัฒนา verifier และทดสอบกับ RP ขององค์กรบนเซิร์ฟเวอร์จริงสำเร็จ

แหล่งข้อมูลคือ PDF ที่ผู้ใช้แนบ `F:\Destops\DESTOP\HAIT\THAID\2. คู่มือประกอบการพัฒนาระบบ-V1.1.0.pdf` ลงวันที่ 7 สิงหาคม 2567 อ่านครบ 17 หน้าและตรวจภาพสถาปัตยกรรม/ตาราง request แล้ว ไม่คัดลอก PDF เข้า Git และไม่เผยแพร่เอกสารควบคุมฉบับนี้

## โครงที่มีแล้ว

- `ThaiDProtocol`: สร้าง request แบบ authorization code, scope ขั้นต่ำ `pid openid`, state สุ่ม 256 บิต เก็บเฉพาะ SHA-256 ใน session อายุ 5 นาทีและใช้ได้ครั้งเดียว
- `exchangeCode`: ส่งแบบ form + HTTP Basic จาก backend ไปยัง endpoint ที่คู่มือระบุ ใช้ callback HTTPS ตรงเส้นทางที่กำหนด ไม่ตาม redirect ไม่ retry authorization code ไม่ส่ง secret/token ให้ browser หรือข้อความ exception
- `ThaiDIdentityVerifier`: สัญญาสำหรับตรวจ token อย่างแท้จริง แยกจาก transport ไม่มี implementation ที่ยอมรับ token ด้วยการ decode Base64
- เส้นทาง `GET /auth/thaid` และ `GET /auth/thaid/callback` จองไว้และตอบ 404 สำหรับ guest ส่วนผู้ที่มี session อยู่แล้วจะถูก guest middleware ส่งกลับตามการเข้าสู่ระบบเดิม การเปลี่ยน `THAID_ENABLED=true` เพียงอย่างเดียวไม่เปิด ThaiD login
- ฐานข้อมูล `external_identities` เดิมรองรับ provider/issuer/subject และข้อกำหนดไม่ให้ตัวตนเดียวผูกสองบัญชี โดย subject ถูกซ่อนจาก serialization และ Audit ปิดบังเลขประชาชน/token/secret
- ไม่สร้างบัญชี ไม่ผูกบัญชี ไม่อัปเดตสิทธิ์จาก query `pid`, `sub`, `code` หรือ token response ที่ยังไม่ได้ตรวจสอบ

## การตั้งค่ารอองค์กร

ใช้ `THAID_CLIENT_ID`, `THAID_CLIENT_SECRET`, `THAID_REDIRECT_URI`, `THAID_ISSUER` เฉพาะ secret/config ฝั่งเซิร์ฟเวอร์ `THAID_ENABLED=false` และ `adapter_ready=false` คงไว้ก่อน อย่าใส่ secret ในหน้า Admin Settings, Excel, source code, VITE variables หรือแชต

เมื่อได้โดเมนจริง ให้ลงทะเบียน callback เป็น `https://<โดเมนจริง>/auth/thaid/callback` กับ RP ให้ตรงทุกอักขระ ไม่ใช้ localhost เป็นตัวแทนผลทดสอบจริง

## ขั้นตอนที่ต้องทำบนเซิร์ฟเวอร์จริงก่อนเปิดใช้

1. องค์กรอนุมัติ RP และสิทธิ์ scope พร้อม Client ID/secret และ callback ที่ลงทะเบียน ตรวจว่ารุ่นเอกสาร/ข้อกำหนดยังตรงกับผู้ให้บริการ
2. อ่าน discovery จาก endpoint `.well-known/openid-configuration` ที่คู่มือให้มา ตรวจ issuer, JWKS, signing algorithms และรายละเอียด security ที่ RP ได้รับ ไม่เดา JWKS URL หรือ algoritm เอง เลือกไลบรารี OIDC ที่มีการตรวจสอบและ pin เวอร์ชัน
3. ทำ verifier ตรวจ signature, issuer, audience, exp, iat, at_hash และ subject/PID รูปแบบที่ provider รับรอง ตรวจวิธีใช้ nonce/PKCE กับเอกสารปัจจุบันของ RP ก่อนเพิ่ม parameter ที่ไม่มีในคู่มือฉบับนี้
4. ตรวจการเชื่อมโยงบัญชีภายใน: ใช้ตัวตนที่ verifier รับรองและบัญชีที่ Admin จัดเตรียมไว้ ไม่ผูกหรือสร้างบัญชี Admin อัตโนมัติจากเลขที่พิมพ์ใน callback; บัญชี inactive ต้องเข้าไม่ได้
5. หลังยืนยันสำเร็จ ใช้ session regeneration, session_version, audit ที่ปิดบังข้อมูล และนโยบายเปลี่ยนรหัสผ่านเดิมตามเส้นทาง local login ก่อนเปลี่ยน gate/ปุ่ม ThaiD
6. ทดสอบ login จริง, ผู้ใช้ปฏิเสธ consent, code/state replay, state หมดอายุ, token หมดอายุ, signature/issuer/audience ผิด, สลับผู้ใช้, account inactive, subject ซ้ำ, provider timeout และ logout/revocation พร้อมทดสอบ local login ต่อได้เมื่อปิด ThaiD
7. ป้องกัน callback query/token/secret ใน reverse proxy, access log, error log, tracing และ APM ด้วย ไม่มีการบันทึก body หรือ URL เต็มใน metrics ที่เพิ่มรอบนี้

คู่มือมีรายละเอียดที่ต้องยืนยันกับ RP: diagram อ้าง path รุ่นเก่าแต่ตาราง request ใช้ v2; ตัวอย่าง token ยังแสดง refresh_token ทั้งที่ประวัติเอกสารเคยระบุเอาออก; introspect/revoke แสดง `token=Bearer token` ซึ่งต้องยืนยันค่าจริงก่อน implement จึงไม่ได้สร้าง refresh/introspect/revoke adapter โดยสมมติเองในรอบนี้

## หลักฐานบน DEV

ชุดทดสอบ `ThaiDScaffoldTest` ตรวจ request contract, form/Basic Auth, one-time state/expiry, callback ปลอม, response ผิดและการปิดบังข้อความผิดพลาด โดยใช้ `Http::fake` และป้องกัน stray requests ไม่ติดต่อผู้ให้บริการจริง ผลนี้ยืนยันเฉพาะโครงและการปิดเส้นทางอันตราย

**การเข้าสู่ระบบ ThaiD กับกรมการปกครองบน Production: ยังไม่ได้ยืนยันจากการรันจริง** และจะทดสอบตามคำสั่งผู้ใช้เมื่อระบบขึ้นเซิร์ฟเวอร์จริงแล้ว
