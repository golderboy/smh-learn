<script setup lang="ts">
defineOptions({ name: 'DepartmentStaffPage' });
import AppLayout from '../Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import Pagination from '../Components/Pagination.vue';
import type { NamedRecord, Paginated } from '../Types/foundation';
const props = defineProps<{
    departmentName: string;
    canSelectDepartments: boolean;
    departments: NamedRecord[];
    filters: { department_id: number | null };
    staff: Paginated<{
        id: number;
        display_name: string;
        department: NamedRecord | null;
        position: NamedRecord | null;
        is_active: boolean;
    }>;
}>();
const filter = useForm({
    department_id: props.filters.department_id === null ? '' : String(props.filters.department_id),
});
</script>
<template>
    <AppLayout title="บุคลากรในแผนก" :subtitle="departmentName"
        ><template v-if="canSelectDepartments" #actions
            ><Link class="btn btn-secondary" href="/admin/users">จัดการบัญชีบุคลากร</Link></template
        >
        <section class="surface">
            <form
                v-if="canSelectDepartments"
                class="filter-bar"
                @submit.prevent="
                    filter.get('/department', { preserveState: true, preserveScroll: true })
                "
            >
                <div class="field">
                    <label for="department-filter">แผนกที่ต้องการดู</label
                    ><select id="department-filter" v-model="filter.department_id">
                        <option value="">ทุกแผนก</option>
                        <option
                            v-for="department in departments"
                            :key="department.id"
                            :value="String(department.id)"
                        >
                            {{ department.name }}
                        </option>
                    </select>
                </div>
                <button class="btn btn-primary" :disabled="filter.processing">แสดงบุคลากร</button>
            </form>
            <p v-else-if="filters.department_id === null" class="info-banner">
                บัญชีนี้ยังไม่ได้กำหนดแผนก กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกก่อนดูข้อมูลบุคลากร
            </p>
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>บุคลากร</th>
                            <th v-if="canSelectDepartments">แผนก</th>
                            <th>ตำแหน่ง</th>
                            <th>สถานะบัญชี</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in staff.data" :key="user.id">
                            <td>{{ user.display_name }}</td>
                            <td v-if="canSelectDepartments">
                                {{ user.department?.name ?? 'ไม่ระบุแผนก' }}
                            </td>
                            <td>{{ user.position?.name ?? 'ไม่ระบุตำแหน่ง' }}</td>
                            <td>
                                <span
                                    class="status-badge"
                                    :class="user.is_active ? 'is-active' : 'is-inactive'"
                                    >{{ user.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!staff.data.length">
                            <td :colspan="canSelectDepartments ? 4 : 3" class="empty-state">
                                ยังไม่มีบุคลากรในแผนกนี้
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="staff.links"
                :total="staff.total"
                :from="staff.from"
                :to="staff.to"
            /></section
    ></AppLayout>
</template>
