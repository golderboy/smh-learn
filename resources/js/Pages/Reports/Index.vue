<script setup lang="ts">
import { useDateTime } from '../../date-time';
const dateTimeLabel = useDateTime();
defineOptions({ name: 'LearningReports' });
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import FormErrors from '../../Components/FormErrors.vue';
import Pagination from '../../Components/Pagination.vue';
import ReportMetrics from '../../Components/ReportMetrics.vue';
import UiDialog from '../../Components/UiDialog.vue';
import type { NamedRecord } from '../../Types/foundation';
import type { ExportRecord, ReportData, ReportType } from '../../Types/reporting';
import { reportNames, numberLabel } from '../../Types/reporting';
import { stateLabels } from '../../Types/learning';
const props = defineProps<{
    filters: Record<string, string | number>;
    reportType: ReportType;
    professional: boolean;
    report: ReportData;
    options: {
        courses: NamedRecord[];
        categories: NamedRecord[];
        departments: NamedRecord[];
        positions: NamedRecord[];
        people: NamedRecord[];
        years: number[];
    };
    exports: ExportRecord[];
}>();
const filtersOpen = ref(false);
const form = useForm({
    type: props.reportType,
    audience: String(props.filters.audience ?? 'scope'),
    fiscal_year: String(props.filters.fiscal_year ?? ''),
    course_id: String(props.filters.course_id ?? ''),
    category_id: String(props.filters.category_id ?? ''),
    department_id: String(props.filters.department_id ?? ''),
    position_id: String(props.filters.position_id ?? ''),
    user_id: String(props.filters.user_id ?? ''),
    date_from: String(props.filters.date_from ?? ''),
    date_to: String(props.filters.date_to ?? ''),
    status: String(props.filters.status ?? ''),
    exam_type: String(props.filters.exam_type ?? ''),
});
const exporting = useForm({
    type: props.reportType,
    format: 'xlsx',
    request_key: crypto.randomUUID(),
    ...props.filters,
});
const types = computed(() =>
    Object.entries(reportNames).filter(
        ([type]) => type !== 'question' || (props.professional && form.audience !== 'mine'),
    ),
);
function apply() {
    if (form.audience === 'mine' && form.type === 'question') form.type = 'individual';
    form.get('/reports', {
        preserveScroll: true,
        onSuccess: () => {
            filtersOpen.value = false;
        },
    });
}
function exportFile(format: string) {
    exporting.format = format;
    exporting.post('/reports/exports', {
        onSuccess: () => {
            exporting.request_key = crypto.randomUUID();
        },
    });
}
function display(key: string, value: string | number | boolean | null) {
    if (key === 'status') return stateLabels[String(value)] ?? value;
    return typeof value === 'number' ? numberLabel(value) : (value ?? '—');
}
const exportStates: Record<string, string> = {
    QUEUED: 'รอคิว',
    RUNNING: 'กำลังสร้าง',
    READY: 'พร้อมดาวน์โหลด',
    FAILED: 'สร้างไม่สำเร็จ',
    EXPIRED: 'หมดอายุ',
};
const filterChoices = computed(
    () =>
        [
            { key: 'course_id', label: 'หลักสูตร', choices: props.options.courses },
            { key: 'category_id', label: 'หมวดหลักสูตร', choices: props.options.categories },
            { key: 'department_id', label: 'แผนก ณ สมัคร', choices: props.options.departments },
            { key: 'position_id', label: 'ตำแหน่ง ณ สมัคร', choices: props.options.positions },
            { key: 'user_id', label: 'บุคลากร', choices: props.options.people },
        ] as const,
);
const appliedLabels = computed(() => {
    const labels = filterChoices.value.flatMap((filter) => {
        const value = props.filters[filter.key];
        const option = filter.choices.find((choice) => String(choice.id) === String(value));
        return option ? [`${filter.label}: ${option.name}`] : [];
    });
    if (props.filters.fiscal_year) labels.push(`ปีงบประมาณ: ${props.filters.fiscal_year}`);
    if (props.filters.status)
        labels.push(`สถานะ: ${stateLabels[String(props.filters.status)] ?? props.filters.status}`);
    if (props.filters.date_from) labels.push(`ตั้งแต่: ${props.filters.date_from}`);
    if (props.filters.date_to) labels.push(`ถึง: ${props.filters.date_to}`);
    if (props.filters.exam_type) labels.push(`ชุดสอบ: ${props.filters.exam_type}`);
    return labels;
});
</script>
<template>
    <AppLayout
        title="รายงานผลการเรียน"
        :subtitle="
            professional && filters.audience !== 'mine'
                ? 'ข้อมูลในขอบเขตสิทธิ์ของคุณ · ใช้แผนกและตำแหน่ง ณ สมัครเรียน'
                : 'ผลการเรียนส่วนบุคคลของคุณ'
        "
    >
        <div class="section-heading">
            <div class="filter-summary">
                <span class="filter-chip">{{ reportNames[reportType] }}</span
                ><span v-for="label in appliedLabels" :key="label" class="filter-chip">{{
                    label
                }}</span
                ><span v-if="!appliedLabels.length" class="muted">ทุกข้อมูลในขอบเขตที่เลือก</span>
            </div>
            <button
                class="btn btn-secondary"
                :aria-expanded="filtersOpen"
                @click="filtersOpen = true"
            >
                ตัวกรองรายงาน{{ form.isDirty ? ' · มีการแก้ไข' : '' }}
            </button>
        </div>
        <UiDialog v-model:open="filtersOpen" title="ตัวกรองรายงาน" drawer>
            <form class="stack-form" @submit.prevent="apply">
                <FormErrors :errors="form.errors" />
                <div class="report-filter-grid">
                    <div v-if="professional" class="field">
                        <label for="report-audience">ขอบเขต</label
                        ><select id="report-audience" v-model="form.audience">
                            <option value="scope">ตามสิทธิ์ของฉัน</option>
                            <option value="mine">การเรียนของฉัน</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="report-type">รูปแบบรายงาน</label
                        ><select id="report-type" v-model="form.type">
                            <option v-for="[type, name] in types" :key="type" :value="type">
                                {{ name }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="report-year">ปีงบประมาณ</label
                        ><select id="report-year" v-model="form.fiscal_year">
                            <option value="">ทุกปี</option>
                            <option v-for="year in options.years" :key="year" :value="String(year)">
                                {{ year }}
                            </option>
                        </select>
                    </div>
                    <div v-for="filter in filterChoices" :key="filter.key" class="field">
                        <label :for="`report-${filter.key}`">{{ filter.label }}</label
                        ><select :id="`report-${filter.key}`" v-model="form[filter.key]">
                            <option value="">ทั้งหมด</option>
                            <option
                                v-for="choice in filter.choices"
                                :key="choice.id"
                                :value="String(choice.id)"
                            >
                                {{ choice.name }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="report-from">สมัครเรียนตั้งแต่</label
                        ><input id="report-from" v-model="form.date_from" type="date" />
                    </div>
                    <div class="field">
                        <label for="report-to">สมัครเรียนถึง</label
                        ><input id="report-to" v-model="form.date_to" type="date" />
                    </div>
                    <div class="field">
                        <label for="report-status">สถานะ</label
                        ><select id="report-status" v-model="form.status">
                            <option value="">ทั้งหมด</option>
                            <option value="COMPLETED">เรียนจบ</option>
                            <option value="NOT_STARTED">ยังไม่เริ่ม</option>
                            <option value="IN_PROGRESS">กำลังเรียน</option>
                            <option value="OVERDUE">เกินกำหนด</option>
                            <option value="CANCELLED">ยกเลิก</option>
                        </select>
                    </div>
                    <div v-if="form.type === 'question'" class="field">
                        <label for="report-exam">ประเภทข้อสอบ</label
                        ><select id="report-exam" v-model="form.exam_type">
                            <option value="">Pretest และ Posttest</option>
                            <option value="PRETEST">Pretest</option>
                            <option value="POSTTEST">Posttest</option>
                        </select>
                    </div>
                </div>
                <div class="button-row">
                    <button class="btn btn-primary" :disabled="form.processing">แสดงรายงาน</button
                    ><button
                        type="button"
                        class="btn btn-secondary"
                        @click="router.get('/reports')"
                    >
                        ล้างตัวกรอง
                    </button>
                </div>
            </form>
        </UiDialog>
        <ReportMetrics :summary="report.summary" />
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>{{ reportNames[reportType] }}</h2>
                <div class="button-row">
                    <button
                        v-for="format in ['xlsx', 'csv', 'pdf']"
                        :key="format"
                        class="btn btn-secondary"
                        :disabled="exporting.processing || form.isDirty"
                        @click="exportFile(format)"
                    >
                        ส่งออก {{ format.toUpperCase() }}
                    </button>
                </div>
            </div>
            <FormErrors :errors="exporting.errors" />
            <p v-if="form.isDirty" class="notice" role="status">
                ตัวกรองเปลี่ยนแล้ว กรุณากดแสดงรายงานก่อนส่งออกไฟล์
            </p>
            <p class="muted">
                ใช้คะแนน Posttest สูงสุด · การจบไม่บังคับคะแนนผ่าน · — หมายถึงยังไม่มีผล ·
                รายการยกเลิกไม่รวมในอัตราและค่าเฉลี่ย
            </p>
            <div v-if="!report.rows.total" class="empty-state">
                <strong>ไม่พบข้อมูลตามตัวกรอง</strong>
                <p>ลองเลือกช่วงเวลา หลักสูตร หรือสถานะอื่น</p>
            </div>
            <div
                v-else
                class="table-wrap report-table"
                :class="{ 'mobile-alternative': reportType !== 'question' }"
                tabindex="0"
                aria-label="ตารางรายงาน เลื่อนแนวนอนเพื่อดูทุกคอลัมน์"
            >
                <table>
                    <thead>
                        <tr>
                            <th
                                v-for="(label, key) in report.columns"
                                :key="key"
                                :class="`report-column-${key}`"
                            >
                                {{ label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in report.rows.data" :key="index">
                            <td
                                v-for="(_, key) in report.columns"
                                :key="key"
                                :class="`report-column-${key}`"
                            >
                                {{ display(String(key), row[key] ?? null) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="reportType !== 'question' && report.rows.total"
                class="report-mobile-rows"
                aria-label="รายการรายงานบนมือถือ"
            >
                <article
                    v-for="(row, index) in report.rows.data"
                    :key="index"
                    class="report-mobile-row"
                >
                    <dl>
                        <div v-for="(label, key) in report.columns" :key="key">
                            <dt>{{ label }}</dt>
                            <dd>{{ display(String(key), row[key] ?? null) }}</dd>
                        </div>
                    </dl>
                </article>
            </div>
            <Pagination v-bind="report.rows" />
            <p class="muted">
                Pretest เฉลี่ย {{ numberLabel(report.summary.pretest_average) }}% · สอบผ่าน
                {{ numberLabel(report.summary.passed) }} /
                {{ numberLabel(report.summary.posttest_count) }} รายการ · อัตราผ่าน
                {{ numberLabel(report.summary.pass_rate) }}%
            </p>
        </section>
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>ไฟล์ส่งออกล่าสุด</h2>
                <button class="btn btn-secondary" @click="router.reload({ only: ['exports'] })">
                    ตรวจสถานะไฟล์
                </button>
            </div>
            <p class="muted">
                ไฟล์แต่ละรายการใช้ตัวกรอง ณ เวลาที่ขอ เก็บไฟล์ 7 วัน
                ต้องใช้บัญชีผู้ขอและยังมีสิทธิ์เดิมจึงดาวน์โหลดได้
            </p>
            <div v-if="!exports.length" class="empty-state">
                <p>กดส่งออกเพื่อสร้างไฟล์รายงาน</p>
            </div>
            <div v-else class="export-list">
                <article v-for="file in exports" :key="file.token" class="export-item">
                    <div>
                        <strong
                            >{{ reportNames[file.report_type] }} ·
                            {{ file.format.toUpperCase() }}</strong
                        >
                        <p class="muted">
                            {{ dateTimeLabel(file.created_at) }} · {{ exportStates[file.status]
                            }}<span v-if="file.row_count !== null">
                                · {{ file.row_count }} รายการ</span
                            >
                        </p>
                        <p v-if="file.expires_at" class="muted">
                            หมดอายุ {{ dateTimeLabel(file.expires_at)
                            }}<span v-if="file.file_size !== null && file.file_size !== undefined">
                                · {{ numberLabel(file.file_size / 1024) }} KB</span
                            >
                        </p>
                        <p v-if="file.error_message" role="alert">{{ file.error_message }}</p>
                        <p v-if="file.status === 'READY' && !file.can_download" class="muted">
                            ไฟล์หมดอายุหรือสิทธิ์เปลี่ยน กรุณาสร้างใหม่
                        </p>
                    </div>
                    <a
                        v-if="file.can_download"
                        class="btn btn-primary"
                        :href="`/reports/exports/${file.token}/download`"
                        >ดาวน์โหลด {{ file.format.toUpperCase() }}</a
                    >
                </article>
            </div>
        </section>
    </AppLayout>
</template>
