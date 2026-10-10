<script setup lang="ts">
import { useDateTime } from '../../date-time';
const dateTimeLabel = useDateTime();
defineOptions({ name: 'AuditHistoryPage' });
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import type { AuditRecord, Paginated } from '../../Types/foundation';
const props = defineProps<{ logs: Paginated<AuditRecord>; filters: { action?: string } }>();
const filter = useForm({ action: props.filters.action ?? '' });
const labels: Record<string, string> = {
    'auth.login': 'เข้าสู่ระบบ',
    'auth.logout': 'ออกจากระบบ',
    'auth.failed': 'เข้าสู่ระบบไม่สำเร็จ',
    'user.created': 'เพิ่มบัญชี',
    'user.updated': 'แก้ไขบัญชี',
    'user.password_reset': 'รีเซ็ตรหัสผ่าน',
    'user.password_changed': 'เปลี่ยนรหัสผ่าน',
    'user.profile_updated': 'แก้ไขข้อมูลติดต่อ',
    'category.created': 'เพิ่มหมวดหลักสูตร',
    'category.updated': 'แก้ไขหมวดและผู้คุม',
    'role.created': 'เพิ่มบทบาท',
    'role.updated': 'แก้ไขสิทธิ์บทบาท',
    'setting.updated': 'แก้ไขการตั้งค่า',
    'departments.created': 'เพิ่มแผนก',
    'departments.updated': 'แก้ไขแผนก',
    'positions.created': 'เพิ่มตำแหน่ง',
    'positions.updated': 'แก้ไขตำแหน่ง',
};
const detail = (values: Record<string, unknown> | null | undefined) =>
    Object.entries(values ?? {})
        .map(
            ([key, value]) =>
                `${key}: ${Array.isArray(value) ? value.join(', ') : typeof value === 'object' ? 'ข้อมูลประกอบ' : String(value)}`,
        )
        .join(' · ');
</script>
<template>
    <AppLayout
        title="ประวัติการดำเนินงาน"
        subtitle="ตรวจสอบผู้ดำเนินงาน เวลา และเหตุผลการเปลี่ยนแปลง"
        ><section class="surface">
            <form class="filter-bar" @submit.prevent="filter.get('/admin/audit')">
                <div class="field">
                    <label for="audit-action">ประเภทการดำเนินงาน</label
                    ><select id="audit-action" v-model="filter.action">
                        <option value="">ทั้งหมด</option>
                        <option v-for="(label, code) in labels" :key="code" :value="code">
                            {{ label }}
                        </option>
                    </select>
                </div>
                <button class="btn btn-secondary">ค้นหา</button>
            </form>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>เวลา / ผู้ดำเนินงาน</th>
                            <th>รายการ</th>
                            <th>เหตุผล</th>
                            <th>รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id">
                            <td>
                                {{ dateTimeLabel(log.created_at)
                                }}<small class="muted">{{
                                    log.user?.display_name ?? 'ไม่ระบุบัญชี'
                                }}</small>
                            </td>
                            <td>
                                {{ labels[log.action] ?? 'การดำเนินงานระบบ'
                                }}<small class="muted"
                                    >รายการอ้างอิง #{{ log.entity_id ?? '—' }}</small
                                >
                            </td>
                            <td>{{ log.reason || '—' }}</td>
                            <td>
                                <details v-if="log.old_values || log.new_values">
                                    <summary>ดูการเปลี่ยนแปลง</summary>
                                    <p v-if="log.old_values" class="audit-detail">
                                        <strong>ก่อนแก้ไข:</strong> {{ detail(log.old_values) }}
                                    </p>
                                    <p v-if="log.new_values" class="audit-detail">
                                        <strong>หลังแก้ไข:</strong> {{ detail(log.new_values) }}
                                    </p>
                                    <p class="helper">IP: {{ log.ip_address ?? 'ไม่ระบุ' }}</p>
                                </details>
                                <span v-else class="muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="!logs.data.length">
                            <td colspan="4" class="empty-state">ไม่พบประวัติตามเงื่อนไข</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="logs.links"
                :total="logs.total"
                :from="logs.from"
                :to="logs.to"
            /></section
    ></AppLayout>
</template>
