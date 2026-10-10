<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../Types/foundation';
import AppIcon from './AppIcon.vue';
const emit = defineEmits<{ navigate: [] }>();
const page = usePage<SharedProps>();
const icon = (href: string) => {
    if (href === '/dashboard') return 'home';
    if (href.includes('points')) return 'star';
    if (href.includes('coupons') || href.includes('welfare')) return 'ticket';
    if (href.includes('roles')) return 'shield';
    if (href.includes('imports')) return 'document';
    if (href.includes('users') || href.includes('department') || href.includes('organization'))
        return 'users';
    if (href.includes('settings')) return 'settings';
    if (href.includes('reports')) return 'chart';
    if (href.includes('audit')) return 'clock';
    if (href.includes('categories')) return 'folder';
    if (href.includes('manage')) return 'document';
    return 'book';
};
const groups = computed(() => {
    const permissions = page.props.auth.user?.permissions ?? [];
    const visibleGroups = [
        {
            name: 'การเรียนรู้',
            items: [
                { text: 'ภาพรวม', href: '/dashboard', permission: '' },
                { text: 'การเรียนของฉัน', href: '/learning', permission: '' },
                { text: 'หลักสูตรทั้งหมด', href: '/courses', permission: '' },
                { text: 'แต้มของฉัน', href: '/points', permission: '' },
                { text: 'คูปองของฉัน', href: '/coupons', permission: '' },
            ],
        },
        {
            name: 'งานหลักสูตร',
            items: [
                {
                    text: 'จัดการหลักสูตร',
                    href: '/manage/courses',
                    permission: 'courses.manage.assigned',
                },
                {
                    text: 'หมวดหลักสูตร',
                    href: '/categories',
                    permission: permissions.includes('categories.manage')
                        ? 'categories.manage'
                        : 'categories.view',
                },
            ],
        },
        {
            name: 'ผลการเรียน',
            items: [
                { text: 'รายงานผลการเรียน', href: '/reports', permission: '' },
                { text: 'บุคลากรในแผนก', href: '/department', permission: 'department.view' },
            ],
        },
        {
            name: 'ร้านสวัสดิการ',
            items: [
                {
                    text: 'รับคูปองและส่งเบิก',
                    href: '/welfare/store',
                    permission: 'welfare.reports',
                },
            ],
        },
        {
            name: 'บริหารระบบ',
            items: [
                { text: 'บัญชีบุคลากร', href: '/admin/users', permission: 'users.manage' },
                {
                    text: 'นำเข้าข้อมูลพื้นฐาน',
                    href: '/admin/imports',
                    permission: 'master.import',
                },
                {
                    text: 'แผนก',
                    href: '/admin/organization/departments',
                    permission: 'organization.manage',
                },
                {
                    text: 'ตำแหน่ง',
                    href: '/admin/organization/positions',
                    permission: 'organization.manage',
                },
                { text: 'บทบาทและสิทธิ์', href: '/admin/roles', permission: 'roles.manage' },
                { text: 'จัดการแต้ม', href: '/admin/points', permission: 'points.manage' },
                { text: 'ร้านและคูปอง', href: '/admin/welfare', permission: 'welfare.manage' },
                { text: 'ประวัติการดำเนินงาน', href: '/admin/audit', permission: 'audit.view' },
                { text: 'ตั้งค่าระบบ', href: '/admin/settings', permission: 'settings.manage' },
            ],
        },
    ]
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => !item.permission || permissions.includes(item.permission),
            ),
        }))
        .filter((group) => group.items.length);
    const isAdmin = page.props.auth.user?.roles.some((role) => role.code === 'administrator');
    return isAdmin
        ? [
              ...visibleGroups.filter((group) => group.name === 'บริหารระบบ'),
              ...visibleGroups.filter((group) => group.name !== 'บริหารระบบ'),
          ]
        : visibleGroups;
});
const active = (href: string) =>
    page.url.split('?')[0] === href ||
    page.url.startsWith(`${href}/`) ||
    (href === '/manage/courses' && page.url.startsWith('/versions/'));
</script>
<template>
    <nav aria-label="เมนูหลัก" class="navigation-groups">
        <section v-for="group in groups" :key="group.name" class="nav-group">
            <h2>{{ group.name }}</h2>
            <template v-for="item in group.items" :key="item.href">
                <p v-if="item.href === '/admin/organization/departments'" class="nav-parent">
                    โครงสร้างองค์กร
                </p>
                <Link
                    :href="item.href"
                    class="nav-link"
                    :class="{
                        active: active(item.href),
                        'nav-child': item.href.startsWith('/admin/organization/'),
                    }"
                    :aria-current="active(item.href) ? 'page' : undefined"
                    @click="emit('navigate')"
                    ><AppIcon :name="icon(item.href)" /><span>{{ item.text }}</span></Link
                >
            </template>
        </section>
    </nav>
</template>
