<script setup lang="ts">
defineOptions({ name: 'RoleManagementPage' });
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import { confirmLeaveForms, useUnsavedForms } from '../../unsaved-forms';
import type { PermissionRecord, RoleRecord } from '../../Types/foundation';
const props = defineProps<{ roles: RoleRecord[]; permissions: PermissionRecord[] }>();
const permissionGroups = computed(() =>
    props.permissions.reduce<Record<string, PermissionRecord[]>>((groups, permission) => {
        (groups[permission.module ?? 'อื่น ๆ'] ??= []).push(permission);
        return groups;
    }, {}),
);
const selected = ref<RoleRecord | null>(null);
const form = useForm({ code: '', name: '', permission_ids: [] as number[] });
useUnsavedForms(form);
const edit = (role: RoleRecord) => {
    if (!confirmLeaveForms()) return;
    selected.value = role;
    form.code = role.code;
    form.name = role.name;
    form.permission_ids = role.permissions?.map((permission) => permission.id) ?? [];
    form.clearErrors();
    form.defaults();
};
const clear = () => {
    selected.value = null;
    form.defaults({ code: '', name: '', permission_ids: [] });
    form.reset();
    form.clearErrors();
};
const save = () => {
    if (
        selected.value &&
        !window.confirm('ยืนยันเปลี่ยนสิทธิ์ของบทบาทนี้? ผู้ใช้ที่เกี่ยวข้องต้องเข้าสู่ระบบใหม่')
    )
        return;
    const options = { onSuccess: clear };
    if (selected.value) form.put(`/admin/roles/${selected.value.id}`, options);
    else form.post('/admin/roles', options);
};
</script>
<template>
    <AppLayout title="บทบาทและสิทธิ์" subtitle="กำหนดความสามารถของผู้ใช้งานแต่ละบทบาท"
        ><div class="editor-columns role-columns">
            <section class="surface section-pad">
                <h2>บทบาทในระบบ</h2>
                <div class="role-list">
                    <div v-for="role in roles" :key="role.id">
                        <div>
                            <strong>{{ role.name }}</strong
                            ><small class="muted">{{ role.permissions?.length ?? 0 }} สิทธิ์</small>
                        </div>
                        <button
                            class="btn btn-quiet"
                            :aria-label="`ดูสิทธิ์ ${role.name}`"
                            @click="edit(role)"
                        >
                            {{ role.code === 'administrator' ? 'ดูสิทธิ์' : 'แก้ไข' }}
                        </button>
                    </div>
                </div>
            </section>
            <section class="surface section-pad">
                <h2>{{ selected ? selected.name : 'เพิ่มบทบาท' }}</h2>
                <div v-if="selected?.code === 'administrator'" class="notice">
                    บทบาทผู้ดูแลระบบสงวนสิทธิ์ครบทุกส่วนเพื่อให้จัดการระบบต่อได้
                </div>
                <form class="form-stack" @submit.prevent="save">
                    <FormErrors :errors="form.errors" />
                    <TextField
                        v-model="form.code"
                        name="code"
                        label="รหัสบทบาท (ภาษาอังกฤษ)"
                        :error="form.errors.code"
                        :readonly="!!selected"
                        required
                    /><TextField
                        v-model="form.name"
                        name="name"
                        label="ชื่อบทบาท"
                        :error="form.errors.name"
                        :readonly="selected?.code === 'administrator'"
                        required
                    />
                    <fieldset class="choice-group">
                        <legend>สิทธิ์ของบทบาท</legend>
                        <section
                            v-for="(items, group) in permissionGroups"
                            :key="group"
                            class="permission-group"
                        >
                            <h3>{{ group }}</h3>
                            <label
                                v-for="permission in items"
                                :key="permission.id"
                                class="check-row"
                                ><input
                                    v-model="form.permission_ids"
                                    type="checkbox"
                                    :value="permission.id"
                                    :disabled="selected?.code === 'administrator'"
                                />{{ permission.name }}</label
                            >
                        </section>
                        <p v-if="form.errors.permission_ids" class="field-error" role="alert">
                            {{ form.errors.permission_ids }}
                        </p>
                    </fieldset>
                    <div class="form-actions">
                        <button
                            v-if="selected?.code !== 'administrator'"
                            class="btn btn-primary"
                            :disabled="form.processing"
                        >
                            บันทึกบทบาท</button
                        ><button
                            v-if="selected"
                            class="btn btn-secondary"
                            type="button"
                            @click="confirmLeaveForms() && clear()"
                        >
                            เพิ่มบทบาทใหม่
                        </button>
                    </div>
                </form>
            </section>
        </div></AppLayout
    >
</template>
