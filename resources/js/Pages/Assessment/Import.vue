<script setup lang="ts">
import { useDateTime } from '../../date-time';
const dateTimeLabel = useDateTime();
defineOptions({ name: 'ObjectiveQuestionImport' });
import { Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import FormErrors from '../../Components/FormErrors.vue';
import Pagination from '../../Components/Pagination.vue';
import type { Paginated } from '../../Types/foundation';
import type { CourseRecord, VersionRecord } from '../../Types/learning';
import type { ExamSettingsRecord } from '../../Types/assessment';
import { choiceLabels } from '../../Types/assessment';
import ExamSettings from '../../Components/ExamSettings.vue';
import UiDialog from '../../Components/UiDialog.vue';
interface QuestionRow {
    code: string;
    usage: string;
    question_text: string;
    choices: Record<string, string>;
    correct_answer: string;
    max_score: number;
    category?: string;
    explanation: string | null;
}
const props = defineProps<{
    course: CourseRecord;
    version: VersionRecord;
    preview: { token: string; file_name: string; rows: QuestionRow[]; expires_at: string } | null;
    questions: Paginated<QuestionRow & { id: number }>;
    exams: ExamSettingsRecord[];
}>();
const form = useForm({ file: null as File | null });
const uploadKey = ref(0);
const clearing = useForm({ confirmation: 'CLEAR' });
const clearOpen = ref(false);
const clearLocked = computed(() => importLocked.value || !!props.version.published_at);
function clearQuestions() {
    clearing.delete(`/versions/${props.version.id}/questions`, {
        onSuccess: () => {
            clearOpen.value = false;
        },
    });
}
const now = ref(Date.now());
const clock = setInterval(() => {
    now.value = Date.now();
}, 1000);
onBeforeUnmount(() => clearInterval(clock));
const previewExpired = computed(
    () => !!props.preview && now.value >= Date.parse(props.preview.expires_at),
);
const importLocked = computed(() =>
    props.exams.some((exam) => exam.status !== 'draft' || exam.has_attempts),
);
const previewPage = ref(1);
const previewPageSize = 20;
const previewPages = computed(() => Math.ceil((props.preview?.rows.length ?? 0) / previewPageSize));
const previewRows = computed(
    () =>
        props.preview?.rows.slice(
            (previewPage.value - 1) * previewPageSize,
            previewPage.value * previewPageSize,
        ) ?? [],
);
watch(
    () => props.preview?.token,
    () => {
        previewPage.value = 1;
    },
);
const importing = useForm({ token: props.preview?.token ?? '' });
function commit() {
    if (!props.preview || importing.processing || previewExpired.value || importLocked.value)
        return;
    importing.token = props.preview.token;
    importing.post(`/versions/${props.version.id}/questions/commit`);
}
</script>
<template>
    <AppLayout
        title="นำเข้าข้อสอบปรนัย Excel"
        :subtitle="`${course.title} · ${version.version_name}`"
        ><template #actions
            ><Link class="btn btn-secondary" :href="`/manage/courses/${course.id}/edit`"
                >กลับหลักสูตร</Link
            ><a class="btn btn-secondary" :href="`/versions/${version.id}/questions/template`"
                >ดาวน์โหลดเทมเพลตข้อสอบ Excel</a
            ></template
        >
        <section class="surface section-pad">
            <h2>นำเข้า Pretest และ Posttest</h2>
            <div class="button-row">
                <a class="btn btn-secondary" :href="`/versions/${version.id}/questions/template`"
                    >ดาวน์โหลดเทมเพลตข้อสอบ</a
                ><button
                    class="btn btn-danger"
                    :disabled="clearLocked || clearing.processing || !exams.length"
                    @click="clearOpen = true"
                >
                    ล้างข้อสอบเดิมเพื่อนำเข้าใหม่
                </button>
            </div>
            <p v-if="clearLocked" class="helper">
                หากเวอร์ชันเผยแพร่หรือมีประวัติสอบแล้ว ให้สร้างเวอร์ชันใหม่ก่อนล้างและนำเข้าข้อสอบ
                เพื่อรักษาผลสอบย้อนหลัง
            </p>
            <p v-if="importLocked" class="notice warning">
                นำเข้าไม่ได้: มีชุดสอบที่เปิดสอบหรือมีประวัติสอบแล้ว ตรวจการตั้งค่าชุดสอบด้านล่าง
            </p>
            <p>
                ใช้ชีต Questions ตามแม่แบบ ระบุ PRETEST, POSTTEST หรือ BOTH ข้อที่ใช้ BOTH
                จะอยู่ในชุดสอบทั้งสองแบบ
            </p>
            <p>
                แต่ละข้อมี 4–6 ตัวเลือก ก ข ค ง จ ฉ (A–F) เลือกคำตอบถูกได้ 1 ตัวเลือก ระบุเฉลยเป็น
                A–F หรือ ก ข ค ง จ ฉ เลือกถูกได้คะแนนเต็ม เลือกผิดได้ 0 คะแนน
            </p>
            <p>
                แม่แบบใหม่มีคอลัมน์ E (จ) และ F (ฉ) เพิ่มเติม เว้นว่างได้สำหรับข้อสอบ 4 ตัวเลือก
                หากใช้ F ต้องมี E ด้วย ไฟล์แม่แบบเดิม 4 ตัวเลือกยังนำเข้าได้
            </p>
            <p class="notice">
                ตรวจข้อสอบและเปิดชุดสอบจากการตั้งค่าด้านล่าง จำนวนครั้ง Posttest
                ตั้งจากรอบเรียนในระบบ ไม่ใช่ Excel ผู้เรียนไม่เห็นเฉลย
            </p>
            <form
                class="stack-form"
                @submit.prevent="
                    form.post(`/versions/${version.id}/questions/preview`, {
                        forceFormData: true,
                        onSuccess: () => {
                            form.reset();
                            uploadKey++;
                        },
                    })
                "
            >
                <FormErrors :errors="form.errors" />
                <div class="field">
                    <label for="question-excel">ไฟล์ Excel .xlsx (ไม่เกิน 10 MB / 1,000 ข้อ)</label
                    ><input
                        id="question-excel"
                        :key="uploadKey"
                        type="file"
                        accept=".xlsx"
                        :disabled="importLocked || form.processing"
                        required
                        @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                </div>
                <p v-if="form.file" class="helper">
                    {{ form.file.name }} · {{ (form.file.size / 1024 / 1024).toFixed(2) }} MB
                </p>
                <p v-if="form.progress" role="status">
                    ส่งไฟล์ {{ form.progress.percentage }}% · รอผลตรวจจากระบบ
                </p>
                <button class="btn btn-primary" :disabled="form.processing || importLocked">
                    ตรวจไฟล์และแสดงตัวอย่าง
                </button>
            </form>
        </section>
        <section v-if="preview" class="surface section-pad">
            <p v-if="previewExpired" class="notice warning" role="status">
                ตัวอย่างหมดอายุแล้ว กรุณาตรวจไฟล์ใหม่ก่อนนำเข้า
            </p>
            <div class="section-heading">
                <h2>ตรวจรายการก่อนนำเข้า {{ preview.rows.length }} ข้อ</h2>
                <span>{{ preview.file_name }}</span>
            </div>
            <p class="muted">
                รายการหมดอายุ {{ dateTimeLabel(preview.expires_at) }} ตรวจรหัสข้อ คำถาม เฉลย
                และคะแนนให้ถูกต้องก่อนยืนยัน
            </p>
            <div class="table-wrap">
                <table class="question-table">
                    <thead>
                        <tr>
                            <th>รหัสข้อ</th>
                            <th>ใช้กับ</th>
                            <th>คำถาม</th>
                            <th>เฉลย</th>
                            <th>คะแนนเต็ม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in previewRows" :key="row.code">
                            <td class="identifier">{{ row.code }}</td>
                            <td>{{ row.usage }}</td>
                            <td>
                                {{ row.question_text }}
                                <ol class="choice-preview">
                                    <li v-for="(choice, key) in row.choices" :key="key">
                                        <strong>{{ choiceLabels[key] }} ({{ key }}).</strong>
                                        {{ choice }}
                                    </li>
                                </ol>
                            </td>
                            <td>
                                {{ choiceLabels[row.correct_answer] }} ({{ row.correct_answer }}) ·
                                {{ row.choices[row.correct_answer] }}
                            </td>
                            <td>{{ row.max_score }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="button-row" aria-label="หน้าตัวอย่างข้อสอบ">
                <button
                    class="btn btn-secondary"
                    :disabled="previewPage === 1"
                    @click="previewPage--"
                >
                    หน้าก่อนหน้า</button
                ><span
                    >หน้า {{ previewPage }} / {{ previewPages }} · แสดง {{ previewRows.length }} จาก
                    {{ preview.rows.length }} ข้อ</span
                ><button
                    class="btn btn-secondary"
                    :disabled="previewPage === previewPages"
                    @click="previewPage++"
                >
                    หน้าถัดไป
                </button>
            </div>
            <FormErrors :errors="importing.errors" />
            <button
                class="btn btn-primary"
                :disabled="importing.processing || previewExpired || importLocked"
                @click="commit"
            >
                ยืนยันนำเข้า {{ preview.rows.length }} ข้อ
            </button>
        </section>
        <section class="surface section-pad">
            <h2>ชุดสอบของเวอร์ชันนี้ · {{ version.version_name }}</h2>
            <ExamSettings
                v-for="exam in exams"
                :key="`${exam.id}-${exam.status}-${exam.has_attempts}`"
                :exam="exam"
            />
            <h2>คลังข้อสอบของหลักสูตร</h2>
            <p class="muted">รหัสข้อเป็นข้อความและห้ามซ้ำ การนำเข้าไม่เขียนทับข้อเดิม</p>
            <div v-if="!questions.data.length" class="empty-state">ยังไม่มีข้อสอบ</div>
            <div v-else class="table-wrap">
                <table class="question-table">
                    <thead>
                        <tr>
                            <th>รหัสข้อ</th>
                            <th>ใช้กับ</th>
                            <th>คำถาม</th>
                            <th>เฉลย</th>
                            <th>คะแนนเต็ม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in questions.data" :key="row.id">
                            <td class="identifier">{{ row.code }}</td>
                            <td>{{ row.usage }}</td>
                            <td>
                                {{ row.question_text }}
                                <ol class="choice-preview">
                                    <li v-for="(choice, key) in row.choices" :key="key">
                                        <strong>{{ choiceLabels[key] }} ({{ key }}).</strong>
                                        {{ choice }}
                                    </li>
                                </ol>
                            </td>
                            <td>
                                {{ choiceLabels[row.correct_answer] }} ({{ row.correct_answer }}) ·
                                {{ row.choices[row.correct_answer] }}
                            </td>
                            <td>{{ row.max_score }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="questions.links"
                :total="questions.total"
                :from="questions.from"
                :to="questions.to"
            />
        </section>
        <UiDialog v-model:open="clearOpen" title="ยืนยันล้างข้อสอบของเวอร์ชันร่าง"
            ><p>
                ล้าง Pretest และ Posttest ของ {{ version.version_name }} แล้วนำเข้าไฟล์ใหม่ได้
                โดยใช้รหัสข้อเดิมซ้ำได้ ประวัติสอบและชุดสอบของเวอร์ชันอื่นยังคงอยู่
            </p>
            <FormErrors :errors="clearing.errors" />
            <div class="button-row">
                <button
                    class="btn btn-secondary"
                    :disabled="clearing.processing"
                    @click="clearOpen = false"
                >
                    ยกเลิก</button
                ><button
                    class="btn btn-danger"
                    :disabled="clearing.processing || clearLocked"
                    @click="clearQuestions"
                >
                    ยืนยันล้างข้อสอบ
                </button>
            </div></UiDialog
        ></AppLayout
    >
</template>
