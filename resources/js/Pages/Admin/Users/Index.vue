<script setup lang="ts">
defineOptions({ name: 'UserAccountList' });
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import Pagination from '../../../Components/Pagination.vue';
import type { NamedRecord, Paginated, UserRecord } from '../../../Types/foundation';
const props = defineProps<{
    users: Paginated<UserRecord>;
    filters: { search?: string; department_id?: number };
    departments: NamedRecord[];
}>();
const filter = useForm({
    search: props.filters.search ?? '',
    department_id: props.filters.department_id ?? '',
});
</script>
<template>
    <AppLayout title="บัญชีบุคลากร" subtitle="จัดการข้อมูล บทบาท และสถานะการเข้าใช้งาน"
        ><template #actions
            ><Link class="btn btn-primary" href="/admin/users/create">เพิ่มบุคลากร</Link></template
        >
        <section class="surface">
            <form
                class="filter-bar"
                @submit.prevent="filter.get('/admin/users', { preserveState: true })"
            >
                <div class="field">
                    <label for="search-users">ค้นหาบุคลากร</label
                    ><input
                        id="search-users"
                        v-model="filter.search"
                        placeholder="ชื่อ รหัสบุคลากร หรือเลขประจำตัว"
                    />
                </div>
                <div class="field">
                    <label for="filter-department">แผนก</label
                    ><select id="filter-department" v-model="filter.department_id">
                        <option value="">ทุกแผนก</option>
                        <option
                            v-for="department in departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }}
                        </option>
                    </select>
                </div>
                <button class="btn btn-secondary" :disabled="filter.processing">ค้นหา</button>
            </form>
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>บุคลากร</th>
                            <th>เลขประจำตัว</th>
                            <th>แผนก / ตำแหน่ง</th>
                            <th>บทบาท</th>
                            <th>สถานะ</th>
                            <th><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.id">
                            <td>
                                <strong>{{ user.display_name }}</strong
                                ><small class="muted identifier">{{ user.employee_code }}</small>
                            </td>
                            <td class="identifier">{{ user.username }}</td>
                            <td>
                                {{ user.department?.name ?? 'ไม่ระบุแผนก'
                                }}<small class="muted">{{
                                    user.position?.name ?? 'ไม่ระบุตำแหน่ง'
                                }}</small>
                            </td>
                            <td>
                                <span
                                    v-for="role in user.roles"
                                    :key="role.id"
                                    class="role-label"
                                    >{{ role.name }}</span
                                >
                            </td>
                            <td>
                                <span
                                    class="status-badge"
                                    :class="user.is_active ? 'is-active' : 'is-inactive'"
                                    >{{ user.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span
                                >
                            </td>
                            <td>
                                <Link
                                    v-if="!user.is_root_admin"
                                    class="btn btn-quiet"
                                    :href="`/admin/users/${user.id}/edit`"
                                    :aria-label="`แก้ไข ${user.display_name}`"
                                    >แก้ไข</Link
                                >
                                <span v-else class="status-badge is-active"
                                    >แอดมินตั้งต้น · บัญชีป้องกัน</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td colspan="6" class="empty-state">ไม่พบข้อมูลตามเงื่อนไขค้นหา</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="users.links"
                :total="users.total"
                :from="users.from"
                :to="users.to"
            /></section
    ></AppLayout>
</template>
