<script setup lang="ts">
defineOptions({ name: 'LearnerExam' });
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import FormErrors from '../../Components/FormErrors.vue';
import UiDialog from '../../Components/UiDialog.vue';
import { learningPost } from '../../learning-http';
import { useUnsavedForms } from '../../unsaved-forms';
import type { AssessmentSummary, AttemptRecord } from '../../Types/assessment';
import type { EnrollmentRecord } from '../../Types/learning';
interface ExamQuestion {
    question_id: number;
    question_text: string;
    max_score: number;
    choices: { key: string; text: string }[];
    selected_answer: string | null;
}
const props = defineProps<{
    attempt: AttemptRecord;
    examType: string;
    questions: ExamQuestion[];
    serverNow: string;
    enrollment: EnrollmentRecord;
    summary: AssessmentSummary;
}>();
const page = usePage();
const answers = ref<Record<number, string | null>>(
    Object.fromEntries(props.questions.map((q) => [q.question_id, q.selected_answer])),
);
const revision = ref(props.attempt.revision);
const pendingSaves = ref(0);
const saving = computed(() => pendingSaves.value > 0);
const currentQuestion = ref(0);
const confirmOpen = ref(false);
const failedQuestions = ref<Record<number, string>>({});
const unsavedQuestions = new Set<number>();
useUnsavedForms({
    get isDirty() {
        return unsavedQuestions.size > 0 || pendingSaves.value > 0;
    },
    get processing() {
        return submitting.value || expired;
    },
});
const submitting = ref(false);
const saveError = computed(() => Object.values(failedQuestions.value)[0] ?? '');
const savedAt = ref('');
let queue = Promise.resolve();
const active = computed(() => props.attempt.status === 'IN_PROGRESS');
const answered = computed(() => Object.values(answers.value).filter(Boolean).length);
const remaining = ref<number | null>(null);
const offset = Date.parse(props.serverNow) - Date.now();
let expired = false;
const timer = setInterval(() => {
    if (!active.value || !props.attempt.expires_at) return;
    remaining.value = Math.max(
        0,
        Math.ceil((Date.parse(props.attempt.expires_at) - Date.now() - offset) / 1000),
    );
    if (remaining.value === 0 && !expired) {
        expired = true;
        router.reload();
    }
}, 1000);
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (pendingSaves.value || unsavedQuestions.size) event.preventDefault();
};
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => {
    clearInterval(timer);
    window.removeEventListener('beforeunload', beforeUnload);
});
async function navigateQuestion(index: number) {
    currentQuestion.value = index;
    await nextTick();
    document.getElementById(`question-${props.questions[index]?.question_id}`)?.focus();
}
const payload = () =>
    props.questions.map((q) => ({
        question_id: q.question_id,
        selected_answer: answers.value[q.question_id] ?? null,
    }));
