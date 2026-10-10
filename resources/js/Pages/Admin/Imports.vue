<script setup lang="ts">
defineOptions({ name: 'MasterDataImports' });
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import FormErrors from '../../Components/FormErrors.vue';
import AppIcon from '../../Components/AppIcon.vue';
import { useDateTime } from '../../date-time';
const dateTime = useDateTime();
interface Definition {
    name: string;
    headers: string[];
    fields: string[];
}
interface ImportRow {
    line: number;
    data: Record<string, string | null>;
    skip: boolean;
}
const props = defineProps<{
    kind: string;
    definitions: Record<string, Definition>;
    preview: { token: string; file_name: string; rows: ImportRow[]; expires_at: string } | null;
    history: { kind: string; file_name: string; committed_at: string }[];
}>();
const selected = computed(() => props.definitions[props.kind]!);
const form = useForm({ file: null as File | null });
const uploadKey = ref(0);
const confirmation = useForm({ token: '' });
const current = ref(1);
const now = ref(Date.now());
const timer = setInterval(() => {
    now.value = Date.now();
}, 1000);
onBeforeUnmount(() => clearInterval(timer));
const expired = computed(
    () => !!props.preview && now.value >= Date.parse(props.preview.expires_at),
);
const rows = computed(
    () => props.preview?.rows.slice((current.value - 1) * 20, current.value * 20) ?? [],
);
const pages = computed(() => Math.ceil((props.preview?.rows.length ?? 0) / 20));
const newCount = computed(() => props.preview?.rows.filter((row) => !row.skip).length ?? 0);
watch(
    () => props.preview?.token,
    () => {
        current.value = 1;
    },
);
const check = () =>
    form.post(`/admin/imports/${props.kind}/preview`, {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            uploadKey.value++;
        },
    });
