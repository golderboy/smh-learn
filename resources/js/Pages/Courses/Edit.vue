<script setup lang="ts">
defineOptions({ name: 'CourseEditor' });
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import DraftModule from '../../Components/DraftModule.vue';
import SessionAssignment from '../../Components/SessionAssignment.vue';
import LessonDocuments from '../../Components/LessonDocuments.vue';
import UiDialog from '../../Components/UiDialog.vue';
import { confirmLeaveForms, hasUnsavedForms, useUnsavedForms } from '../../unsaved-forms';
import type { CourseRecord, VersionRecord } from '../../Types/learning';
import type { NamedRecord } from '../../Types/foundation';
const props = defineProps<{
    course: CourseRecord;
    versions: VersionRecord[];
    departments: NamedRecord[];
    positions: NamedRecord[];
    users: { id: number; display_name: string }[];
    today: string;
    fiscalYear: number;
}>();
const selected = ref(props.versions[0]?.id);
const version = computed(
    () => props.versions.find((item) => item.id === Number(selected.value)) ?? props.versions[0]!,
);
const page = usePage();
const tab = ref('overview');
const selectedModule = ref(version.value.modules[0]?.id);
const selectedLesson = ref<number | null>(version.value.modules[0]?.lessons[0]?.id ?? null);
watch(selected, () => {
    selectedModule.value = version.value.modules[0]?.id;
    selectedLesson.value = version.value.modules[0]?.lessons[0]?.id ?? null;
});
function changeVersion(event: Event) {
    if (confirmLeaveForms()) {
        metadata.reset();
        clone.reset();
        module.reset();
        session.reset();
        selected.value = Number((event.target as HTMLSelectElement).value);
    } else (event.target as HTMLSelectElement).value = String(selected.value);
}
watch(
    () => version.value.modules,
    (modules) => {
        if (!modules.some((item) => item.id === selectedModule.value)) {
            selectedModule.value = modules[0]?.id;
            selectedLesson.value = modules[0]?.lessons[0]?.id ?? null;
        }
        if (
            selectedLesson.value &&
            !modules.some((item) => item.lessons.some((entry) => entry.id === selectedLesson.value))
        )
            selectedLesson.value = null;
    },
    { deep: true },
);
const learnerLessonCount = computed(() => {
    const lessons = version.value.modules
        .flatMap((item) => item.lessons)
        .filter((item) => item.status === 'active');
    const linked = new Set(
        lessons.flatMap((item) => (item.documents ?? []).map((document) => document.id)),
    );
    return lessons.filter(
        (item) =>
            !item.contents.length ||
            item.contents.some(
                (content) => content.content_type !== 'document' || !linked.has(content.id),
            ),
    ).length;
});
const videoLessons = computed(() =>
    version.value.modules
        .flatMap((module) => module.lessons)
        .filter((lesson) => lesson.contents.some((content) => content.content_type === 'video')),
);
const documents = computed(() =>
    version.value.modules
        .flatMap((module) => module.lessons)
        .flatMap((lesson) => lesson.contents)
        .filter((content) => content.content_type === 'document'),
);
const metadata = useForm({
    title: props.course.title,
    description: props.course.description ?? '',
    status: props.course.status,
});
const clone = useForm({ version_name: `เวอร์ชัน ${props.versions.length + 1}` });
const module = useForm({ title: '', sort_order: '1' });
const session = useForm({
    name: '',
    fiscal_year: String(props.fiscalYear),
    start_date: props.today,
    end_date: props.today,
    enrollment_mode: 'optional',
    passing_score: '80',
    max_attempts: '1',
    required_progress_percent: '100',
    required_video_watch_percent: '80',
    posttest_required: true,
});
useUnsavedForms(metadata, clone, module, session);
const selectedSession = ref(version.value.sessions[0]?.id);
const newSessionPanel = ref<HTMLDetailsElement>();
watch(
    () => version.value.sessions,
    (items) => {
        if (!items.some((item) => item.id === selectedSession.value))
            selectedSession.value = items[0]?.id;
    },
    { deep: true },
);
const publishOpen = ref(false);
const publishWarning = ref('');
function requestPublication() {
    if (hasUnsavedForms()) {
        publishWarning.value = 'กรุณาบันทึกข้อมูลที่แก้ไขในทุกพื้นที่ให้ครบก่อนเผยแพร่เวอร์ชัน';
        return;
    }
    publishWarning.value = '';
    publishOpen.value = true;
}
const publication = useForm({});
function revealFormError(event: Event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const area = target.closest<HTMLElement>('[data-editor-area]')?.dataset.editorArea;
    if (area) tab.value = area;
    const moduleId = target.closest<HTMLElement>('[data-module-id]')?.dataset.moduleId;
    const lessonId = target.closest<HTMLElement>('[data-lesson-id]')?.dataset.lessonId;
    const sessionId = target.closest<HTMLElement>('[data-session-id]')?.dataset.sessionId;
    if (moduleId) selectedModule.value = Number(moduleId);
    if (lessonId) selectedLesson.value = Number(lessonId);
    if (sessionId) selectedSession.value = Number(sessionId);
}
function publish() {
    publication.post(`/versions/${version.value.id}/publish`, {
        onSuccess: () => {
            publishOpen.value = false;
        },
    });
}
</script>
<template>
    <AppLayout
        :title="course.title"
        :subtitle="`จัดการหลักสูตร · ${course.category.name}`"
        @reveal-form-error="revealFormError"
        ><template #actions
            ><Link class="btn btn-secondary" href="/manage/courses">กลับรายการ</Link></template
        ><FormErrors :errors="page.props.errors as Record<string, string>" />
        <section class="surface context-bar">
            <div class="field">
                <label for="version-choice">เวอร์ชันบทเรียน</label
                ><select id="version-choice" :value="selected" @change="changeVersion">
                    <option v-for="item in versions" :key="item.id" :value="item.id">
                        {{ item.version_name }} ({{
                            item.published_at ? 'เผยแพร่แล้ว' : 'ฉบับร่าง'
                        }})
                    </option>
                </select>
            </div>
            <div class="button-row">
                <span class="badge">{{
                    version.published_at ? 'เนื้อหาตรึงแล้ว' : 'เนื้อหาฉบับร่าง'
                }}</span
                ><button
                    v-if="!version.published_at"
                    class="btn btn-primary"
                    @click="requestPublication"
                >
                    เผยแพร่เวอร์ชัน
                </button>
            </div>
        </section>
        <p v-if="publishWarning" class="notice error" role="alert">{{ publishWarning }}</p>
        <nav class="editor-tabs" aria-label="พื้นที่จัดการหลักสูตร">
            <button
                v-for="item in [
                    { key: 'overview', label: 'ภาพรวม' },
                    { key: 'lessons', label: 'บทเรียน' },
                    { key: 'exams', label: 'ข้อสอบ' },
                    { key: 'sessions', label: 'รอบเรียนและผู้เรียน' },
                ]"
                :key="item.key"
                :aria-pressed="tab === item.key"
                @click="tab = item.key"
            >
                {{ item.label }}
            </button>
        </nav>
        <section
            v-show="tab === 'overview'"
            data-editor-area="overview"
            class="surface section-pad"
        >
            <details open>
                <summary>ข้อมูลหลักสูตร</summary>
                <form
                    class="stack-form"
                    @submit.prevent="metadata.put(`/manage/courses/${course.id}`)"
                >
                    <FormErrors :errors="metadata.errors" /><TextField
                        v-model="metadata.title"
                        name="edit-course-title"
                        label="ชื่อหลักสูตร"
                        required
                    />
                    <div class="field">
                        <label for="edit-course-description">คำอธิบาย</label
                        ><textarea
                            id="edit-course-description"
                            v-model="metadata.description"
                            rows="3"
                        ></textarea>
                    </div>
                    <div class="field">
                        <label for="edit-course-status">สถานะหลักสูตร</label
                        ><select id="edit-course-status" v-model="metadata.status">
                            <option value="draft">ฉบับร่าง</option>
                            <option value="published">เผยแพร่</option>
                            <option value="archived">เก็บเข้าประวัติ</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" :disabled="metadata.processing">
                        บันทึกข้อมูลหลักสูตร
                    </button>
                </form>
            </details>
            <p v-if="version.published_at" class="notice">
                เนื้อหาเวอร์ชันนี้ตรึงแล้ว ผู้เรียนจะเรียนเนื้อหาเดิมตามรอบที่ลงทะเบียน
            </p>
            <p class="notice">
                จำนวนบทเรียนที่เปิดใช้
                {{ learnerLessonCount }}
                บท · Admin หรือ Instructor เพิ่ม/นำบทออกและกำหนดลำดับได้เองในฉบับร่าง
                เอกสารประกอบแนบในบทนั้นหรือไม่แนบก็ได้
            </p>
            <p class="muted">
                PDF เดิมที่จับคู่ไว้ใต้คลิปนับเป็นเอกสารประกอบ ไม่เพิ่มจำนวนบทในหน้าผู้เรียน
                ส่วนบทอ่านแยกยังนับเป็นบทเรียน
            </p>
            <details>
                <summary>สร้างเวอร์ชันใหม่จากเวอร์ชันนี้</summary>
                <form
                    class="stack-form"
                    @submit.prevent="clone.post(`/versions/${version.id}/clone`)"
                >
                    <TextField
                        v-model="clone.version_name"
                        name="clone-version-name"
                        label="ชื่อเวอร์ชันใหม่"
                        required
                    /><button class="btn btn-secondary" :disabled="clone.processing">
                        สร้างเวอร์ชันใหม่
                    </button>
                </form>
            </details>
        </section>
        <section v-show="tab === 'exams'" data-editor-area="exams" class="surface section-pad">
            <h2>ชุดสอบของ {{ version.version_name }}</h2>
            <p class="muted">
                ตั้งค่าชุด Pretest / Posttest และตรวจคลังคำถามของหลักสูตร จำนวนครั้ง Posttest
                กำหนดในรอบเรียน
            </p>
            <div class="button-row">
                <Link class="btn btn-primary" :href="`/versions/${version.id}/questions`"
                    >นำเข้าข้อสอบ Excel และตั้งค่าชุดสอบ</Link
                >
            </div>
        </section>
        <section v-show="tab === 'lessons'" data-editor-area="lessons" class="surface section-pad">
            <h2>โครงสร้างบทเรียน · {{ learnerLessonCount }} บท</h2>
            <details v-if="videoLessons.length && documents.length">
                <summary>เอกสารประกอบที่แสดงใต้คลิป</summary>
                <LessonDocuments
                    v-for="lesson in videoLessons"
                    :key="`${version.id}-${lesson.id}`"
                    :editable="!version.published_at"
                    :lesson="lesson"
                    :documents="documents"
                />
            </details>
            <details v-if="!version.published_at">
                <summary>เพิ่มหมวดบทเรียน</summary>
                <form
                    class="stack-form"
                    @submit.prevent="
                        module.post(`/versions/${version.id}/modules`, {
                            onSuccess: () => module.reset(),
                        })
                    "
                >
                    <FormErrors :errors="module.errors" />
                    <div class="form-grid">
                        <TextField
                            v-model="module.title"
                            name="new-module-title"
                            label="ชื่อหมวดบทเรียน"
                            required
                        /><TextField
                            v-model="module.sort_order"
                            name="new-module-order"
                            label="ลำดับหมวด"
                            type="number"
                            required
                        />
                    </div>
                    <button class="btn btn-primary" :disabled="module.processing">
                        เพิ่มหมวดบทเรียน
                    </button>
                </form>
            </details>
        </section>
        <div v-show="tab === 'lessons'" data-editor-area="lessons" class="editor-columns">
            <aside class="surface editor-outline" aria-label="เลือกบทเรียนเพื่อแก้ไข">
                <section v-for="item in version.modules" :key="item.id">
                    <h3>{{ item.title }}</h3>
                    <button
                        :aria-pressed="selectedModule === item.id && selectedLesson === null"
                        @click="
                            selectedModule = item.id;
                            selectedLesson = null;
                        "
                    >
                        จัดการหมวด / เพิ่มบทเรียน</button
                    ><button
                        v-for="entry in item.lessons"
                        :key="entry.id"
                        :aria-pressed="selectedLesson === entry.id"
                        @click="
                            selectedModule = item.id;
                            selectedLesson = entry.id;
                        "
                    >
                        {{ entry.sort_order }}. {{ entry.title }}
                    </button>
                </section>
                <p v-if="!version.modules.length" class="muted">
                    เพิ่มหมวดบทเรียนเพื่อเริ่มสร้างบท
                </p>
            </aside>
            <div>
                <DraftModule
                    v-for="item in version.modules"
                    v-show="item.id === selectedModule"
                    :key="item.id"
                    :module="item"
                    :version-id="version.id"
                    :editable="!version.published_at"
                    :selected-lesson-id="selectedLesson"
                />
            </div>
        </div>
        <section
            v-show="tab === 'sessions'"
            data-editor-area="sessions"
            class="surface section-pad"
        >
            <h2>รอบเรียนและการมอบหมาย</h2>
            <div v-if="version.sessions.length" class="field">
                <label for="session-choice">เลือกรอบเรียน</label>
                <select id="session-choice" v-model="selectedSession">
                    <option v-for="item in version.sessions" :key="item.id" :value="item.id">
                        {{ item.name }}
                    </option>
                </select>
            </div>
            <p v-else class="muted">ยังไม่มีรอบเรียน เพิ่มรอบเพื่อกำหนดเกณฑ์และผู้เรียน</p>
            <SessionAssignment
                v-for="item in version.sessions"
                v-show="item.id === selectedSession"
                :key="item.id"
                :session="item"
                :departments="departments"
                :positions="positions"
                :users="users"
            />
            <details ref="newSessionPanel">
                <summary>เพิ่มรอบเรียน</summary>
                <form
                    class="stack-form"
                    @submit.prevent="
                        session.post(`/versions/${version.id}/sessions`, {
                            onSuccess: () => {
                                session.reset();
                                selectedSession = version.sessions[version.sessions.length - 1]?.id;
                                if (newSessionPanel) newSessionPanel.open = false;
                            },
                        })
                    "
                >
                    <FormErrors :errors="session.errors" />
                    <div class="form-grid">
                        <TextField
                            v-model="session.name"
                            name="session-name"
                            label="ชื่อรอบเรียน"
                            required
                        /><TextField
                            v-model="session.fiscal_year"
                            name="session-fiscal-year"
                            label="ปีงบประมาณ (พ.ศ.)"
                            type="number"
                            required
                        /><TextField
                            v-model="session.start_date"
                            name="session-start"
                            label="วันที่เริ่ม"
                            type="date"
                            required
                        /><TextField
                            v-model="session.end_date"
                            name="session-end"
                            label="วันที่สิ้นสุด"
                            type="date"
                            required
                        />
                        <div class="field">
                            <label for="session-mode">การลงทะเบียน</label
                            ><select id="session-mode" v-model="session.enrollment_mode">
                                <option value="optional">สมัครเอง</option>
                                <option value="required">มอบหมาย</option>
                                <option value="mandatory">บังคับและมอบหมาย</option>
                            </select>
                        </div>
                        <TextField
                            v-model="session.max_attempts"
                            name="session-attempts"
                            label="จำนวนครั้งสอบ Posttest รวมทั้งหมด"
                            type="number"
                            required
                        /><TextField
                            v-model="session.required_progress_percent"
                            name="session-progress"
                            label="บทที่จำเป็นต้องจบ (%)"
                            type="number"
                            required
                        /><TextField
                            v-model="session.required_video_watch_percent"
                            name="session-video"
                            label="วิดีโอที่ต้องดู (%)"
                            type="number"
                            required
                        /><TextField
                            v-model="session.passing_score"
                            name="session-passing"
                            label="คะแนนผ่าน Posttest (%)"
                            type="number"
                            required
                        />
                    </div>
                    <p class="notice">
                        ต้องส่ง Pretest ก่อนเริ่มเรียน และส่ง Posttest อย่างน้อย 1 ครั้งหลังเรียนครบ
                        จบได้โดยไม่บังคับคะแนนผ่าน
                    </p>
                    <p class="muted">
                        ค่าเริ่มต้นสอบ Posttest ได้รวม 1 ครั้ง ใช้คะแนนสูงสุดสรุปผล
                        รอบเรียนใหม่ใช้เวอร์ชันนี้และเกณฑ์ของตนเอง
                    </p>
                    <button class="btn btn-primary" :disabled="session.processing">
                        สร้างรอบเรียน
                    </button>
                </form>
            </details>
        </section>
        <UiDialog v-model:open="publishOpen" title="เผยแพร่เวอร์ชัน"
            ><p>{{ course.title }} · {{ version.version_name }}</p>
            <p>
                {{ learnerLessonCount }} บทเรียน · เผยแพร่แล้วจะตรึงเนื้อหา
                การแก้ครั้งต่อไปต้องสร้างเวอร์ชันใหม่
            </p>
            <p class="notice">ตรวจว่าบันทึกข้อมูลบทเรียนและเอกสารครบแล้วก่อนเผยแพร่</p>
            <FormErrors :errors="publication.errors" />
            <div class="button-row">
                <button class="btn btn-primary" :disabled="publication.processing" @click="publish">
                    ยืนยันเผยแพร่เวอร์ชัน</button
                ><button class="btn btn-secondary" @click="publishOpen = false">
                    กลับไปตรวจข้อมูล
                </button>
            </div></UiDialog
        >
    </AppLayout>
</template>
