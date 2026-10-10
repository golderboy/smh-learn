<script setup lang="ts">
defineOptions({ name: 'WelfareAdministration' });
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import UiDialog from '../../Components/UiDialog.vue';
import TextField from '../../Components/TextField.vue';
import Pagination from '../../Components/Pagination.vue';
import type { Paginated } from '../../Types/foundation';
import type { Claim, Offer, WelfareStore } from '../../Types/welfare';
import { money, couponStatus } from '../../Types/welfare';
const props = defineProps<{
    stores: WelfareStore[];
    people: { id: number; display_name: string; employee_code: string }[];
    offers: Offer[];
    claims: Paginated<Claim>;
}>();
const tab = ref('stores');
const storeOpen = ref(false);
const storeEdit = ref<number | null>(null);
const search = ref('');
const people = computed(() =>
    props.people.filter((person) =>
        `${person.display_name} ${person.employee_code}`
            .toLocaleLowerCase()
            .includes(search.value.toLocaleLowerCase()),
    ),
);
const store = useForm({ code: '', name: '', contact: '', operator_ids: [] as number[] });
function editStore(item?: WelfareStore) {
    storeEdit.value = item?.id ?? null;
    search.value = '';
    store.clearErrors();
    store.code = item?.code ?? '';
    store.name = item?.name ?? '';
    store.contact = item?.contact ?? '';
    store.operator_ids = item?.operators?.map((person) => person.id) ?? [];
    storeOpen.value = true;
}
function saveStore() {
    const options = {
        onSuccess: () => {
            storeOpen.value = false;
        },
    };
    if (storeEdit.value) store.put(`/admin/welfare/stores/${storeEdit.value}`, options);
    else store.post('/admin/welfare/stores', options);
}
const statusOpen = ref(false);
const statusStore = ref<WelfareStore | null>(null);
const status = useForm({ status: 'APPROVED', reason: '' });
function changeStatus(item: WelfareStore, value: string) {
    statusStore.value = item;
    status.status = value;
    status.reason = '';
    status.clearErrors();
    statusOpen.value = true;
}
function saveStatus() {
    if (statusStore.value)
        status.put(`/admin/welfare/stores/${statusStore.value.id}/status`, {
            onSuccess: () => {
                statusOpen.value = false;
            },
        });
}
const offerOpen = ref(false);
const offerEdit = ref<number | null>(null);
const baht = ref('5.00');
const offer = useForm({
    welfare_store_id: 0,
    name: '',
    description: '',
    required_points: 500,
    discount_satang: 500,
    inventory: 100,
    is_active: true,
    start_date: '',
    end_date: '',
});
const priceError = ref('');
function editOffer(item?: Offer) {
    offerEdit.value = item?.id ?? null;
    offer.clearErrors();
    priceError.value = '';
    offer.welfare_store_id = item?.welfare_store_id ?? props.stores[0]?.id ?? 0;
    offer.name = item?.name ?? 'ส่วนลดเครื่องดื่ม';
    offer.description = item?.description ?? '';
    offer.required_points = item?.required_points ?? 500;
    offer.discount_satang = item?.discount_satang ?? 500;
    baht.value = (offer.discount_satang / 100).toFixed(2);
    offer.inventory = item?.inventory ?? 100;
    offer.is_active = item?.is_active ?? true;
    offer.start_date = item?.start_date?.slice(0, 10) ?? '';
    offer.end_date = item?.end_date?.slice(0, 10) ?? '';
    offerOpen.value = true;
}
function saveOffer() {
    const match = baht.value.trim().match(/^(\d{1,6})(?:\.(\d{1,2}))?$/);
    if (!match) {
        priceError.value = 'ระบุจำนวนบาท และทศนิยมไม่เกิน 2 ตำแหน่ง';
        return;
    }
    offer.discount_satang = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
    priceError.value = '';
    const options = {
        onSuccess: () => {
            offerOpen.value = false;
        },
    };
    if (offerEdit.value) offer.put(`/admin/welfare/rewards/${offerEdit.value}`, options);
    else offer.post('/admin/welfare/rewards', options);
}
const stockOpen = ref(false);
const stockOffer = ref<Offer | null>(null);
const stock = useForm({ quantity: 100 });
function addStock(item: Offer) {
    stockOffer.value = item;
    stock.quantity = 100;
    stock.clearErrors();
    stockOpen.value = true;
}
function saveStock() {
    if (stockOffer.value)
        stock.post(`/admin/welfare/rewards/${stockOffer.value.id}/stock`, {
            onSuccess: () => {
                stockOpen.value = false;
            },
        });
}
</script>
<template>
    <AppLayout
        title="ร้านและคูปองสวัสดิการ"
        subtitle="Admin กำหนดร้าน ผู้ปฏิบัติงาน และรายการคูปองที่เปิดให้แลก"
    >
        <section class="welfare-hero">
            <div>
                <p class="eyebrow">บริหารสิทธิพิเศษจากการเรียนรู้</p>
                <h2>อนุมัติร้านก่อนเริ่มรับคูปอง</h2>
                <p>ร้านเห็นเฉพาะรายการของตนเอง • คูปองมีอายุ 90 วัน • รับได้ครั้งเดียว</p>
            </div>
            <div class="welfare-balance">
                <span>ร้านเข้าร่วม</span
                ><strong>{{ stores.filter((item) => item.status === 'APPROVED').length }}</strong
                ><span>จาก {{ stores.length }} ร้าน</span>
            </div>
        </section>
        <nav class="button-row" aria-label="งานสวัสดิการ">
            <button
                v-for="item in [
                    { key: 'stores', name: 'ร้านที่เข้าร่วม' },
                    { key: 'offers', name: 'รายการคูปอง' },
                    { key: 'claims', name: 'ใบสรุปส่งเบิก' },
                ]"
                :key="item.key"
                class="btn"
                :class="tab === item.key ? 'btn-primary' : 'btn-secondary'"
                :aria-pressed="tab === item.key"
                @click="tab = item.key"
            >
                {{ item.name }}
            </button>
        </nav>
        <section v-if="tab === 'stores'" class="surface section-pad">
            <div class="section-heading">
                <h2>ร้านที่เข้าร่วม</h2>
                <button class="btn btn-primary" @click="editStore()">เพิ่มร้าน</button>
            </div>
            <div class="welfare-store-grid">
                <article v-for="item in stores" :key="item.id" class="welfare-store-card">
                    <span class="badge">{{
                        item.status === 'APPROVED' ? 'อนุมัติแล้ว' : couponStatus[item.status]
                    }}</span>
                    <h3>{{ item.name }}</h3>
                    <p>
                        {{ item.code }}<span v-if="item.contact"> • {{ item.contact }}</span>
                    </p>
                    <p>
                        ผู้ปฏิบัติงาน:
                        {{
                            item.operators?.map((person) => person.display_name).join(', ') ||
                            'ยังไม่ได้กำหนด'
                        }}
                    </p>
                    <div class="button-row">
                        <button class="btn btn-secondary" @click="editStore(item)">แก้ไขร้าน</button
                        ><button
                            v-if="item.status !== 'APPROVED'"
                            class="btn btn-primary"
                            @click="changeStatus(item, 'APPROVED')"
                        >
                            อนุมัติร้าน</button
                        ><button
                            v-else
                            class="btn btn-secondary"
                            @click="changeStatus(item, 'SUSPENDED')"
                        >
                            ระงับร้าน
                        </button>
                    </div>
                </article>
            </div>
            <div v-if="!stores.length" class="empty-state">
                <h3>เพิ่มร้านแรกของโครงการ</h3>
                <p>กำหนดผู้ปฏิบัติงาน แล้วอนุมัติร้านเพื่อเริ่มรับคูปอง</p>
            </div>
        </section>
        <section v-if="tab === 'offers'" class="surface section-pad">
            <div class="section-heading">
                <h2>รายการคูปอง</h2>
                <button class="btn btn-primary" :disabled="!stores.length" @click="editOffer()">
                    เพิ่มรายการคูปอง
                </button>
            </div>
            <ul class="coupon-history">
                <li v-for="item in offers" :key="item.id">
                    <div>
                        <h3>{{ item.name }}</h3>
                        <p>{{ item.store?.name }}</p>
                        <small
                            >{{ item.required_points.toLocaleString('th-TH') }} แต้ม • คงเหลือ
                            {{ item.inventory }} ใบ •
                            {{ item.is_active ? 'เปิดแลก' : 'ปิดแลก' }}</small
                        >
                    </div>
                    <strong>{{ money(item.discount_satang) }} บาท</strong>
                    <div class="button-row">
                        <button class="btn btn-secondary" @click="editOffer(item)">
                            แก้ไขรายการ</button
                        ><button class="btn btn-primary" @click="addStock(item)">เพิ่มจำนวน</button>
                    </div>
                </li>
            </ul>
            <p class="muted">
                การแก้ไขราคา/แต้มมีผลกับคูปองที่แลกใหม่ คูปองที่ออกไปแล้วเก็บราคาและรายละเอียดเดิม
            </p>
        </section>
        <section v-if="tab === 'claims'" class="surface section-pad">
            <h2>ใบสรุปส่งเบิกทั้งหมด</h2>
            <ul class="coupon-history">
                <li v-for="item in claims.data" :key="item.claim_id">
                    <div>
                        <h3>{{ item.store_name }}</h3>
                        <p>
                            {{ item.start_date }} – {{ item.end_date }} • {{ item.coupon_count }} ใบ
                        </p>
                    </div>
                    <strong>{{ money(item.total_satang) }} บาท</strong
                    ><Link class="btn btn-secondary" :href="`/welfare/claims/${item.claim_id}`"
                        >เปิดใบสรุป</Link
                    >
                </li>
            </ul>
            <Pagination v-bind="claims" />
        </section>
        <UiDialog
            v-model:open="storeOpen"
            :title="storeEdit ? 'แก้ไขร้านสวัสดิการ' : 'เพิ่มร้านสวัสดิการ'"
            ><form class="stack-form" @submit.prevent="saveStore">
                <TextField
                    v-model="store.code"
                    name="store-code"
                    label="รหัสร้าน"
                    :readonly="!!storeEdit"
                    :maxlength="40"
                    :error="store.errors.code"
                    required
                /><TextField
                    v-model="store.name"
                    name="store-name"
                    label="ชื่อร้าน"
                    :maxlength="200"
                    :error="store.errors.name"
                    required
                /><TextField
                    v-model="store.contact"
                    name="store-contact"
                    label="ช่องทางติดต่อ"
                    :maxlength="250"
                />
                <fieldset>
                    <legend>ผู้ปฏิบัติงานประจำร้าน ({{ store.operator_ids.length }} คน)</legend>
                    <TextField v-model="search" name="operator-search" label="ค้นหาผู้ปฏิบัติงาน" />
                    <div class="welfare-people-picker">
                        <label v-for="person in people" :key="person.id" class="checkbox-label"
                            ><input
                                v-model="store.operator_ids"
                                type="checkbox"
                                :value="person.id"
                            />{{ person.display_name }} · {{ person.employee_code }}</label
                        >
                    </div>
                </fieldset>
                <p class="muted">
                    ให้สิทธิ์รับคูปอง ดูรายงาน และส่งเบิกเฉพาะร้านนี้
                    ผู้ที่เปลี่ยนสิทธิ์ต้องเข้าสู่ระบบใหม่
                </p>
                <p v-for="(error, key) in store.errors" :key="key" class="field-error" role="alert">
                    {{ error }}
                </p>
                <button class="btn btn-primary" :disabled="store.processing">บันทึกร้าน</button>
            </form></UiDialog
        >
        <UiDialog
            v-model:open="statusOpen"
            :title="status.status === 'APPROVED' ? 'อนุมัติร้านสวัสดิการ' : 'ระงับร้านสวัสดิการ'"
            ><form class="stack-form" @submit.prevent="saveStatus">
                <h3>{{ statusStore?.name }}</h3>
                <p>
                    {{
                        status.status === 'APPROVED'
                            ? 'ร้านนี้จะเปิดรับคูปอง และเปิดแลกรายการที่กำหนดไว้'
                            : 'หยุดรับคูปองและหยุดแลกคูปองใหม่ รายงานและใบส่งเบิกเดิมยังใช้งานได้'
                    }}
                </p>
                <TextField
                    v-model="status.reason"
                    name="store-status-reason"
                    label="เหตุผล"
                    :maxlength="500"
                    required
                />
                <p
                    v-for="(error, key) in status.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn btn-primary" :disabled="status.processing">
                    ยืนยัน{{ status.status === 'APPROVED' ? 'อนุมัติร้าน' : 'ระงับร้าน' }}
                </button>
            </form></UiDialog
        >
        <UiDialog
            v-model:open="offerOpen"
            :title="offerEdit ? 'แก้ไขรายการคูปอง' : 'เพิ่มรายการคูปอง'"
            ><form class="stack-form" @submit.prevent="saveOffer">
                <label
                    >ร้านที่ใช้คูปอง<select
                        v-model="offer.welfare_store_id"
                        :disabled="!!offerEdit"
                        required
                    >
                        <option v-for="item in stores" :key="item.id" :value="item.id">
                            {{ item.name }}
                        </option>
                    </select></label
                ><TextField
                    v-model="offer.name"
                    name="offer-name"
                    label="ชื่อรายการ"
                    :maxlength="200"
                    required
                /><TextField
                    v-model="offer.description"
                    name="offer-description"
                    label="รายละเอียดเพิ่มเติม"
                    :maxlength="2000"
                />
                <div class="welfare-form-grid">
                    <label
                        >แต้มที่ใช้<input
                            v-model.number="offer.required_points"
                            type="number"
                            min="1"
                            max="1000000"
                            required /></label
                    ><TextField
                        v-model="baht"
                        name="discount-baht"
                        label="ส่วนลด (บาท)"
                        :error="priceError"
                        required
                    /><label v-if="!offerEdit"
                        >จำนวนคูปองเริ่มต้น<input
                            v-model.number="offer.inventory"
                            type="number"
                            min="0"
                            max="1000000"
                            required /></label
                    ><label>วันเริ่มเปิดแลก<input v-model="offer.start_date" type="date" /></label
                    ><label
                        >วันสุดท้ายที่เปิดแลก<input v-model="offer.end_date" type="date"
                    /></label>
                </div>
                <label class="checkbox-label"
                    ><input v-model="offer.is_active" type="checkbox" />เปิดให้แลก</label
                >
                <p>อายุคูปอง 90 วันนับจากเวลาแลก ใช้ได้เฉพาะร้านที่เลือก</p>
                <p v-for="(error, key) in offer.errors" :key="key" class="field-error" role="alert">
                    {{ error }}
                </p>
                <button class="btn btn-primary" :disabled="offer.processing">
                    บันทึกรายการคูปอง
                </button>
            </form></UiDialog
        >
        <UiDialog v-model:open="stockOpen" title="เพิ่มจำนวนคูปองที่เปิดแลก"
            ><form class="stack-form" @submit.prevent="saveStock">
                <h3>{{ stockOffer?.name }}</h3>
                <p>คงเหลือ {{ stockOffer?.inventory }} ใบ</p>
                <label
                    >จำนวนที่เพิ่ม<input
                        v-model.number="stock.quantity"
                        type="number"
                        min="1"
                        max="1000000"
                        required
                /></label>
                <p v-if="stock.errors.quantity" class="field-error" role="alert">
                    {{ stock.errors.quantity }}
                </p>
                <button class="btn btn-primary" :disabled="stock.processing">
                    ยืนยันเพิ่มจำนวน
                </button>
            </form></UiDialog
        >
    </AppLayout>
</template>
