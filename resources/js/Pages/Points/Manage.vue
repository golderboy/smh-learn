<script setup lang="ts">
defineOptions({ name: 'ManagePoints' });
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import FormErrors from '../../Components/FormErrors.vue';
import PointHistory from '../../Components/PointHistory.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import UiDialog from '../../Components/UiDialog.vue';
import { ref } from 'vue';
import { useUnsavedForms } from '../../unsaved-forms';
import type { Paginated } from '../../Types/foundation';
import type { PointEntry } from '../../Types/reporting';
import { pointNames, numberLabel } from '../../Types/reporting';
const props = defineProps<{
    rules: { code: string; points: number; is_active: boolean; threshold_percent: string | null }[];
    people: { id: number; display_name: string; employee_code: string }[];
    selectedUserId: number | string | null;
    balances: Record<string, number>;
    history: Paginated<PointEntry>;
}>();
const rulesForm = useForm({ rules: props.rules.map((rule) => ({ ...rule })) });
const adjustment = useForm({
    user_id: String(props.selectedUserId ?? ''),
    points: '',
    reason: '',
    request_key: crypto.randomUUID(),
});
const balance = computed(() => props.balances[adjustment.user_id] ?? 0);
const confirmOpen = ref(false);
const projectedBalance = computed(() => Number(balance.value) + Number(adjustment.points || 0));
useUnsavedForms(rulesForm, {
    get isDirty() {
        return !!adjustment.points || !!adjustment.reason;
    },
    get processing() {
        return adjustment.processing;
    },
});
function adjust() {
    confirmOpen.value = false;
    adjustment.post(`/admin/points/users/${adjustment.user_id}/adjust`, {
        onSuccess: () => {
            adjustment.reset('points', 'reason');
            adjustment.request_key = crypto.randomUUID();
        },
    });
}
</script>
<template>
    <AppLayout title="จัดการแต้ม" subtitle="กฎการให้แต้มและการปรับยอดพร้อมเหตุผล">
        <section class="surface section-pad">
            <h2>กฎสำหรับรอบเรียนใหม่</h2>
            <p class="muted">
                เปิดให้แต้มได้ในหน้าจัดการหลักสูตร กฎจะถูกตรึงเมื่อเปิดให้แต้มในรอบนั้น
            </p>
            <form class="stack-form" @submit.prevent="rulesForm.put('/admin/points/rules')">
                <FormErrors :errors="rulesForm.errors" />
                <div class="rule-grid">
                    <div
                        v-for="(rule, index) in rulesForm.rules"
                        :key="rule.code"
                        class="rule-card"
                    >
                        <h3>{{ pointNames[rule.code] }}</h3>
                        <div class="field">
                            <label :for="`rule-points-${index}`">จำนวนแต้ม</label
                            ><input
                                :id="`rule-points-${index}`"
                                v-model="rule.points"
                                type="number"
                                min="0"
                                max="1000000"
                                required
                            />
                        </div>
                        <div v-if="rule.code === 'HIGH_SCORE'" class="field">
                            <label :for="`rule-threshold-${index}`">คะแนนสูงสุดอย่างน้อย (%)</label
                            ><input
                                :id="`rule-threshold-${index}`"
                                v-model="rule.threshold_percent"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                required
                            />
                        </div>
                        <label class="check-field"
                            ><input v-model="rule.is_active" type="checkbox" />
                            เปิดใช้โบนัสนี้</label
                        >
                    </div>
                </div>
                <div>
                    <button class="btn btn-primary" :disabled="rulesForm.processing">
                        บันทึกกฎแต้ม
                    </button>
                </div>
            </form>
        </section>
        <section class="surface section-pad">
            <h2>ปรับแต้มบุคลากร</h2>
            <p class="muted">
                ระบุค่าบวกเพื่อเพิ่ม หรือค่าลบเพื่อลด ต้องระบุเหตุผลทุกครั้ง รายการเดิมเก็บไว้เสมอ
            </p>
            <form class="stack-form" @submit.prevent="confirmOpen = true">
                <FormErrors :errors="adjustment.errors" />
                <div class="form-grid">
                    <div>
                        <SearchSelect
                            id="point-person"
                            v-model="adjustment.user_id"
                            label="บุคลากร"
                            :options="
                                people.map((person) => ({
                                    id: person.id,
                                    name: `${person.display_name} · ${person.employee_code}`,
                                }))
                            "
                            required
                            ><small v-if="adjustment.user_id"
                                >คงเหลือ {{ numberLabel(Number(balance)) }} แต้ม</small
                            ></SearchSelect
                        >
                    </div>
                    <div class="field">
                        <label for="point-adjustment">แต้มที่เพิ่ม / ลด</label
                        ><input
                            id="point-adjustment"
                            v-model="adjustment.points"
                            type="number"
                            min="-1000000"
                            max="1000000"
                            required
                        />
                    </div>
                </div>
                <div class="field">
                    <label for="point-reason">เหตุผล (อย่างน้อย 5 ตัวอักษร)</label
                    ><textarea
                        id="point-reason"
                        v-model="adjustment.reason"
                        minlength="5"
                        maxlength="500"
                        rows="3"
                        required
                    />
                </div>
                <div>
                    <button
                        class="btn btn-primary"
                        :disabled="adjustment.processing || !adjustment.user_id"
                    >
                        บันทึกการปรับแต้ม
                    </button>
                </div>
            </form>
        </section>
        <UiDialog v-model:open="confirmOpen" title="ยืนยันการปรับแต้ม"
            ><p>
                {{
                    people.find((person) => String(person.id) === adjustment.user_id)?.display_name
                }}
            </p>
            <p>
                ปรับ {{ numberLabel(Number(adjustment.points)) }} แต้ม · ยอดประมาณหลังปรับ
                {{ numberLabel(projectedBalance) }} แต้ม
            </p>
            <p class="muted">ยอดจริงยืนยันหลังระบบบันทึก</p>
            <p>เหตุผล: {{ adjustment.reason }}</p>
            <div class="button-row">
                <button class="btn btn-primary" :disabled="adjustment.processing" @click="adjust">
                    ยืนยันปรับแต้ม</button
                ><button class="btn btn-secondary" @click="confirmOpen = false">กลับไปแก้ไข</button>
            </div></UiDialog
        >
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>ประวัติแต้ม</h2>
                <button
                    class="btn btn-secondary"
                    @click="
                        router.get(
                            '/admin/points',
                            adjustment.user_id ? { user_id: adjustment.user_id } : {},
                        )
                    "
                >
                    แสดงประวัติที่เลือก
                </button>
            </div>
            <PointHistory :history="history" :people="people" />
        </section>
    </AppLayout>
</template>
