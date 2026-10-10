<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import FormErrors from './FormErrors.vue';
import SearchSelect from './SearchSelect.vue';
import UiDialog from './UiDialog.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { NamedRecord } from '../Types/foundation';
import type { SharedProps } from '../Types/foundation';
import { pointNames } from '../Types/reporting';
import type { SessionRecord } from '../Types/learning';
import { dateLabel, stateLabels } from '../Types/learning';
const props = defineProps<{
    session: SessionRecord;
    departments: NamedRecord[];
    positions: NamedRecord[];
    users: { id: number; display_name: string }[];
}>();
const form = useForm({ assignment_type: 'all', target_id: '', due_date: '' });
const assessment = useForm({ max_attempts: props.session.max_attempts });
const page = usePage<SharedProps>();
const points = useForm({ points_enabled: props.session.points_enabled });
useUnsavedForms(form, assessment, points);
const options = computed(() =>
    form.assignment_type === 'department'
        ? props.departments
        : form.assignment_type === 'position'
          ? props.positions
          : props.users.map((user) => ({ id: user.id, name: user.display_name })),
);
function changeStatus(status: string) {
    statusForm.status = status;
    statusOpen.value = true;
}
function cancel(id: number) {
    cancelTarget.value = id;
    cancelOpen.value = true;
}
const statusForm = useForm({ status: '' });
const statusOpen = ref(false);
const assignmentOpen = ref(false);
const cancelTarget = ref<number>();
const cancelOpen = ref(false);
const cancellation = useForm({ reason: '' });
function confirmStatus() {
    statusForm.put(`/sessions/${props.session.id}/status`, {
        onSuccess: () => {
            statusOpen.value = false;
        },
    });
}
function assign() {
    form.post(`/sessions/${props.session.id}/assign`, {
        onSuccess: () => {
            assignmentOpen.value = false;
        },
    });
}
function confirmCancel() {
    if (cancelTarget.value)
        cancellation.post(`/learning/${cancelTarget.value}/cancel`, {
            onSuccess: () => {
                cancelOpen.value = false;
                cancellation.reset();
            },
        });
}
</script>
<template>
    <article class="session-editor" :data-session-id="session.id">
        <div class="section-heading">
            <div>
                <h3>{{ session.name }}</h3>
                <p class="muted">
                    ปีงบประมาณ {{ session.fiscal_year }} · {{ dateLabel(session.start_date) }} ถึง
                    {{ dateLabel(session.end_date) }}
                </p>
            </div>
            <span class="badge">{{
                { draft: 'ฉบับร่าง', open: 'เปิดรอบเรียน', closed: 'ปิดรอบเรียน' }[session.status]
            }}</span>
        </div>
        <p>
            ดูวิดีโอ {{ session.required_video_watch_percent }}% · บทที่จำเป็น
            {{ session.required_progress_percent }}% · Posttest ได้รวม
            {{ session.max_attempts }} ครั้ง
        </p>
        <p>
            การลงทะเบียน:
            {{
                {
                    optional: 'สมัครเอง',
                    required: 'ได้รับมอบหมาย',
                    mandatory: 'บังคับและได้รับมอบหมาย',
                }[session.enrollment_mode]
            }}
            ·
            {{
                session.posttest_required
                    ? 'ต้องส่ง Pretest ก่อนเรียน และส่ง Posttest หลังเรียนครบ ไม่บังคับคะแนนผ่าน'
                    : 'เรียนครบตามเกณฑ์แล้วจบหลักสูตร'
            }}
        </p>
        <form
            class="stack-form"
            @submit.prevent="assessment.put(`/sessions/${session.id}/assessment`)"
        >
            <FormErrors :errors="assessment.errors" />
            <div class="field">
                <label :for="`attempts-${session.id}`"
                    >จำนวนครั้ง Posttest รวมทั้งหมด (ค่าเริ่มต้น 1)</label
                ><input
                    :id="`attempts-${session.id}`"
                    v-model.number="assessment.max_attempts"
                    type="number"
                    min="1"
                    max="100"
                    required
                />
            </div>
            <p class="muted">
                รวมครั้งแรกด้วย ใช้คะแนนสูงสุดและเก็บผลทุกครั้ง การเพิ่มสิทธิ์ใช้กับการสอบครั้งถัดไป
            </p>
            <button class="btn btn-secondary" :disabled="assessment.processing">
                บันทึกจำนวนครั้งสอบ
            </button>
        </form>
        <div class="session-points">
            <h4>แต้มรอบเรียน</h4>
            <p>
                {{ session.points_enabled ? 'เปิดให้แต้ม' : 'ยังไม่เปิดให้แต้ม' }} ·
                ให้โบนัสครั้งเดียวต่อคนต่อรอบเรียน
            </p>
            <p v-for="(rule, code) in session.point_rules_snapshot ?? {}" :key="code" class="muted">
                {{ pointNames[code] ?? code }} +{{ rule.points }} แต้ม<span
                    v-if="rule.threshold_percent !== null"
                >
                    · อย่างน้อย {{ rule.threshold_percent }}%</span
                >
            </p>
            <form
                v-if="page.props.auth.user?.permissions.includes('points.manage')"
                class="stack-form"
                @submit.prevent="points.put(`/sessions/${session.id}/points`)"
            >
                <FormErrors :errors="points.errors" /><label class="check-field"
                    ><input v-model="points.points_enabled" type="checkbox" />
                    เปิดการให้แต้มในรอบนี้</label
                ><button class="btn btn-secondary" :disabled="points.processing">
                    บันทึกการให้แต้ม</button
                ><small class="muted"
                    >ตรึงกฎเมื่อเปิดครั้งแรก
                    เมื่อมีประวัติให้แต้มแล้วต้องสร้างรอบใหม่เพื่อเปลี่ยนกฎ</small
                >
            </form>
        </div>
        <div class="button-row">
            <button
                class="btn btn-secondary"
                type="button"
                @click="changeStatus(session.status === 'open' ? 'closed' : 'open')"
            >
                {{ session.status === 'open' ? 'ปิดรอบเรียน' : 'เปิดรอบเรียน' }}
            </button>
        </div>
        <details v-if="session.status === 'open'">
            <summary>มอบหมายการเรียน</summary>
            <form class="stack-form" @submit.prevent="assignmentOpen = true">
                <FormErrors :errors="form.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label :for="`assignment-type-${session.id}`">กลุ่มเป้าหมาย</label
                        ><select
                            :id="`assignment-type-${session.id}`"
                            v-model="form.assignment_type"
                            @change="form.target_id = ''"
                        >
                            <option value="all">บุคลากรทั้งหมดที่เปิดใช้ขณะนี้</option>
                            <option value="department">แผนก</option>
                            <option value="position">ตำแหน่ง</option>
                            <option value="user">รายบุคคล</option>
                        </select>
                    </div>
                    <div v-if="form.assignment_type !== 'all'">
                        <SearchSelect
                            :id="`assignment-target-${session.id}`"
                            v-model="form.target_id"
                            label="เลือกผู้รับมอบหมาย"
                            :options="options"
                            required
                        />
                    </div>
                    <div class="field">
                        <label :for="`assignment-due-${session.id}`">กำหนดส่ง (ถ้ามี)</label
                        ><input
                            :id="`assignment-due-${session.id}`"
                            v-model="form.due_date"
                            type="date"
                            :min="session.start_date.slice(0, 10)"
                            :max="session.end_date.slice(0, 10)"
                        />
                    </div>
                </div>
                <p class="muted">
                    มอบหมายให้ผู้ตรงเงื่อนไขในขณะนี้ การเพิ่มหรือย้ายแผนกภายหลังให้มอบหมายอีกครั้ง
                    รายการเดิมยังคงประวัติและกำหนดส่งเดิม
                </p>
                <button class="btn btn-primary" :disabled="form.processing">มอบหมาย</button>
            </form>
        </details>
        <details>
            <summary>ผู้ลงทะเบียน {{ session.enrollments?.length ?? 0 }} คน</summary>
            <div class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>ผู้เรียน</th>
                            <th>สถานะ</th>
                            <th>ความก้าวหน้า</th>
                            <th>การดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in session.enrollments" :key="item.id">
                            <td>{{ item.user?.display_name }}</td>
                            <td>{{ stateLabels[item.state] }}</td>
                            <td>{{ item.progress_percent }}%</td>
                            <td>
                                <button
                                    v-if="!item.cancelled_at"
                                    class="btn btn-quiet danger-text"
                                    type="button"
                                    @click="cancel(item.id)"
                                >
                                    ยกเลิกการเรียน</button
                                ><span v-else>ยกเลิกแล้ว</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
        <UiDialog
            v-model:open="statusOpen"
            :title="statusForm.status === 'open' ? 'เปิดรอบเรียน' : 'ปิดรอบเรียน'"
            ><p>{{ session.name }} · ปี {{ session.fiscal_year }}</p>
            <p class="muted">
                {{
                    statusForm.status === 'open'
                        ? 'ระบบจะตรวจเวอร์ชัน หลักสูตร และช่วงวันที่ก่อนเปิดให้เรียน'
                        : 'ผู้เรียนจะเปิดเนื้อหาและเริ่มสอบไม่ได้ในช่วงที่ปิดรอบ ประวัติเดิมยังคงอยู่'
                }}
            </p>
            <FormErrors :errors="statusForm.errors" />
            <div class="button-row">
                <button
                    class="btn btn-primary"
                    :disabled="statusForm.processing"
                    @click="confirmStatus"
                >
                    ยืนยัน{{ statusForm.status === 'open' ? 'เปิด' : 'ปิด' }}รอบเรียน</button
                ><button class="btn btn-secondary" @click="statusOpen = false">ยกเลิก</button>
            </div></UiDialog
        >
        <UiDialog v-model:open="assignmentOpen" title="ยืนยันมอบหมายการเรียน"
            ><p>{{ session.name }}</p>
            <p>
                {{
                    form.assignment_type === 'all'
                        ? `บุคลากรที่เปิดใช้ในรายชื่อ ${users.length} คน`
                        : options.find((option) => String(option.id) === String(form.target_id))
                              ?.name
                }}
            </p>
            <p>กำหนดส่ง: {{ form.due_date ? dateLabel(form.due_date) : 'ไม่ระบุ' }}</p>
            <p class="muted">
                ผู้รับมอบหมายจริงตรวจจากข้อมูล ณ เวลาที่ระบบบันทึก เก็บประวัติของรายการเดิมไว้
            </p>
            <FormErrors :errors="form.errors" />
            <div class="button-row">
                <button class="btn btn-primary" :disabled="form.processing" @click="assign">
                    ยืนยันมอบหมาย</button
                ><button class="btn btn-secondary" @click="assignmentOpen = false">
                    กลับไปแก้ไข
                </button>
            </div></UiDialog
        >
        <UiDialog v-model:open="cancelOpen" title="ยกเลิกการเรียน"
            ><p>
                {{
                    session.enrollments?.find((item) => item.id === cancelTarget)?.user
                        ?.display_name
                }}
                · {{ session.name }}
            </p>
            <p class="muted">เก็บประวัติการเรียนเดิม พร้อมเหตุผลการยกเลิก</p>
            <form class="form-stack" @submit.prevent="confirmCancel">
                <FormErrors :errors="cancellation.errors" />
                <div class="field">
                    <label :for="`cancel-reason-${session.id}`"
                        >เหตุผลยกเลิก (อย่างน้อย 3 ตัวอักษร)</label
                    ><textarea
                        :id="`cancel-reason-${session.id}`"
                        v-model="cancellation.reason"
                        required
                        minlength="3"
                        maxlength="500"
                        rows="3"
                    ></textarea>
                </div>
                <div class="button-row">
                    <button class="btn btn-danger" :disabled="cancellation.processing">
                        ยืนยันยกเลิกการเรียน</button
                    ><button type="button" class="btn btn-secondary" @click="cancelOpen = false">
                        กลับไป
                    </button>
                </div>
            </form></UiDialog
        >
    </article>
</template>
