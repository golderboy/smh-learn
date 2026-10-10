<script setup lang="ts">
defineOptions({ name: 'CourseCategoryForm' });
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import PeopleCheckboxPicker from '../../Components/PeopleCheckboxPicker.vue';
import { useUnsavedForms } from '../../unsaved-forms';
import type { CategoryRecord } from '../../Types/foundation';
const props = defineProps<{
    record: CategoryRecord | null;
    users: { id: number; display_name: string; is_active: boolean }[];
}>();
const form = useForm({
    code: props.record?.code ?? '',
    name: props.record?.name ?? '',
    description: props.record?.description ?? '',
    is_active: props.record?.is_active ?? true,
    instructor_ids: props.record?.instructors.map((user) => user.id) ?? [],
});
const save = () => {
    if (props.record?.is_active && !form.is_active && !window.confirm('ยืนยันปิดใช้งานหมวดนี้?'))
        return;
    if (props.record) form.put(`/categories/${props.record.id}`);
    else form.post('/categories');
};
useUnsavedForms(form);
</script>
<template>
    <AppLayout
        :title="record ? 'แก้ไขหมวดหลักสูตร' : 'เพิ่มหมวดหลักสูตร'"
        subtitle="Admin กำหนดได้ว่าบุคลากรคนใดควบคุมหลักสูตรในหมวดนี้"
        ><template #actions
            ><Link class="btn btn-secondary" href="/categories">กลับรายการ</Link></template
        >
        <section class="surface section-pad">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <TextField
                        v-model="form.code"
                        name="code"
                        label="รหัสหมวด"
                        :maxlength="50"
                        :error="form.errors.code"
                        required
                    /><TextField
                        v-model="form.name"
                        name="name"
                        label="ชื่อหมวดหลักสูตร"
                        :maxlength="150"
                        :error="form.errors.name"
                        required
                    />
                </div>
                <div class="field">
                    <label for="category-description">คำอธิบาย</label
                    ><textarea
                        id="category-description"
                        v-model="form.description"
                        rows="3"
                        maxlength="5000"
                    ></textarea>
                    <p v-if="form.errors.description" class="field-error" role="alert">
                        {{ form.errors.description }}
                    </p>
                </div>
                <fieldset class="choice-group">
                    <legend>ผู้คุมกลุ่มการศึกษาของหมวดนี้</legend>
                    <p class="helper">
                        ผู้ใช้ที่เลือกจะได้รับบทบาทผู้คุมและสิทธิ์ควบคุมหลักสูตรเฉพาะหมวดที่มอบหมาย
                        เลือกได้มากกว่าหนึ่งคน
                    </p>
                    <PeopleCheckboxPicker v-model="form.instructor_ids" :people="users" />
                    <p
                        v-for="(error, key) in Object.fromEntries(
                            Object.entries(form.errors).filter(([key]) =>
                                key.startsWith('instructor_ids'),
                            ),
                        )"
                        :key="key"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </fieldset>
                <label class="check-row"
                    ><input v-model="form.is_active" type="checkbox" />เปิดใช้งานหมวดนี้</label
                >
                <p v-if="form.errors.is_active" class="field-error" role="alert">
                    {{ form.errors.is_active }}
                </p>
                <div class="form-actions">
                    <button class="btn btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'กำลังบันทึก…' : 'บันทึกหมวดและผู้คุม' }}</button
                    ><Link class="btn btn-quiet" href="/categories">ยกเลิก</Link>
                </div>
            </form>
        </section></AppLayout
    >
</template>
