<script setup lang="ts">
defineOptions({ name: 'WelfareMerchantWorkspace' });
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import UiDialog from '../../Components/UiDialog.vue';
import TextField from '../../Components/TextField.vue';
import Pagination from '../../Components/Pagination.vue';
import type { Paginated, SharedProps } from '../../Types/foundation';
import type { Claim, Coupon, WelfareStore } from '../../Types/welfare';
import { money, couponStatus } from '../../Types/welfare';
import { useDateTime } from '../../date-time';
import { learningPost } from '../../learning-http';
const props = defineProps<{
    stores: WelfareStore[];
    store: WelfareStore | null;
    period: { start_date: string; end_date: string };
    coupons: Paginated<Coupon> | null;
    claims: Paginated<Claim> | null;
    summary: {
        count: number;
        total_satang: number;
        unclaimed_count: number;
        unclaimed_satang: number;
    } | null;
}>();
const page = usePage<SharedProps>();
const canReceive = computed(() => page.props.auth.user?.permissions.includes('welfare.receive'));
const canClaim = computed(() => page.props.auth.user?.permissions.includes('welfare.claims'));
const date = useDateTime();
const storeId = ref(props.store?.id ?? '');
const start = ref(props.period.start_date);
const end = ref(props.period.end_date);
const code = ref('');
const checking = ref(false);
const checkError = ref('');
const preview = ref<Coupon | null>(null);
const checkedCode = ref('');
const receiveOpen = ref(false);
const receive = useForm({
    store_id: props.store?.id ?? 0,
    coupon_code: '',
    coupon_id: '',
    request_key: '',
});
const selected = ref<string[]>([]);
const claimOpen = ref(false);
const claim = useForm({
    store_id: props.store?.id ?? 0,
    coupon_ids: [] as string[],
    start_date: start.value,
    end_date: end.value,
    request_key: '',
    note: '',
});
const available = computed(() => props.coupons?.data.filter((coupon) => !coupon.claim_id) ?? []);
const selectedTotal = computed(() =>
    available.value
        .filter((coupon) => selected.value.includes(coupon.coupon_id))
        .reduce((sum, coupon) => sum + coupon.discount_satang, 0),
);
watch(code, () => {
    preview.value = null;
    receiveOpen.value = false;
    checkError.value = '';
});
watch(
    () => [props.store?.id, props.coupons],
    () => {
        selected.value = [];
        preview.value = null;
        code.value = '';
    },
);
function filter() {
    if (!storeId.value) return;
    router.get(
        '/welfare/store',
        { store_id: storeId.value, start_date: start.value, end_date: end.value },
        { preserveState: false },
    );
}
async function check() {
    if (checking.value || !props.store) return;
    const submittedCode = code.value;
    checking.value = true;
    receive.clearErrors();
    checkError.value = '';
    preview.value = null;
    try {
        const result = await learningPost<{ coupon: Coupon }>('/welfare/coupons/check', {
            store_id: props.store.id,
            coupon_code: submittedCode,
        });
        if (code.value === submittedCode) {
            preview.value = result.coupon;
            checkedCode.value = submittedCode;
            receive.request_key = crypto.randomUUID();
        }
    } catch (error) {
        checkError.value = error instanceof Error ? error.message : 'ตรวจคูปองไม่สำเร็จ';
    } finally {
        checking.value = false;
    }
}
function confirmReceive() {
    if (!preview.value || receive.processing || !props.store || code.value !== checkedCode.value)
        return;
    receive.store_id = props.store.id;
    receive.coupon_code = checkedCode.value;
    receive.coupon_id = preview.value.coupon_id;
    receive.post('/welfare/coupons/receive', {
        onSuccess: () => {
            receiveOpen.value = false;
            preview.value = null;
            code.value = '';
            receive.reset('coupon_code');
        },
        onError: () => {
            receiveOpen.value = false;
            preview.value = null;
        },
    });
}
function prepareClaim() {
    if (!selected.value.length || !props.store) return;
    claim.clearErrors();
    claim.store_id = props.store.id;
    claim.coupon_ids = [...selected.value];
    claim.start_date = props.period.start_date;
    claim.end_date = props.period.end_date;
    claim.request_key = crypto.randomUUID();
    claim.note = '';
    claimOpen.value = true;
}
function createClaim() {
    if (!claim.processing)
        claim.post('/welfare/claims', {
            onSuccess: () => {
                claimOpen.value = false;
            },
        });
}
</script>
<template>
    <AppLayout title="ร้านสวัสดิการ" subtitle="ตรวจรับคูปอง ดูยอดการใช้งาน และจัดทำใบสรุปส่งเบิก">
        <div v-if="!store" class="empty-state">
            <h2>ยังไม่มีร้านที่ได้รับมอบหมาย</h2>
            <p>ติดต่อ Admin เพื่อกำหนดร้านและผู้ปฏิบัติงาน</p>
        </div>
        <template v-else>
            <section class="welfare-hero">
                <div>
                    <p class="eyebrow">พื้นที่ปฏิบัติงานร้านสวัสดิการ</p>
                    <h2>{{ store.name }}</h2>
                    <p>
                        {{
                            store.status === 'APPROVED'
                                ? 'เปิดรับคูปอง • ตรวจรายละเอียดก่อนยืนยันทุกครั้ง'
                                : 'ร้านยังไม่อนุมัติหรือถูกระงับ • ดูประวัติและส่งเบิกรายการเดิมได้'
                        }}
                    </p>
                </div>
                <span class="coupon-state">{{
                    store.status === 'APPROVED' ? 'ร้านอนุมัติแล้ว' : couponStatus[store.status]
                }}</span>
            </section>
            <form class="surface section-pad welfare-filters" @submit.prevent="filter">
                <label
                    >ร้านที่ปฏิบัติงาน<select v-model="storeId">
                        <option v-for="item in stores" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select></label
                ><label>ตั้งแต่วันที่<input v-model="start" type="date" required /></label
                ><label>ถึงวันที่<input v-model="end" type="date" required /></label
                ><button class="btn btn-secondary" type="submit">แสดงรายงาน</button>
                <p v-for="(error, key) in page.props.errors" :key="key" class="field-error">
                    {{ error }}
                </p>
            </form>
            <section v-if="canReceive" class="surface section-pad">
                <h2>รับคูปองจากผู้เรียน</h2>
                <p class="muted">ขอให้ผู้เรียนเปิดบัตรออนไลน์ แล้วนำรหัสจากบัตรมาตรวจในระบบ</p>
                <form class="welfare-code-form" @submit.prevent="check">
                    <TextField
                        v-model="code"
                        name="coupon-code"
                        label="รหัสใช้คูปอง"
                        :maxlength="100"
                        :error="checkError || receive.errors.coupon_code"
                        required
                    /><button
                        class="btn btn-primary"
                        :disabled="checking || store.status !== 'APPROVED' || !code.trim()"
                    >
                        {{ checking ? 'กำลังตรวจ…' : 'ตรวจคูปอง' }}
                    </button>
                </form>
                <div v-if="preview" class="welfare-preview" aria-label="รายละเอียดคูปองที่ตรวจ">
                    <div>
                        <p class="eyebrow">คูปองพร้อมใช้</p>
                        <h3>{{ preview.reward_name }}</h3>
                        <p>ผู้ถือ: {{ preview.holder_name }}</p>
                        <p>ร้าน: {{ preview.store_name }}</p>
                        <small>หมดอายุ {{ date(preview.expires_at) }}</small>
                    </div>
                    <div>
                        <strong class="welfare-preview-value"
                            >{{ money(preview.discount_satang) }} บาท</strong
                        ><button
                            class="btn btn-primary"
                            :disabled="receive.processing"
                            @click="receiveOpen = true"
                        >
                            รับคูปองนี้
                        </button>
                    </div>
                </div>
            </section>
            <section v-if="summary" class="welfare-summary" aria-label="สรุปคูปอง">
                <div>
                    <span>รับคูปองแล้ว</span><strong>{{ summary.count }} ใบ</strong>
                </div>
                <div>
                    <span>ส่วนลดรวม</span><strong>{{ money(summary.total_satang) }} บาท</strong>
                </div>
                <div>
                    <span>ยังไม่ส่งเบิก {{ summary.unclaimed_count }} ใบ</span
                    ><strong>{{ money(summary.unclaimed_satang) }} บาท</strong>
                </div>
            </section>
            <section v-if="coupons" class="surface section-pad">
                <div class="section-heading">
                    <div>
                        <h2>รายการใช้คูปอง</h2>
                        <p class="muted">เลือกรายการที่ยังไม่อยู่ในใบส่งเบิก</p>
                    </div>
                    <button
                        v-if="canClaim"
                        class="btn btn-primary"
                        :disabled="!selected.length"
                        @click="prepareClaim"
                    >
                        สร้างใบสรุปส่งเบิก ({{ selected.length }})
                    </button>
                </div>
                <label v-if="available.length && canClaim" class="checkbox-label"
                    ><input
                        type="checkbox"
                        :checked="available.length > 0 && selected.length === available.length"
                        @change="
                            selected = ($event.target as HTMLInputElement).checked
                                ? available.map((coupon) => coupon.coupon_id)
                                : []
                        "
                    />เลือกที่ยังไม่ส่งเบิกทั้งหมดในหน้านี้</label
                >
                <div v-if="coupons.data.length" class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th v-if="canClaim">เลือก</th>
                                <th>คูปอง / ผู้ถือ</th>
                                <th>รับเมื่อ / ผู้รับ</th>
                                <th>บาท</th>
                                <th>ใบส่งเบิก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="coupon in coupons.data" :key="coupon.coupon_id">
                                <td v-if="canClaim">
                                    <input
                                        v-if="!coupon.claim_id"
                                        v-model="selected"
                                        type="checkbox"
                                        :value="coupon.coupon_id"
                                        :aria-label="`เลือกคูปอง ${coupon.coupon_id}`"
                                    /><span v-else>—</span>
                                </td>
                                <td>
                                    <strong>{{ coupon.reward_name }}</strong
                                    ><small class="block">{{ coupon.holder_name }}</small
                                    ><small class="coupon-reference">{{ coupon.coupon_id }}</small>
                                </td>
                                <td>
                                    {{ date(coupon.received_at)
                                    }}<small class="block">{{ coupon.receiver_name }}</small>
                                </td>
                                <td>{{ money(coupon.discount_satang) }}</td>
                                <td>
                                    <Link
                                        v-if="coupon.claim_id && canClaim"
                                        :href="`/welfare/claims/${coupon.claim_id}`"
                                        >อยู่ในใบสรุปแล้ว</Link
                                    ><span v-else>{{
                                        coupon.claim_id ? 'ส่งเบิกแล้ว' : 'ยังไม่ส่งเบิก'
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state">
                    <h3>ยังไม่มีการใช้คูปองในช่วงนี้</h3>
                    <p>เมื่อร้านยืนยันรับคูปอง รายการจะแสดงที่นี่</p>
                </div>
                <Pagination v-bind="coupons" />
            </section>
            <section v-if="claims && canClaim" class="surface section-pad">
                <h2>ใบสรุปส่งเบิกของร้าน</h2>
                <ul class="coupon-history">
                    <li v-for="item in claims.data" :key="item.claim_id">
                        <div>
                            <strong>{{ item.start_date }} – {{ item.end_date }}</strong
                            ><small>{{ date(item.created_at) }} • {{ item.coupon_count }} ใบ</small>
                        </div>
                        <strong>{{ money(item.total_satang) }} บาท</strong
                        ><Link class="btn btn-secondary" :href="`/welfare/claims/${item.claim_id}`"
                            >เปิดใบสรุป</Link
                        >
                    </li>
                </ul>
                <Pagination v-bind="claims" />
            </section>
        </template>
        <UiDialog v-model:open="receiveOpen" title="ยืนยันรับคูปอง"
            ><div v-if="preview" class="stack-form">
                <h3>{{ preview.reward_name }}</h3>
                <p>ผู้ถือ {{ preview.holder_name }}</p>
                <strong class="welfare-callout"
                    >ให้ส่วนลด {{ money(preview.discount_satang) }} บาท</strong
                >
                <p>เมื่อยืนยันแล้ว คูปองจะใช้ซ้ำไม่ได้</p>
                <button
                    class="btn btn-primary"
                    :disabled="receive.processing"
                    @click="confirmReceive"
                >
                    {{ receive.processing ? 'กำลังบันทึก…' : 'ยืนยันรับคูปอง' }}
                </button>
            </div></UiDialog
        >
        <UiDialog v-model:open="claimOpen" title="สร้างใบสรุปส่งเบิก"
            ><form class="stack-form" @submit.prevent="createClaim">
                <p>{{ store?.name }}</p>
                <div class="welfare-callout">
                    <strong>{{ selected.length }} ใบ • รวม {{ money(selectedTotal) }} บาท</strong>
                    <p>{{ period.start_date }} ถึง {{ period.end_date }}</p>
                </div>
                <TextField
                    v-model="claim.note"
                    name="claim-note"
                    label="หมายเหตุ (ไม่บังคับ)"
                    :maxlength="500"
                />
                <p>รวมเฉพาะรายการที่เลือกและยังไม่ส่งเบิก ขั้นตอนนี้ยังไม่บันทึกการจ่ายเงินจริง</p>
                <p v-for="(error, key) in claim.errors" :key="key" class="field-error" role="alert">
                    {{ error }}
                </p>
                <button class="btn btn-primary" :disabled="claim.processing">
                    {{ claim.processing ? 'กำลังจัดทำ…' : 'ยืนยันสร้างใบสรุป' }}
                </button>
            </form></UiDialog
        >
    </AppLayout>
</template>
