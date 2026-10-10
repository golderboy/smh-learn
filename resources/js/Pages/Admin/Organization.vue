<script setup lang="ts">
defineOptions({ name: 'OrganizationManagementPage' });
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import Pagination from '../../Components/Pagination.vue';
import UiDialog from '../../Components/UiDialog.vue';
import { confirmLeaveForms, useUnsavedForms } from '../../unsaved-forms';
import type { NamedRecord, Paginated } from '../../Types/foundation';
type OrganizationRecord = NamedRecord & { code: string; is_active: boolean };
const props = defineProps<{
    kind: 'departments' | 'positions';
    records: Paginated<OrganizationRecord>;
}>();
const label = computed(() => (props.kind === 'departments' ? 'แผนก' : 'ตำแหน่ง'));
const editing = ref<OrganizationRecord | null>(null);
const editOpen = ref(false);
const form = useForm({ code: '', name: '', is_active: true });
useUnsavedForms(form);
const startEdit = (record: OrganizationRecord) => {
    if (!confirmLeaveForms()) return;
    editing.value = record;
    form.code = record.code;
    form.name = record.name;
    form.is_active = record.is_active;
    form.clearErrors();
    form.defaults();
    editOpen.value = true;
};
const clear = () => {
    editOpen.value = false;
    editing.value = null;
    form.defaults({ code: '', name: '', is_active: true });
    form.reset();
    form.clearErrors();
};
const startNew = () => {
    if (!confirmLeaveForms()) return;
    clear();
    editOpen.value = true;
};
const cancelEdit = () => {
    if (confirmLeaveForms()) clear();
};
const save = () => {
    if (
        editing.value?.is_active &&
        !form.is_active &&
        !window.confirm(`ยืนยันปิดใช้งาน${label.value}นี้?`)
    )
        return;
    const options = { onSuccess: clear };
    if (editing.value) form.put(`/admin/organization/${props.kind}/${editing.value.id}`, options);
    else form.post(`/admin/organization/${props.kind}`, options);
};
</script>
<template>
    <AppLayout :title="label" :subtitle="`จัดการข้อมูล${label}ขององค์กร`"
        ><template #actions
            ><button class="btn btn-primary" @click="startNew">เพิ่ม{{ label }}</button></template
        >
        <div>
            <section class="surface">
                <div class="section-pad section-heading">
                    <h2>รายการ{{ label }}</h2>
                    <span class="muted">{{ records.total }} รายการ</span>
                </div>
                <div class="table-wrap">
                    <table v-responsive-table>
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ{{ label }}</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in records.data" :key="record.id">
                                <td class="identifier">{{ record.code }}</td>
                                <td>{{ record.name }}</td>
                                <td>
                                    <span
                                        class="status-badge"
                                        :class="record.is_active ? 'is-active' : 'is-inactive'"
                                        >{{ record.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span
                                    >
                                </td>
                                <td>
                                    <button
                                        class="btn btn-quiet"
                                        :aria-label="`แก้ไข ${record.name}`"
                                        @click="startEdit(record)"
                                    >
                                        แก้ไข
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!records.data.length">
                                <td colspan="4" class="empty-state">ยังไม่มี{{ label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    :links="records.links"
                    :total="records.total"
                    :from="records.from"
                    :to="records.to"
                />
            </section>
            <UiDialog
                v-model:open="editOpen"
                :title="editing ? `แก้ไข${label}` : `เพิ่ม${label}`"
                drawer
            >
                <form class="form-stack" @submit.prevent="save">
                    <TextField
                        v-model="form.code"
                        name="code"
                        :label="`รหัส${label}`"
                        :maxlength="50"
                        :error="form.errors.code"
                        required
                    /><TextField
                        v-model="form.name"
                        name="name"
                        :label="`ชื่อ${label}`"
                        :maxlength="150"
                        :error="form.errors.name"
                        required
                    /><label class="check-row"
                        ><input v-model="form.is_active" type="checkbox" />เปิดใช้งาน</label
                    >
                    <p v-if="form.errors.is_active" class="field-error" role="alert">
                        {{ form.errors.is_active }}
                    </p>
                    <div class="form-actions">
                        <button class="btn btn-primary" :disabled="form.processing">
                            บันทึก{{ label }}</button
                        ><button
                            v-if="editing"
                            class="btn btn-secondary"
                            type="button"
                            @click="cancelEdit"
                        >
                            ยกเลิก
                        </button>
                    </div>
                </form>
            </UiDialog>
        </div></AppLayout
    >
</template>
