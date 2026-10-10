<script setup lang="ts">
defineOptions({ name: 'CourseCategoryList' });
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import type { CategoryRecord, Paginated } from '../../Types/foundation';
defineProps<{ categories: Paginated<CategoryRecord>; canManage: boolean }>();
</script>
<template>
    <AppLayout
        title="หมวดหลักสูตร"
        :subtitle="
            canManage
                ? 'จัดหมวดและกำหนดผู้คุมกลุ่มการศึกษา'
                : 'หมวดหลักสูตรที่คุณได้รับสิทธิ์ควบคุม'
        "
        ><template #actions
            ><Link v-if="canManage" class="btn btn-primary" href="/categories/create"
                >เพิ่มหมวดหลักสูตร</Link
            ></template
        >
        <section class="surface">
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>หมวดหลักสูตร</th>
                            <th>ผู้คุมกลุ่มการศึกษา</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories.data" :key="category.id">
                            <td>
                                <Link class="text-link" :href="`/categories/${category.id}`">{{
                                    category.name
                                }}</Link
                                ><small class="muted identifier">{{ category.code }}</small>
                            </td>
                            <td>
                                {{
                                    category.instructors
                                        .map((user) => user.display_name)
                                        .join(', ') || 'ยังไม่ได้กำหนดผู้คุม'
                                }}
                            </td>
                            <td>
                                <span
                                    class="status-badge"
                                    :class="category.is_active ? 'is-active' : 'is-inactive'"
                                    >{{ category.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span
                                >
                            </td>
                            <td>
                                <Link
                                    v-if="canManage"
                                    class="btn btn-quiet"
                                    :href="`/categories/${category.id}/edit`"
                                    :aria-label="`แก้ไข ${category.name}`"
                                    >แก้ไข</Link
                                ><Link
                                    v-else
                                    class="btn btn-quiet"
                                    :href="`/categories/${category.id}`"
                                    >ดูหมวด</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="!categories.data.length">
                            <td colspan="4" class="empty-state">
                                {{
                                    canManage
                                        ? 'ยังไม่มีหมวดหลักสูตร'
                                        : 'ยังไม่มีหมวดที่ได้รับมอบหมาย กรุณาติดต่อผู้ดูแลระบบ'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="categories.links"
                :total="categories.total"
                :from="categories.from"
                :to="categories.to"
            /></section
    ></AppLayout>
</template>
