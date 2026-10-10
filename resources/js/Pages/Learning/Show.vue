<script setup lang="ts">
defineOptions({ name: 'LearningWorkspace' });
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import LearningVideo from '../../Components/LearningVideo.vue';
import LearningPdf from '../../Components/LearningPdf.vue';
import { learningPost } from '../../learning-http';
import type {
    ContentRecord,
    EnrollmentRecord,
    ModuleRecord,
    ProgressRecord,
} from '../../Types/learning';
import { stateLabels } from '../../Types/learning';
import type { AssessmentSummary } from '../../Types/assessment';
import FormErrors from '../../Components/FormErrors.vue';
import UiDialog from '../../Components/UiDialog.vue';
import LessonOutline from '../../Components/LessonOutline.vue';
import { usePage } from '@inertiajs/vue3';
const props = defineProps<{
    enrollment: EnrollmentRecord;
    modules: ModuleRecord[];
    progress: ProgressRecord[];
    canLearn: boolean;
    assessment: AssessmentSummary;
    selectedLessonId?: number | null;
}>();
const localEnrollment = ref(props.enrollment);
const localProgress = ref(props.progress);
watch(
    () => props.enrollment,
    (value) => {
        localEnrollment.value = value;
    },
);
watch(
    () => props.progress,
    (value) => {
        localProgress.value = value;
    },
);
const lessons = computed(() => props.modules.flatMap((module) => module.lessons));
const selected = ref(props.selectedLessonId ?? lessons.value[0]?.id);
const lesson = computed(() => lessons.value.find((item) => item.id === selected.value));
const page = usePage();
const outlineOpen = ref(false);
const linkedIds = computed(
    () => new Set(lessons.value.flatMap((item) => (item.documents ?? []).map((doc) => doc.id))),
);
const visibleModules = computed(() =>
    props.modules
        .map((module) => ({
            ...module,
            lessons: module.lessons.filter((item) =>
                item.contents.some(
                    (content) =>
                        content.content_type !== 'document' || !linkedIds.value.has(content.id),
                ),
            ),
        }))
        .filter((module) => module.lessons.length),
);
const displayedContents = computed(() => {
    const all = [...(lesson.value?.contents ?? []), ...(lesson.value?.documents ?? [])];
    return [...new Map(all.map((content) => [content.id, content])).values()].sort(
        (a, b) => Number(b.content_type === 'video') - Number(a.content_type === 'video'),
    );
});
const error = ref('');
const reading = ref<number | null>(null);
const readyDocuments = ref<number[]>([]);
const contentProgress = (id: number) => localProgress.value.find((item) => item.content_id === id);
const lessonComplete = (id: number) => {
    const all = [
        ...(lessons.value.find((item) => item.id === id)?.contents ?? []),
        ...(lessons.value.find((item) => item.id === id)?.documents ?? []),
    ];
    const required = all.filter((item) => item.is_required);
    const contents = required.length ? required : all;
    return contents.length > 0 && contents.every((item) => contentProgress(item.id)?.completed_at);
};
const orderedLessons = computed(() => visibleModules.value.flatMap((module) => module.lessons));
const lessonUnlocked = (id: number) => {
    const index = orderedLessons.value.findIndex((item) => item.id === id);
    return (
        props.canLearn &&
        index >= 0 &&
        orderedLessons.value.slice(0, index).every((item) => lessonComplete(item.id))
    );
};
const nextLesson = computed(
    () =>
        orderedLessons.value[
            orderedLessons.value.findIndex((item) => item.id === selected.value) + 1
        ],
);
const previousLesson = computed(
    () =>
        orderedLessons.value[
            orderedLessons.value.findIndex((item) => item.id === selected.value) - 1
        ],
);
const completedIds = computed(() =>
    orderedLessons.value.filter((item) => lessonComplete(item.id)).map((item) => item.id),
);
const unlockedIds = computed(() =>
    orderedLessons.value.filter((item) => lessonUnlocked(item.id)).map((item) => item.id),
);
function selectLesson(id: number) {
    if (lessonUnlocked(id))
        router.get(`/learning/${props.enrollment.id}`, { lesson: id }, { preserveScroll: true });
}
function saved(progress: ProgressRecord, enrollment: EnrollmentRecord) {
    localProgress.value = [
        ...localProgress.value.filter((item) => item.content_id !== progress.content_id),
        progress,
    ];
    localEnrollment.value = {
        ...localEnrollment.value,
        ...enrollment,
        session: localEnrollment.value.session,
    };
}
async function open(content: ContentRecord) {
    try {
        const result = await learningPost<{ progress: ProgressRecord }>(
            `/learning/${props.enrollment.id}/contents/${content.id}/open`,
            {},
        );
        localProgress.value = [
            ...localProgress.value.filter((item) => item.content_id !== content.id),
            result.progress,
        ];
        reading.value = content.id;
        if (content.content_type === 'link' && content.external_url)
            window.open(content.external_url, '_blank', 'noopener,noreferrer');
        error.value = '';
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'เปิดเนื้อหาไม่ได้';
    }
}
</script>
<template>
    <AppLayout
        mode="learning"
        :title="enrollment.session.version?.course?.title ?? 'บทเรียน'"
        :subtitle="`${enrollment.session.name} · ปีงบประมาณ ${enrollment.session.fiscal_year}`"
        ><template #actions
            ><Link
                class="btn btn-secondary"
                :href="`/courses/${enrollment.session.version?.course?.id}`"
                >เอกสารทั้งหลักสูตร</Link
            ><Link class="btn btn-secondary" href="/learning">การเรียนของฉัน</Link></template
        >
        <FormErrors :errors="page.props.errors as Record<string, string>" />
        <section class="surface section-pad">
            <div class="learning-steps" aria-label="ขั้นตอนการเรียน">
                <span :class="{ complete: !!enrollment.pretest_completed_at }">1. Pretest</span
                ><span :class="{ complete: !!localEnrollment.content_completed_at }"
                    >2. เรียนตามเกณฑ์</span
                ><span :class="{ complete: localEnrollment.state === 'COMPLETED' }"
                    >3. Posttest</span
                >
            </div>
            <div class="section-heading">
                <strong>{{ stateLabels[localEnrollment.state] }}</strong
                ><span>ความก้าวหน้า {{ localEnrollment.progress_percent }}%</span>
            </div>
            <progress
                :value="Number(localEnrollment.progress_percent)"
                max="100"
                aria-label="ความก้าวหน้าหลักสูตร"
            ></progress>
            <p v-if="localEnrollment.state === 'CONTENT_COMPLETED'" class="notice">
                เรียนครบตามเกณฑ์แล้ว ต้องส่ง Posttest อย่างน้อย 1 ครั้งก่อนจบหลักสูตร
            </p>
            <p v-if="localEnrollment.state === 'COMPLETED'" class="notice success">
                เรียนจบตามเกณฑ์ของรอบเรียนนี้แล้ว
            </p>
            <p v-if="!canLearn" class="notice error">
                ยังเปิดเนื้อหาไม่ได้: รอบเรียนปิด การเรียนถูกยกเลิก หรือยังไม่ได้ส่ง Pretest
            </p>
            <div
                v-if="!enrollment.cancelled_at && enrollment.session.status === 'open'"
                class="assessment-actions"
            >
                <p v-if="!enrollment.pretest_completed_at">
                    ขั้นที่ 1: ทำ Pretest ให้ครบก่อนเริ่มเรียน ไม่บังคับคะแนนขั้นต่ำ
                </p>
                <button
                    v-if="!enrollment.pretest_completed_at"
                    class="btn btn-primary"
                    :disabled="!assessment.pretestReady"
                    @click="
                        router.post(`/learning/${enrollment.id}/exams/start`, {
                            exam_type: 'PRETEST',
                        })
                    "
                >
                    ทำ Pretest ก่อนเรียน
                </button>
                <p v-if="localEnrollment.content_completed_at">
                    ขั้นที่ 3: Posttest ใช้แล้ว {{ assessment.usedPosttestAttempts }} /
                    {{ assessment.maxPosttestAttempts }} ครั้ง (จำนวนครั้งรวมทั้งหมด)
                </p>
                <button
                    v-if="localEnrollment.content_completed_at"
                    class="btn btn-primary"
                    :disabled="
                        !assessment.posttestReady ||
                        (assessment.usedPosttestAttempts >= assessment.maxPosttestAttempts &&
                            !assessment.attempts.some(
                                (attempt) => attempt.status === 'IN_PROGRESS',
                            ))
                    "
                    @click="
                        router.post(`/learning/${enrollment.id}/exams/start`, {
                            exam_type: 'POSTTEST',
                        })
                    "
                >
                    {{
                        assessment.attempts.some((attempt) => attempt.status === 'IN_PROGRESS')
                            ? 'ทำข้อสอบต่อ'
                            : assessment.usedPosttestAttempts
                              ? 'ทำ Posttest อีกครั้ง'
                              : 'ทำ Posttest หลังเรียน'
                    }}
                </button>
                <p v-if="assessment.bestPosttest" class="result-score">
                    คะแนน Posttest สูงสุด {{ assessment.bestPosttest.percentage }}%
                </p>
                <p v-if="assessment.bestPosttest">
                    {{ assessment.bestPosttest.score }} /
                    {{ assessment.bestPosttest.max_score }} คะแนน ·
                    จบหลักสูตรได้โดยไม่บังคับคะแนนผ่าน
                </p>
                <div v-if="assessment.attempts.length" class="button-row">
                    <Link
                        v-for="attempt in assessment.attempts"
                        :key="attempt.id"
                        class="text-link"
                        :href="`/attempts/${attempt.id}`"
                        >{{ attempt.status === 'IN_PROGRESS' ? 'ทำต่อ' : 'ผล' }}
                        {{ attempt.exam_type === 'PRETEST' ? 'Pretest' : 'Posttest' }}
                        ครั้งที่ {{ attempt.attempt_number
                        }}{{ attempt.percentage !== null ? ` (${attempt.percentage}%)` : '' }}</Link
                    >
                </div>
            </div>
        </section>
        <div class="learning-columns">
            <button
                class="btn btn-secondary lesson-mobile-toggle"
                :aria-expanded="outlineOpen"
                @click="outlineOpen = true"
            >
                รายการบทเรียน · {{ orderedLessons.length }} บท
            </button>
            <aside
                class="surface section-pad lesson-outline desktop-lesson-outline"
                aria-label="รายการบทเรียน"
            >
                <LessonOutline
                    :modules="visibleModules"
                    :selected-id="selected"
                    :completed-ids="completedIds"
                    :unlocked-ids="unlockedIds"
                    @select="selectLesson"
                />
            </aside>
            <section v-if="lesson" class="surface section-pad lesson-main">
                <h2>{{ lesson.title }}</h2>
                <p>{{ lesson.description }}</p>
                <p v-if="error" class="notice error" role="alert">{{ error }}</p>
                <div v-if="canLearn && lessonUnlocked(lesson.id)">
                    <article
                        v-for="content in displayedContents"
                        :key="content.id"
                        class="learning-content"
                    >
                        <div class="section-heading">
                            <h3>{{ content.title }}</h3>
                            <span class="badge">{{
                                contentProgress(content.id)?.completed_at
                                    ? 'เรียนครบแล้ว'
                                    : content.is_required
                                      ? 'จำเป็น'
                                      : 'เพิ่มเติม'
                            }}</span>
                        </div>
                        <LearningVideo
                            v-if="content.content_type === 'video'"
                            :key="content.id"
                            :content="content"
                            :enrollment-id="enrollment.id"
                            :progress="contentProgress(content.id)"
                            :threshold="enrollment.session.required_video_watch_percent"
                            @saved="saved"
                        />
                        <div v-else-if="content.content_type === 'document'">
                            <LearningPdf
                                :url="`/media/${content.id}?enrollment=${enrollment.id}`"
                                :title="content.title"
                                @ready="readyDocuments.push(content.id)"
                            />
                            <p class="muted">อ่านเอกสารประกอบแล้วกดยืนยันเพื่อบันทึกความก้าวหน้า</p>
                            <div class="button-row">
                                <a
                                    class="btn btn-secondary"
                                    :href="`/media/${content.id}?enrollment=${enrollment.id}`"
                                    target="_blank"
                                    rel="noopener"
                                    >เปิดเอกสาร PDF</a
                                ><a
                                    class="text-link"
                                    :href="`/media/${content.id}?enrollment=${enrollment.id}&download=1`"
                                    >ดาวน์โหลดเอกสาร</a
                                >
                            </div>
                        </div>
                        <div v-else-if="content.content_type === 'image'">
                            <img
                                class="learning-image"
                                :src="`/media/${content.id}?enrollment=${enrollment.id}`"
                                :alt="content.title"
                                @load="readyDocuments.push(content.id)"
                                @error="error = 'เปิดภาพประกอบไม่ได้ กรุณาลองใหม่'"
                            />
                            <a
                                class="text-link"
                                :href="`/media/${content.id}?enrollment=${enrollment.id}&download=1`"
                                >ดาวน์โหลดภาพประกอบ</a
                            >
                        </div>
                        <div v-else>
                            <button class="btn btn-secondary" @click="open(content)">
                                {{
                                    content.content_type === 'article'
                                        ? 'เปิดอ่านบทความ'
                                        : 'เปิดลิงก์ประกอบการเรียน'
                                }}
                            </button>
                            <!-- body is produced by LearningHtml's tag whitelist with no attributes; covered by server XSS tests. -->
                            <!-- eslint-disable vue/no-v-html -->
                            <div
                                v-if="content.content_type === 'article' && reading === content.id"
                                class="article-body"
                                v-html="content.body"
                            ></div>
                            <!-- eslint-enable vue/no-v-html -->
                        </div>
                        <button
                            v-if="
                                content.content_type !== 'video' &&
                                !contentProgress(content.id)?.completed_at
                            "
                            class="btn btn-primary confirm-read"
                            :disabled="
                                ['document', 'image'].includes(content.content_type) &&
                                !readyDocuments.includes(content.id)
                            "
                            @click="
                                router.post(
                                    `/learning/${enrollment.id}/contents/${content.id}/confirm`,
                                )
                            "
                        >
                            ยืนยันว่าอ่านแล้ว
                        </button>
                    </article>
                    <div class="lesson-navigation">
                        <button
                            v-if="previousLesson"
                            class="btn btn-secondary"
                            @click="selectLesson(previousLesson.id)"
                        >
                            บทก่อนหน้า
                        </button>
                        <p class="muted">
                            {{
                                lessonComplete(lesson.id)
                                    ? 'เรียนบทนี้ครบตามเกณฑ์แล้ว'
                                    : 'รับชมวิดีโอให้ถึงเกณฑ์และยืนยันอ่านเนื้อหาที่จำเป็นก่อนเรียนบทถัดไป'
                            }}
                        </p>
                        <button
                            v-if="nextLesson"
                            class="btn btn-primary"
                            :disabled="!lessonComplete(lesson.id) || !lessonUnlocked(nextLesson.id)"
                            @click="selectLesson(nextLesson.id)"
                        >
                            บทถัดไป
                        </button>
                        <p v-else-if="lessonComplete(lesson.id)" class="notice">
                            ครบถึงบทสุดท้ายแล้ว ทำ Posttest หลังเรียนเพื่อสรุปผลหลักสูตร
                        </p>
                    </div>
                </div>
            </section>
        </div>
        <UiDialog v-model:open="outlineOpen" title="รายการบทเรียน" drawer>
            <div v-if="outlineOpen" class="lesson-outline">
                <LessonOutline
                    :modules="visibleModules"
                    :selected-id="selected"
                    :completed-ids="completedIds"
                    :unlocked-ids="unlockedIds"
                    @select="
                        outlineOpen = false;
                        selectLesson($event);
                    "
                /></div
        ></UiDialog>
    </AppLayout>
</template>