function changed(questionId: number) {
    const snapshot = [
        { question_id: questionId, selected_answer: answers.value[questionId] ?? null },
    ];
    unsavedQuestions.add(questionId);
    pendingSaves.value++;
    queue = queue.then(async () => {
        try {
            const result = await learningPost<{ attempt: AttemptRecord }>(
                `/attempts/${props.attempt.id}/answers`,
                { revision: revision.value, answers: snapshot },
            );
            revision.value = result.attempt.revision;
            delete failedQuestions.value[questionId];
            if (answers.value[questionId] === snapshot[0]?.selected_answer)
                unsavedQuestions.delete(questionId);
            savedAt.value = new Date().toLocaleTimeString('th-TH');
            if (result.attempt.status !== 'IN_PROGRESS') {
                expired = true;
                router.reload();
            }
        } catch (error) {
            failedQuestions.value[questionId] =
                error instanceof Error ? error.message : 'บันทึกคำตอบไม่สำเร็จ';
        } finally {
            pendingSaves.value--;
        }
    });
}
async function retrySave() {
    const ids = [...unsavedQuestions];
    ids.forEach(changed);
    await queue;
}
function reloadAnswers() {
    if (
        window.confirm(
            'คำตอบที่ยังบันทึกไม่สำเร็จจะถูกแทนด้วยคำตอบล่าสุดบนระบบ ต้องการโหลดใหม่หรือไม่?',
        )
    )
        router.reload();
}
async function submit() {
    if (submitting.value) return;
    submitting.value = true;
    confirmOpen.value = false;
    await queue;
    if (saveError.value) {
        submitting.value = false;
        return;
    }
    router.post(
        `/attempts/${props.attempt.id}/submit`,
        { revision: revision.value, answers: payload() },
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>
<template>
    <AppLayout
        mode="exam"
        :title="examType === 'PRETEST' ? 'Pretest ก่อนเรียน' : 'Posttest หลังเรียน'"
        :subtitle="enrollment.session.version?.course?.title ?? 'ข้อสอบ'"
    >
        <template #actions
            ><Link class="btn btn-secondary" :href="`/learning/${enrollment.id}`"
                >กลับบทเรียน</Link
            ></template
        >
        <FormErrors :errors="page.props.errors as Record<string, string>" />
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>ครั้งที่ {{ attempt.attempt_number }} · {{ questions.length }} ข้อ</h2>
                <strong v-if="active && remaining !== null"
                    >เหลือ {{ Math.floor(remaining / 60) }}:{{
                        String(remaining % 60).padStart(2, '0')
                    }}
                    นาที</strong
                >
            </div>
            <p v-if="active" class="notice">
                เลือกคำตอบที่ถูกหนึ่งตัวเลือกต่อข้อให้ครบก่อนส่ง ระบบบันทึกคำตอบอัตโนมัติ
                กลับมาทำต่อได้ในครั้งเดิม
            </p>
            <p v-if="active && examType === 'PRETEST'">
                ต้องส่ง Pretest ก่อนเปิดบทเรียน ไม่บังคับคะแนนขั้นต่ำ
            </p>
            <p v-if="active && examType === 'POSTTEST'">
                ทำได้รวม {{ summary.maxPosttestAttempts }} ครั้ง ใช้คะแนนสูงสุดสรุปผล
                เรียนจบเมื่อเรียนครบและส่ง Posttest อย่างน้อย 1 ครั้ง
            </p>
            <p v-if="saveError" class="notice error" role="alert">
                {{ saveError }}
                <button class="btn btn-primary" :disabled="saving" @click="retrySave">
                    ลองบันทึกคำตอบอีกครั้ง
                </button>
                <button class="btn btn-secondary" @click="reloadAnswers">
                    โหลดคำตอบจากระบบใหม่
                </button>
            </p>
            <div v-if="!active" class="exam-result">
                <h2>บันทึกผลสอบแล้ว{{ attempt.status === 'EXPIRED' ? ' (หมดเวลา)' : '' }}</h2>
                <p class="result-score">{{ attempt.percentage }}%</p>
                <p>
                    {{ attempt.score }} / {{ attempt.max_score }} คะแนน ·
                    {{ attempt.passed ? 'ผ่านเกณฑ์คะแนน' : 'ต่ำกว่าเกณฑ์คะแนน' }}
                </p>
                <p v-if="examType === 'PRETEST'">
                    ทำ Pretest ครบแล้ว สามารถเริ่มเรียนได้โดยไม่จำกัดคะแนน
                </p>
                <p v-else-if="summary.bestPosttest">
                    คะแนน Posttest สูงสุด {{ summary.bestPosttest.percentage }}% · ใช้แล้ว
                    {{ summary.usedPosttestAttempts }} / {{ summary.maxPosttestAttempts }} ครั้ง
                </p>
                <Link class="btn btn-primary" :href="`/learning/${enrollment.id}`"
                    >กลับไปเรียนและดูผลสรุป</Link
                >
            </div>
        </section>
        <form v-if="active" class="exam-form" @submit.prevent="confirmOpen = true">
            <div class="exam-status" role="status">
                <span>ตอบแล้ว {{ answered }} / {{ questions.length }} ข้อ</span
                ><span>{{
                    saveError
                        ? 'มีคำตอบที่ยังบันทึกไม่สำเร็จ'
                        : saving
                          ? 'กำลังบันทึกคำตอบ'
                          : savedAt
                            ? `บันทึกครบแล้ว · ${savedAt}`
                            : 'ยังไม่ได้เปลี่ยนคำตอบ'
                }}</span>
            </div>
            <div class="exam-workspace">
                <div>
                    <fieldset
                        v-for="(question, index) in questions"
                        v-show="currentQuestion === index"
                        :key="question.question_id"
                        class="surface section-pad exam-question"
                        :disabled="submitting"
                    >
                        <legend :id="`question-${question.question_id}`" tabindex="-1">
                            ข้อ {{ index + 1 }} / {{ questions.length }} ·
                            {{ question.max_score }} คะแนน
                        </legend>
                        <p class="question-text">{{ question.question_text }}</p>
                        <label
                            v-for="(choice, choiceIndex) in question.choices"
                            :key="choice.key"
                            class="exam-choice"
                            :class="{ chosen: answers[question.question_id] === choice.key }"
                        >
                            <input
                                v-model="answers[question.question_id]"
                                type="radio"
                                :name="`q-${question.question_id}`"
                                :value="choice.key"
                                @change="changed(question.question_id)"
                            />
                            <span
                                ><strong>{{ 'กขคงจฉ'[choiceIndex] }}.</strong>
                                {{ choice.text }}</span
                            >
                        </label>
                    </fieldset>
                    <div class="question-navigation">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="currentQuestion === 0 || submitting"
                            @click="navigateQuestion(currentQuestion - 1)"
                        >
                            ข้อก่อนหน้า</button
                        ><button
                            type="button"
                            class="btn btn-primary"
                            :disabled="currentQuestion === questions.length - 1 || submitting"
                            @click="navigateQuestion(currentQuestion + 1)"
                        >
                            ข้อถัดไป
                        </button>
                    </div>
                </div>
                <aside class="surface question-palette" aria-label="เลือกข้อสอบ">
                    <h2>คำถามทั้งหมด</h2>
                    <p class="helper">พื้นสีอ่อน = ตอบแล้ว</p>
                    <div class="question-numbers">
                        <button
                            v-for="(question, index) in questions"
                            :key="question.question_id"
                            type="button"
                            :aria-label="`ไปข้อ ${index + 1}${answers[question.question_id] ? ' ตอบแล้ว' : ' ยังไม่ได้ตอบ'}`"
                            :aria-current="currentQuestion === index ? 'step' : undefined"
                            :class="{ answered: !!answers[question.question_id] }"
                            :disabled="submitting"
                            @click="navigateQuestion(index)"
                        >
                            {{ index + 1 }}
                        </button>
                    </div>
                </aside>
            </div>
            <section class="surface section-pad exam-submit">
                <p role="status">
                    ตอบแล้ว {{ answered }} / {{ questions.length }} ข้อ ·
                    {{
                        saveError
                            ? 'มีคำตอบที่ยังบันทึกไม่สำเร็จ'
                            : saving
                              ? 'กำลังบันทึก'
                              : savedAt
                                ? `บันทึกล่าสุด ${savedAt}`
                                : 'ยังไม่ได้เปลี่ยนคำตอบ'
                    }}
                </p>
                <button
                    class="btn btn-primary"
                    :disabled="submitting || saving || answered !== questions.length || !!saveError"
                >
                    {{ submitting ? 'กำลังส่งข้อสอบ' : 'ส่งข้อสอบและดูผล' }}
                </button>
            </section>
        </form>
        <UiDialog v-model:open="confirmOpen" title="ยืนยันส่งข้อสอบ"
            ><p>
                ตอบแล้ว {{ answered }} / {{ questions.length }} ข้อ
                เมื่อส่งแล้วจะแก้คำตอบในครั้งนี้ไม่ได้
            </p>
            <div class="button-row">
                <button
                    class="btn btn-primary"
                    :disabled="submitting || saving || !!saveError"
                    @click="submit"
                >
                    ยืนยันส่งข้อสอบ</button
                ><button class="btn btn-secondary" @click="confirmOpen = false">
                    กลับไปตรวจคำตอบ
                </button>
            </div></UiDialog
        >
    </AppLayout>
</template>
