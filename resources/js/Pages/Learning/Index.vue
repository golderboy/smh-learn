<script setup lang="ts">
defineOptions({ name: 'MyLearning' });
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import type { Paginated } from '../../Types/foundation';
import type { EnrollmentRecord } from '../../Types/learning';
import { dateLabel, stateLabels } from '../../Types/learning';
defineProps<{ enrollments: Paginated<EnrollmentRecord> }>();
const overdue = (item: EnrollmentRecord) =>
    !!item.due_date &&
    !item.completed_at &&
    !item.cancelled_at &&
    item.due_date.slice(0, 10) < new Date().toLocaleDateString('en-CA');
</script>
<template>
    <AppLayout
        title="การเรียนของฉัน"
        subtitle="หลักสูตรที่สมัครและได้รับมอบหมาย พร้อมประวัติความก้าวหน้า"
        ><template #actions
            ><Link class="btn btn-primary" href="/courses">เลือกหลักสูตร</Link></template
        >
        <section class="surface section-pad">
            <div v-if="!enrollments.data.length" class="empty-state">
                <strong>ยังไม่มีรายการเรียน</strong>
                <p>เลือกหลักสูตรที่เปิดให้สมัคร หรือรอการมอบหมายจากผู้คุมหลักสูตร</p>
                <Link class="btn btn-secondary" href="/courses">ดูหลักสูตรทั้งหมด</Link>
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>หลักสูตรและรอบเรียน</th>
                            <th>สถานะ</th>
                            <th>ความก้าวหน้า</th>
                            <th>กำหนดส่ง</th>
                            <th>การดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in enrollments.data" :key="item.id">
                            <td>
                                <div class="learning-course-cell">
                                    <span class="course-thumbnail"
                                        ><img
                                            :src="'/images/cobalt-hero.webp'"
                                            alt=""
                                            width="80"
                                            height="60"
                                            loading="lazy"
                                    /></span>
                                    <div>
                                        <strong>{{ item.session.version?.course?.title }}</strong
                                        ><small class="muted block"
                                            >{{ item.session.name }} · ปี
                                            {{ item.session.fiscal_year }}</small
                                        >
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge">{{ stateLabels[item.state] }}</span
                                ><span v-if="overdue(item)" class="danger-text block"
                                    >เกินกำหนดส่ง</span
                                >
                            </td>
                            <td>
                                {{ item.progress_percent }}%<progress
                                    :value="Number(item.progress_percent)"
                                    max="100"
                                    aria-label="ความก้าวหน้าหลักสูตร"
                                ></progress>
                            </td>
                            <td>{{ item.due_date ? dateLabel(item.due_date) : 'ไม่กำหนด' }}</td>
                            <td>
                                <Link class="btn btn-secondary" :href="`/learning/${item.id}`"
                                    >ดูบทเรียน</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="enrollments.links"
                :total="enrollments.total"
                :from="enrollments.from"
                :to="enrollments.to"
            /></section
    ></AppLayout>
</template>
