<script setup lang="ts">
defineOptions({ name: 'CourseDetails' });
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AppIcon from '../../Components/AppIcon.vue';
import type { CourseRecord, EnrollmentRecord, SessionRecord } from '../../Types/learning';
import { dateLabel } from '../../Types/learning';
const props = defineProps<{
    course: CourseRecord;
    sessions: SessionRecord[];
    enrollments: EnrollmentRecord[];
    documents: Record<
        number,
        { id: number; title: string; lesson: string; type: string; url: string }[]
    >;
}>();
const enrollment = (id: number) => props.enrollments.find((item) => item.course_session_id === id);
</script>
<template>
    <AppLayout :title="course.title" :subtitle="course.category.name"
        ><template #actions
            ><Link class="btn btn-secondary" href="/courses">กลับหลักสูตร</Link></template
        >
        <section class="course-detail-banner">
            <div>
                <p class="eyebrow"><AppIcon name="cap" />{{ course.category.name }}</p>
                <h2>{{ course.title }}</h2>
                <p>
                    {{
                        course.description ||
                        'เลือกเข้ารอบเรียนที่เปิด แล้วทำ Pretest เพื่อเริ่มต้นการเรียนรู้'
                    }}
                </p>
                <span class="badge">{{ course.code }}</span>
            </div>
            <img :src="'/images/cobalt-hero.webp'" alt="" width="280" height="200" />
        </section>
        <section class="surface section-pad">
            <h2>รอบเรียนที่เปิด</h2>
            <div v-if="!sessions.length" class="empty-state">
                ยังไม่มีรอบเรียนที่เปิดในช่วงเวลานี้
            </div>
            <article v-for="session in sessions" :key="session.id" class="session-row">
                <div>
                    <h3>{{ session.name }}</h3>
                    <p>
                        ปีงบประมาณ {{ session.fiscal_year }} ·
                        {{ dateLabel(session.start_date) }} ถึง {{ dateLabel(session.end_date) }}
                    </p>
                    <p class="muted">
                        ดูวิดีโออย่างน้อย {{ session.required_video_watch_percent }}% ·
                        เรียนบทที่จำเป็น {{ session.required_progress_percent }}% · Posttest ได้รวม
                        {{ session.max_attempts }} ครั้ง
                    </p>
                </div>
                <Link
                    v-if="enrollment(session.id) && !enrollment(session.id)?.cancelled_at"
                    class="btn btn-primary"
                    :href="`/learning/${enrollment(session.id)?.id}`"
                    >เข้าสู่บทเรียน</Link
                ><button
                    v-else-if="session.enrollment_mode === 'optional' && !enrollment(session.id)"
                    class="btn btn-primary"
                    @click="router.post(`/sessions/${session.id}/enroll`)"
                >
                    สมัครเรียน</button
                ><span v-else class="badge">{{
                    enrollment(session.id)?.cancelled_at
                        ? 'การเรียนถูกยกเลิก'
                        : 'ต้องได้รับมอบหมายก่อน'
                }}</span>
            </article>
        </section>
        <section class="surface section-pad">
            <h2>เอกสารประกอบการเรียนทั้งหลักสูตร</h2>
            <p class="muted">
                ดาวน์โหลด PDF/ภาพ และเปิดลิงก์ประกอบทุกบทได้หลังส่ง Pretest ไม่บังคับคะแนนขั้นต่ำ
                การดาวน์โหลดไม่ถือเป็นการยืนยันอ่าน
            </p>
            <div v-if="!Object.keys(documents).length" class="empty-state">
                สมัครเรียนและทำ Pretest ให้เสร็จก่อนเปิดเอกสาร
            </div>
            <section v-for="(items, enrollmentId) in documents" :key="enrollmentId">
                <h3>
                    {{
                        sessions.find(
                            (item) =>
                                item.id ===
                                enrollments.find((record) => record.id === Number(enrollmentId))
                                    ?.course_session_id,
                        )?.name
                    }}
                </h3>
                <p v-if="!items.length" class="muted">หลักสูตรนี้ไม่มีเอกสารประกอบ</p>
                <ul class="content-list">
                    <li v-for="document in items" :key="document.id">
                        <div>
                            <strong>{{ document.title }}</strong
                            ><small>{{ document.lesson }}</small>
                        </div>
                        <a
                            class="btn btn-secondary"
                            :href="document.url"
                            :target="document.type === 'link' ? '_blank' : undefined"
                            rel="noopener noreferrer"
                            >{{ document.type === 'link' ? 'เปิดลิงก์' : 'ดาวน์โหลด' }}</a
                        >
                    </li>
                </ul>
            </section>
        </section></AppLayout
    >
</template>