const commit = () => {
    if (!props.preview || expired.value || confirmation.processing) return;
    confirmation.token = props.preview.token;
    confirmation.post(`/admin/imports/${props.kind}/commit`);
};
</script>
<template>
    <AppLayout
        title="นำเข้าข้อมูลพื้นฐาน"
        subtitle="เตรียมข้อมูลทั้งองค์กรจาก Excel ตรวจสอบก่อนบันทึกทุกครั้ง"
    >
        <template #actions
            ><a class="btn btn-primary" :href="`/admin/imports/${kind}/template`"
                ><AppIcon name="document" />ดาวน์โหลดเทมเพลต{{ selected.name }}</a
            ></template
        >
        <section class="surface section-pad">
            <h2>เลือกข้อมูลที่ต้องการนำเข้า</h2>
            <div class="button-row" aria-label="หัวข้อนำเข้าข้อมูลพื้นฐาน">
                <Link
                    v-for="(definition, key) in definitions"
                    :key="key"
                    :href="`/admin/imports?kind=${key}`"
                    :class="['btn', kind === key ? 'btn-primary' : 'btn-secondary']"
                    :aria-current="kind === key ? 'page' : undefined"
                    >{{ definition.name }}</Link
                >
            </div>
            <p class="helper">
                แนะนำให้นำเข้าแผนกและตำแหน่งก่อนผู้ใช้งาน ใช้รหัสข้อมูลอ้างอิงตามที่มีในระบบ
            </p>
        </section>
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>นำเข้า{{ selected.name }}</h2>
                <a class="btn btn-secondary" :href="`/admin/imports/${kind}/template`"
                    >ดาวน์โหลด Excel</a
                >
            </div>
            <p>
                กรอกชีต Data เริ่มจากแถวที่ 2 และคงชื่อคอลัมน์เดิม รองรับ .xlsx ไม่เกิน 10 MB /
                1,000 รายการ
            </p>
            <p v-if="kind === 'users'" class="notice">
                เลขประจำตัวต้องเป็นข้อความตัวเลข 1–15 หลัก เพื่อรักษาเลขศูนย์นำหน้า
                บัญชีใหม่ได้รับบทบาทผู้เรียนและรหัสผ่านแรก learn123456
                พร้อมเปลี่ยนรหัสผ่านก่อนใช้งาน เลขประจำตัวที่มีแล้วจะถูกข้าม โดยไม่แก้บัญชีเดิม
            </p>
            <p v-else-if="kind === 'stores'" class="notice">
                ร้านใหม่มีสถานะรออนุมัติ
                หลังนำเข้าให้กำหนดผู้ดูแลร้านและอนุมัติจากเมนูร้านและคูปองก่อนรับคูปอง
            </p>
            <p v-else class="notice">
                รายการใหม่เปิดใช้งานอัตโนมัติ หากพบรหัสซ้ำ ระบบจะแจ้งให้แก้ไฟล์ก่อนบันทึก
            </p>
            <form class="stack-form" @submit.prevent="check">
                <FormErrors :errors="form.errors" />
                <div class="field">
                    <label for="master-excel">ไฟล์ Excel {{ selected.name }}</label
                    ><input
                        id="master-excel"
                        :key="uploadKey"
                        type="file"
                        accept=".xlsx"
                        required
                        :disabled="form.processing"
                        @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                </div>
                <p v-if="form.progress" role="status">
                    กำลังส่งไฟล์ {{ form.progress.percentage }}%
                </p>
                <button class="btn btn-primary" :disabled="form.processing">
                    ตรวจไฟล์และแสดงตัวอย่าง
                </button>
            </form>
        </section>
        <section v-if="preview" class="surface section-pad">
            <h2>ตรวจรายการก่อนนำเข้า</h2>
            <p>
                {{ preview.file_name }} · เพิ่มใหม่ {{ newCount }} รายการ · ข้าม
                {{ preview.rows.length - newCount }} รายการ
            </p>
            <p class="helper">
                ยืนยันได้ถึง
                {{ dateTime(preview.expires_at) }}
                ระบบจะตรวจรหัสซ้ำและข้อมูลอ้างอิงอีกครั้งก่อนบันทึก
            </p>
            <p v-if="expired" class="notice warning" role="status">
                ตัวอย่างหมดอายุ กรุณาตรวจไฟล์ใหม่
            </p>
            <div
                class="table-wrap"
                tabindex="0"
                aria-label="ตัวอย่างข้อมูลนำเข้า เลื่อนตารางเพื่อดูคอลัมน์เพิ่มเติม"
            >
                <table
                    class="master-preview-table"
                    :class="{ 'master-user-preview': kind === 'users' }"
                >
                    <thead>
                        <tr>
                            <th>แถว Excel</th>
                            <th>การบันทึก</th>
                            <th v-for="header in selected.headers" :key="header">{{ header }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.line">
                            <td>{{ row.line }}</td>
                            <td>
                                <span
                                    class="status-badge"
                                    :class="row.skip ? 'is-inactive' : 'is-active'"
                                    >{{ row.skip ? 'ข้ามรายการซ้ำ' : 'เพิ่มใหม่' }}</span
                                >
                            </td>
                            <td
                                v-for="field in selected.fields"
                                :key="field"
                                :class="
                                    field === 'username' ||
                                    field.includes('code') ||
                                    field === 'phone'
                                        ? 'identifier'
                                        : ''
                                "
                            >
                                {{ row.data[field] ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="button-row">
                <button class="btn btn-secondary" :disabled="current === 1" @click="current--">
                    หน้าก่อนหน้า</button
                ><span>หน้า {{ current }} / {{ pages }}</span
                ><button class="btn btn-secondary" :disabled="current === pages" @click="current++">
                    หน้าถัดไป
                </button>
            </div>
            <FormErrors :errors="confirmation.errors" />
            <button
                class="btn btn-primary"
                :disabled="confirmation.processing || expired"
                @click="commit"
            >
                ยืนยันนำเข้า {{ newCount }} รายการ
            </button>
        </section>
        <section class="surface section-pad">
            <h2>รายการนำเข้าล่าสุดของคุณ</h2>
            <p v-if="!history.length" class="empty-state">ยังไม่มีรายการนำเข้า</p>
            <div v-else class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ข้อมูล</th>
                            <th>ไฟล์</th>
                            <th>บันทึกเมื่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in history" :key="index">
                            <td>{{ definitions[item.kind]?.name }}</td>
                            <td>{{ item.file_name }}</td>
                            <td>{{ dateTime(item.committed_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
