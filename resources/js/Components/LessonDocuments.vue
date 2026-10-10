<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrors from './FormErrors.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { ContentRecord, LessonRecord } from '../Types/learning';
const props = defineProps<{
    lesson: LessonRecord;
    documents: ContentRecord[];
    editable: boolean;
}>();
const form = useForm({ content_ids: (props.lesson.documents ?? []).map((doc) => doc.id) });
useUnsavedForms(form);
</script>
<template>
    <details class="session-editor">
        <summary>จับคู่ PDF ใต้ {{ lesson.title }}</summary>
        <form class="stack-form" @submit.prevent="form.put(`/lessons/${lesson.id}/documents`)">
            <FormErrors :errors="form.errors" />
            <p class="muted">
                เลือกเอกสารในเวอร์ชันนี้ที่ให้แสดงใต้คลิปโดยอัตโนมัติ ใช้ความก้าวหน้าเอกสารเดิม
                ไม่เพิ่มสำเนาหรือรีเซ็ตประวัติการอ่าน
                การแก้เอกสารของเวอร์ชันที่เผยแพร่ต้องสร้างเวอร์ชันใหม่
            </p>
            <label v-for="doc in documents" :key="doc.id" class="check-label"
                ><input
                    v-model="form.content_ids"
                    type="checkbox"
                    :value="doc.id"
                    :disabled="!editable"
                />{{ doc.title }}</label
            >
            <button class="btn btn-secondary" :disabled="form.processing || !editable">
                บันทึกการจับคู่เอกสาร
            </button>
        </form>
    </details>
</template>
