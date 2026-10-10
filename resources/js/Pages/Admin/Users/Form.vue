<script setup lang="ts">
defineOptions({ name: 'UserAccountForm' });
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import TextField from '../../../Components/TextField.vue';
import FormErrors from '../../../Components/FormErrors.vue';
import UiDialog from '../../../Components/UiDialog.vue';
import { useUnsavedForms } from '../../../unsaved-forms';
import type { NamedRecord, RoleRecord, SharedProps, UserRecord } from '../../../Types/foundation';
const props = defineProps<{
    record: UserRecord | null;
    departments: NamedRecord[];
    positions: NamedRecord[];
    roles: RoleRecord[];
}>();
const page = usePage<SharedProps>();
const canAssignRoles = computed(() => page.props.auth.user?.permissions.includes('roles.manage'));
const form = useForm({
    employee_code: props.record?.employee_code ?? '',
    username: props.record?.username ?? '',
    first_name: props.record?.first_name ?? '',
    last_name: props.record?.last_name ?? '',
    email: props.record?.email ?? '',
    phone: props.record?.phone ?? '',
    department_id: props.record?.department_id ?? null,
    position_id: props.record?.position_id ?? null,
    employment_status: props.record?.employment_status ?? '',
    is_active: props.record?.is_active ?? true,
    role_ids:
        props.record?.roles.map((role) => role.id) ??
        props.roles.filter((role) => role.code === 'learner').map((role) => role.id),
});
const reset = useForm({ reason: '' });
const resetOpen = ref(false);
useUnsavedForms(form);
const save = () => {
    if (
        props.record?.is_active &&
        !form.is_active &&
        !window.confirm('ยืนยันปิดบัญชีบุคลากรนี้? ประวัติเดิมจะยังเก็บไว้')
    )
        return;
    form.transform((data) => {
        if (canAssignRoles.value) return data;
        const copy = { ...data } as Partial<typeof data>;
        delete copy.role_ids;
        return copy;
    });
    if (props.record) form.put(`/admin/users/${props.record.id}`);
    else form.post('/admin/users');
};
const resetPassword = () => {
    if (!props.record) return;
    reset.post(`/admin/users/${props.record.id}/reset-password`, {
        onSuccess: () => {
            reset.reset();
            resetOpen.value = false;
        },
    });
};
</script>
<template>
    <AppLayout
        :title="record ? 'แก้ไขบัญชีบุคลากร' : 'เพิ่มบุคลากร'"
        subtitle="เลขประจำตัวเป็นตัวเลขไม่เกิน 15 หลัก"
        ><template #actions
            ><Link class="btn btn-secondary" href="/admin/users">กลับรายการ</Link></template
        >
        <section class="surface section-pad">
            <form @submit.prevent="save">
                <FormErrors :errors="form.errors" />
                <div class="form-grid">
                    <TextField
                        v-model="form.employee_code"
                        name="employee_code"
                        label="รหัสบุคลากร"
                        :maxlength="50"
                        :error="form.errors.employee_code"
                        required
                    /><TextField
                        v-model="form.username"
                        name="username"
                        label="เลขบัตรประชาชน / เลขประจำตัว"
                        inputmode="numeric"
                        :maxlength="15"
                        :error="form.errors.username"
                        required
                    /><TextField
                        v-model="form.first_name"
                        name="first_name"
                        label="ชื่อ"
                        :maxlength="100"
                        :error="form.errors.first_name"
                        required
                    /><TextField
                        v-model="form.last_name"
                        name="last_name"
                        label="นามสกุล"
                        :maxlength="100"
                        :error="form.errors.last_name"
                        required
                    />
                    <div class="field">
                        <label for="user-department">แผนก</label
                        ><select id="user-department" v-model="form.department_id">
                            <option :value="null">ยังไม่ได้กำหนด</option>
                            <option
                                v-for="department in departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.department_id" class="field-error" role="alert">
                            {{ form.errors.department_id }}
                        </p>
                    </div>
                    <div class="field">
                        <label for="user-position">ตำแหน่ง</label
                        ><select id="user-position" v-model="form.position_id">
                            <option :value="null">ยังไม่ได้กำหนด</option>
                            <option
                                v-for="position in positions"
                                :key="position.id"
                                :value="position.id"
                            >
                                {{ position.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.position_id" class="field-error" role="alert">
                            {{ form.errors.position_id }}
                        </p>
                    </div>
                    <TextField
                        v-model="form.email"
                        name="email"
                        label="อีเมล"
                        type="email"
                        :error="form.errors.email"
                    /><TextField
                        v-model="form.phone"
                        name="phone"
                        label="เบอร์โทรศัพท์"
                        inputmode="tel"
                        :maxlength="30"
                        :error="form.errors.phone"
                    /><TextField
                        v-model="form.employment_status"
                        name="employment_status"
                        label="สถานะการจ้างงาน"
                        :maxlength="100"
                        :error="form.errors.employment_status"
                    />
                </div>
                <fieldset v-if="canAssignRoles" class="choice-group">
                    <legend>บทบาทของบุคลากร</legend>
                    <label v-for="role in roles" :key="role.id" class="check-row"
                        ><input v-model="form.role_ids" type="checkbox" :value="role.id" />{{
                            role.name
                        }}</label
                    >
                    <p v-if="form.errors.role_ids" class="field-error" role="alert">
                        {{ form.errors.role_ids }}
                    </p>
                    <p class="helper">กำหนดหมวดที่ผู้คุมรับผิดชอบได้จากหน้าหมวดหลักสูตร</p>
                </fieldset>
                <label class="check-row"
                    ><input v-model="form.is_active" type="checkbox" />เปิดใช้งานบัญชีนี้</label
                >
                <p v-if="form.errors.is_active" class="field-error" role="alert">
                    {{ form.errors.is_active }}
                </p>
                <div class="form-actions">
                    <button class="btn btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'กำลังบันทึก…' : 'บันทึกบัญชี' }}</button
                    ><Link class="btn btn-quiet" href="/admin/users">ยกเลิก</Link>
                </div>
            </form>
        </section>
        <section v-if="record" class="surface section-pad reset-section">
            <h2>รีเซ็ตรหัสผ่าน</h2>
            <p class="muted">คืนรหัสผ่านเริ่มต้นและยกเลิกเซสชันเดิมของบุคลากร โดยต้องระบุเหตุผล</p>
            <button class="btn btn-danger-outline" @click="resetOpen = true">
                รีเซ็ตรหัสผ่านผู้ใช้นี้
            </button>
        </section>
        <UiDialog v-model:open="resetOpen" title="ยืนยันรีเซ็ตรหัสผ่าน">
            <p>{{ record?.display_name }}</p>
            <form class="form-stack" @submit.prevent="resetPassword">
                <TextField
                    v-model="reset.reason"
                    name="reason"
                    label="เหตุผลที่รีเซ็ต"
                    :error="reset.errors.reason"
                    :maxlength="500"
                    required
                />
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" @click="resetOpen = false">
                        ยกเลิก</button
                    ><button class="btn btn-danger" :disabled="reset.processing">
                        ยืนยันรีเซ็ต
                    </button>
                </div>
            </form>
        </UiDialog></AppLayout
    >
</template>
