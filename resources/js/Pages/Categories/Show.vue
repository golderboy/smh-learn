<script setup lang="ts">
defineOptions({ name: 'CourseCategoryDetails' });
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { CategoryRecord } from '../../Types/foundation';
defineProps<{
    category: CategoryRecord;
    courses: { id: number; title: string; status: string }[];
    canManageCourses: boolean;
}>();
</script>
<template>
    <AppLayout :title="category.name" subtitle="พื้นที่ควบคุมหลักสูตรตามหมวดที่ได้รับมอบหมาย"
        ><template #actions
            ><Link class="btn btn-secondary" href="/categories">กลับหมวดหลักสูตร</Link></template
        >
        <section class="surface section-pad">
            <dl class="detail-list">
                <div>
                    <dt>รหัสหมวด</dt>
                    <dd class="identifier">{{ category.code }}</dd>
                </div>
                <div>
                    <dt>คำอธิบาย</dt>
                    <dd>{{ category.description || 'ไม่มีคำอธิบายเพิ่มเติม' }}</dd>
                </div>
                <div>
                    <dt>ผู้คุม</dt>
                    <dd>
                        {{
                            category.instructors.map((user) => user.display_name).join(', ') ||
                            'ยังไม่ได้กำหนด'
                        }}
                    </dd>
                </div>
            </dl>
            <hr />
            <h2>หลักสูตรในหมวดนี้</h2>
            <div v-if="!courses.length" class="empty-state">
                <strong>ยังไม่มีหลักสูตรในหมวดนี้</strong>
                <p>หลักสูตรที่อยู่ในหมวดจะปรากฏในพื้นที่นี้</p>
            </div>
            <div v-else class="assigned-list">
                <Link
                    v-for="course in courses"
                    :key="course.id"
                    :href="
                        canManageCourses
                            ? `/manage/courses/${course.id}/edit`
                            : `/courses/${course.id}`
                    "
                    >{{ course.title
                    }}<span>{{
                        {
                            draft: 'ฉบับร่าง',
                            published: 'เผยแพร่แล้ว',
                            archived: 'เก็บเข้าประวัติ',
                        }[course.status]
                    }}</span></Link
                >
            </div>
        </section></AppLayout
    >
</template>
