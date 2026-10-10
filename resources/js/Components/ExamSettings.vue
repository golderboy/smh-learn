<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrors from './FormErrors.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { ExamSettingsRecord } from '../Types/assessment';
const props = defineProps<{ exam: ExamSettingsRecord }>();
const form = useForm({
    status: props.exam.status,
    question_count: props.exam.question_count ?? '',
    time_limit_minutes: props.exam.time_limit_minutes ?? '',
    randomize_questions: props.exam.randomize_questions,
    randomize_choices: props.exam.randomize_choices,
});
useUnsavedForms(form);
</script>
<template>
    <form
        class="session-editor stack-form"
        @submit.prevent="form.put(`/exams/${exam.id}/settings`)"
    >
        <h3>
            {{ exam.type }}: {{ exam.count }} ข้อ ·
            {{ exam.status === 'published' ? 'เปิดสอบแล้ว' : 'ฉบับร่าง' }}
        </h3>
        <p v-if="exam.has_attempts" class="notice">
            มีประวัติสอบแล้ว กติกาชุดสอบนี้ถูกตรึงไว้ จำนวนครั้ง Posttest ปรับได้จากรอบเรียน
        </p>
        <FormErrors :errors="form.errors" />
        <fieldset :disabled="exam.has_attempts || form.processing" class="exam-settings-fields">
            <div class="form-grid">
                <div class="field">
                    <label :for="`exam-count-${exam.id}`"
                        >จำนวนข้อที่ใช้สอบ (เว้นว่าง = ทั้งหมด)</label
                    ><input
                        :id="`exam-count-${exam.id}`"
                        v-model="form.question_count"
                        type="number"
                        min="1"
                        :max="exam.count"
                    />
                </div>
                <div class="field">
                    <label :for="`exam-time-${exam.id}`">เวลาสอบ (นาที; เว้นว่าง = ไม่จำกัด)</label
                    ><input
                        :id="`exam-time-${exam.id}`"
                        v-model="form.time_limit_minutes"
                        type="number"
                        min="1"
                        max="600"
                    />
                </div>
            </div>
            <label class="check-label"
                ><input v-model="form.randomize_questions" type="checkbox" />
                สุ่มลำดับข้อและเลือกจำนวนข้อจากชุด</label
            >
            <label class="check-label"
                ><input v-model="form.randomize_choices" type="checkbox" /> สุ่มลำดับตัวเลือก</label
            >
            <div class="field">
                <label :for="`exam-status-${exam.id}`">สถานะชุดสอบ</label
                ><select :id="`exam-status-${exam.id}`" v-model="form.status">
                    <option value="draft">ฉบับร่าง</option>
                    <option value="published">เปิดให้สอบ</option>
                </select>
            </div>
            <button class="btn btn-primary">บันทึกการตั้งค่าชุดสอบ</button>
        </fieldset>
    </form>
</template>
