<?php

declare(strict_types=1);

return [
    'present' => 'กรุณาระบุรายการ:attribute แม้ยังไม่มีรายการที่เลือก',
    'required' => 'กรุณาระบุ:attribute', 'string' => ':attributeต้องเป็นข้อความ',
    'array' => ':attributeต้องเป็นรายการ', 'boolean' => ':attributeไม่ถูกต้อง',
    'integer' => ':attributeต้องเป็นจำนวนเต็ม', 'email' => 'รูปแบบอีเมลไม่ถูกต้อง',
    'unique' => 'มี:attributeนี้ในระบบแล้ว', 'exists' => ':attributeไม่ถูกต้องหรือปิดใช้งานแล้ว',
    'distinct' => ':attributeต้องไม่ซ้ำกัน', 'regex' => 'รูปแบบ:attributeไม่ถูกต้อง',
    'confirmed' => 'ยืนยัน:attributeไม่ตรงกัน', 'different' => ':attributeต้องต่างจาก:other',
    'current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
    'min' => ['string' => ':attributeต้องมีอย่างน้อย :min ตัวอักษร', 'numeric' => ':attributeต้องไม่น้อยกว่า :min', 'array' => 'กรุณาเลือก:attributeอย่างน้อย :min รายการ'],
    'max' => ['string' => ':attributeต้องไม่เกิน :max ตัวอักษร', 'numeric' => ':attributeต้องไม่เกิน :max', 'array' => ':attributeต้องไม่เกิน :max รายการ'],
    'attributes' => ['username' => 'เลขประจำตัว', 'employee_code' => 'รหัสบุคลากร', 'first_name' => 'ชื่อ', 'last_name' => 'นามสกุล', 'email' => 'อีเมล', 'phone' => 'เบอร์โทรศัพท์', 'password' => 'รหัสผ่าน', 'current_password' => 'รหัสผ่านปัจจุบัน', 'password_confirmation' => 'ยืนยันรหัสผ่าน', 'role_ids' => 'บทบาท', 'department_id' => 'แผนก', 'position_id' => 'ตำแหน่ง', 'is_active' => 'สถานะเปิดใช้งาน', 'code' => 'รหัส', 'name' => 'ชื่อ', 'reason' => 'เหตุผล', 'permission_ids' => 'สิทธิ์', 'instructor_ids' => 'ผู้คุม', 'organization_name' => 'ชื่อองค์กร', 'session_timeout_minutes' => 'เวลาเซสชัน'],
];
