<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import TextField from './TextField.vue';
import FormErrors from './FormErrors.vue';
import DraftLesson from './DraftLesson.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { ModuleRecord } from '../Types/learning';
const props = defineProps<{
    module: ModuleRecord;
    versionId: number;
    editable: boolean;
    selectedLessonId?: number | null;
}>();
const metadata = useForm({
    module_id: props.module.id,
    title: props.module.title,
    sort_order: String(props.module.sort_order),
});
const lesson = useForm({
    title: '',
    description: '',
    sort_order: String(props.module.lessons.length + 1),
    is_required: true,
    status: 'active',
});
useUnsavedForms(metadata, lesson);
</script>
<template>
    <section class="surface section-pad" :data-module-id="module.id">
        <div class="section-heading">
            <h2>{{ module.title }}</h2>
            <span class="muted">{{ module.lessons.length }} บทเรียน</span>
        </div>
        <details v-if="editable">
            <summary>แก้หมวดบทเรียน</summary>
            <form
                class="stack-form"
                @submit.prevent="metadata.post(`/versions/${versionId}/modules`)"
            >
                <FormErrors :errors="metadata.errors" />
                <div class="form-grid">
                    <TextField
                        v-model="metadata.title"
                        :name="`module-title-${module.id}`"
                        label="ชื่อหมวดบทเรียน"
                        required
                    /><TextField
                        v-model="metadata.sort_order"
                        :name="`module-order-${module.id}`"
                        label="ลำดับหมวด"
                        type="number"
                        required
                    />
                </div>
                <button class="btn btn-secondary" :disabled="metadata.processing">
                    บันทึกหมวดบทเรียน
                </button>
            </form>
        </details>
        <DraftLesson
            v-for="item in module.lessons"
            v-show="selectedLessonId === undefined || selectedLessonId === item.id"
            :key="item.id"
            :lesson="item"
            :editable="editable"
            :expanded="selectedLessonId === item.id"
        />
        <details v-if="editable">
            <summary>เพิ่มบทเรียน</summary>
            <form
                class="stack-form"
                @submit.prevent="
                    lesson.post(`/modules/${module.id}/lessons`, {
                        onSuccess: () => {
                            lesson.reset();
                            lesson.sort_order = String(module.lessons.length + 1);
                            lesson.defaults('sort_order', lesson.sort_order);
                        },
                    })
                "
            >
                <FormErrors :errors="lesson.errors" />
                <div class="form-grid">
                    <TextField
                        v-model="lesson.title"
                        :name="`new-lesson-title-${module.id}`"
                        label="ชื่อบทเรียน"
                        required
                    /><TextField
                        v-model="lesson.sort_order"
                        :name="`new-lesson-order-${module.id}`"
                        label="ลำดับบท"
                        type="number"
                        required
                    />
                </div>
                <label class="checkbox-label"
                    ><input v-model="lesson.is_required" type="checkbox" />บทเรียนที่จำเป็น</label
                ><button class="btn btn-primary" :disabled="lesson.processing">เพิ่มบทเรียน</button>
            </form>
        </details>
    </section>
</template>
