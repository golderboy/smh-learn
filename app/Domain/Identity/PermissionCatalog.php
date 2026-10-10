<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class PermissionCatalog
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function all(): array
    {
        return [
            'master.import' => ['นำเข้าข้อมูลพื้นฐาน Excel', 'ระบบ'],
            'users.manage' => ['จัดการบัญชีบุคลากร', 'บุคลากร'],
            'organization.manage' => ['จัดการแผนกและตำแหน่ง', 'องค์กร'],
            'roles.manage' => ['จัดการบทบาทและสิทธิ์', 'สิทธิ์'],
            'categories.manage' => ['จัดการหมวดและกำหนดผู้คุม', 'การศึกษา'],
            'categories.view' => ['ดูหมวดที่ได้รับมอบหมาย', 'การศึกษา'],
            'courses.manage.assigned' => ['ควบคุมหลักสูตรในหมวดที่ได้รับมอบหมาย', 'การศึกษา'],
            'department.view' => ['ดูบุคลากรในแผนกของตนเอง', 'องค์กร'],
            'audit.view' => ['ดูประวัติการดำเนินงาน', 'ตรวจสอบ'],
            'settings.manage' => ['ตั้งค่าระบบ', 'ระบบ'],
            'reports.view.all' => ['ดูรายงานทั้งองค์กร', 'รายงาน'],
            'reports.view.assigned' => ['ดูรายงานหมวดที่ควบคุม', 'รายงาน'],
            'reports.view.department' => ['ดูรายงานแผนกของตน', 'รายงาน'],
            'points.manage' => ['กำหนดกฎและปรับแต้ม', 'แต้ม'],
            'welfare.manage' => ['อนุมัติร้านและจัดการคูปอง', 'สวัสดิการ'],
            'welfare.receive' => ['รับคูปองของร้านที่ได้รับมอบหมาย', 'สวัสดิการ'],
            'welfare.reports' => ['ดูรายงานคูปองของร้านที่ได้รับมอบหมาย', 'สวัสดิการ'],
            'welfare.claims' => ['จัดทำใบสรุปส่งเบิกของร้านที่ได้รับมอบหมาย', 'สวัสดิการ'],
        ];
    }

    /** @return array<string, array{name: string, permissions: list<string>}> */
    public static function roles(): array
    {
        return [
            'administrator' => ['name' => 'ผู้ดูแลระบบ', 'permissions' => array_keys(self::all())],
            'instructor' => ['name' => 'ผู้คุมกลุ่มการศึกษา', 'permissions' => ['categories.view', 'courses.manage.assigned', 'reports.view.assigned']],
            'department-manager' => ['name' => 'หัวหน้าแผนก', 'permissions' => ['department.view', 'reports.view.department']],
            'learner' => ['name' => 'ผู้เรียน', 'permissions' => []],
            'welfare-shop' => ['name' => 'ร้านสวัสดิการ', 'permissions' => ['welfare.receive', 'welfare.reports', 'welfare.claims']],
        ];
    }
}
