<script setup lang="ts">
defineOptions({ name: 'WelfareCouponCatalog' });
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AppIcon from '../../Components/AppIcon.vue';
import UiDialog from '../../Components/UiDialog.vue';
import Pagination from '../../Components/Pagination.vue';
import type { Paginated } from '../../Types/foundation';
import type { Coupon, Offer } from '../../Types/welfare';
import { money, couponStatus } from '../../Types/welfare';
import { numberLabel } from '../../Types/reporting';
import { useDateTime } from '../../date-time';
const props = defineProps<{ offers: Offer[]; balance: number; coupons: Paginated<Coupon> }>();
const selected = ref<Offer | null>(null);
const open = ref(false);
const date = useDateTime();
const form = useForm({ request_key: '', expected_points: 0, expected_discount_satang: 0 });
function select(offer: Offer) {
    selected.value = offer;
    form.clearErrors();
    form.request_key = crypto.randomUUID();
    form.expected_points = offer.required_points;
    form.expected_discount_satang = offer.discount_satang;
    open.value = true;
}
function exchange() {
    if (!selected.value || form.processing) return;
    form.post(`/coupons/rewards/${selected.value.id}/exchange`, {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>
<template>
    <AppLayout title="คูปองของฉัน" subtitle="เปลี่ยนความตั้งใจเรียน เป็นสิทธิพิเศษที่ร้านสวัสดิการ">
        <template #actions
            ><Link class="btn btn-secondary" href="/points">ประวัติแต้ม</Link></template
        >
        <section class="welfare-hero">
            <div>
                <p class="eyebrow"><AppIcon name="star" /> สิทธิพิเศษจากการเรียนรู้</p>
                <h2>เรียนรู้ สะสมแต้ม แลกคูปอง</h2>
                <p>ใช้ได้เฉพาะร้านที่ระบุบนบัตร • อายุ 90 วัน • ใช้ได้ครั้งเดียว</p>
            </div>
            <div class="welfare-balance">
                <span>แต้มพร้อมแลก</span
                ><strong data-testid="coupon-balance">{{ numberLabel(props.balance) }}</strong
                ><span>แต้ม</span>
            </div>
        </section>
        <section aria-labelledby="offers-heading">
            <div class="section-heading">
                <h2 id="offers-heading">เลือกคูปองที่ใช่</h2>
                <span class="muted">{{ offers.length }} รายการ</span>
            </div>
            <div v-if="offers.length" class="coupon-grid">
                <article
                    v-for="offer in offers"
                    :key="offer.id"
                    class="coupon-offer"
                    :data-testid="`offer-${offer.required_points}`"
                >
                    <div class="coupon-offer-top">
                        <AppIcon name="star" /><span>คูปองส่วนลด</span
                        ><strong>{{ money(offer.discount_satang) }} <small>บาท</small></strong>
                    </div>
                    <div class="coupon-offer-body">
                        <h3>{{ offer.name }}</h3>
                        <p class="coupon-store-name">{{ offer.store_name }}</p>
                        <p v-if="offer.description">{{ offer.description }}</p>
                        <div class="coupon-details">
                            <strong>{{ numberLabel(offer.required_points) }} แต้ม</strong
                            ><span>คงเหลือ {{ numberLabel(offer.inventory) }} ใบ</span>
                        </div>
                        <button
                            class="btn btn-primary"
                            :disabled="balance < offer.required_points || offer.inventory < 1"
                            @click="select(offer)"
                        >
                            {{
                                offer.inventory < 1
                                    ? 'คูปองหมด'
                                    : balance < offer.required_points
                                      ? 'แต้มไม่เพียงพอ'
                                      : 'แลกคูปองนี้'
                            }}
                        </button>
                    </div>
                </article>
            </div>
            <div v-else class="empty-state">
                <h3>ยังไม่มีคูปองเปิดแลก</h3>
                <p>เมื่อแอดมินอนุมัติร้านและเปิดรายการ คูปองจะแสดงที่นี่</p>
            </div>
        </section>
        <section class="surface section-pad">
            <h2>บัตรคูปองของฉัน</h2>
            <p class="muted">เปิดบัตรเพื่อแสดงรหัสให้ร้านที่ระบุ รับคูปองผ่านระบบก่อนรับส่วนลด</p>
            <ul v-if="coupons.data.length" class="coupon-history">
                <li v-for="coupon in coupons.data" :key="coupon.coupon_id">
                    <div>
                        <h3>{{ coupon.reward_name }}</h3>
                        <p>{{ coupon.store_name }}</p>
                        <small>หมดอายุ {{ date(coupon.expires_at) }}</small>
                    </div>
                    <strong>{{ money(coupon.discount_satang) }} บาท</strong
                    ><span
                        class="badge"
                        :class="coupon.status === 'APPROVED' ? 'badge-success' : ''"
                        >{{ couponStatus[coupon.status] ?? coupon.status }}</span
                    ><Link class="btn btn-secondary" :href="`/coupons/${coupon.coupon_id}`"
                        >เปิดบัตร</Link
                    >
                </li>
            </ul>
            <div v-else class="empty-state">
                <h3>บัตรใบแรกของคุณเริ่มที่นี่</h3>
                <p>เมื่อแลกสำเร็จ ระบบจะหักแต้มและออกบัตรให้ทันที</p>
            </div>
            <Pagination v-bind="coupons" />
        </section>
        <UiDialog v-model:open="open" title="ยืนยันแลกแต้มเป็นคูปอง"
            ><div v-if="selected" class="stack-form">
                <div class="welfare-callout">
                    <h3>{{ selected.name }}</h3>
                    <p>{{ selected.store_name }}</p>
                    <strong>ส่วนลด {{ money(selected.discount_satang) }} บาท</strong>
                </div>
                <p>
                    หัก {{ numberLabel(selected.required_points) }} แต้ม จาก
                    {{ numberLabel(balance) }} แต้ม
                </p>
                <p>
                    หลังแลกคงเหลือ
                    <strong>{{ numberLabel(balance - selected.required_points) }} แต้ม</strong>
                </p>
                <p>ใช้ได้ครั้งเดียว ภายใน 90 วันนับจากเวลาที่แลก</p>
                <p v-for="(error, key) in form.errors" :key="key" class="field-error" role="alert">
                    {{ error }}
                </p>
                <div class="button-row">
                    <button class="btn btn-primary" :disabled="form.processing" @click="exchange">
                        {{ form.processing ? 'กำลังออกคูปอง…' : 'ยืนยันแลกคูปอง' }}</button
                    ><button
                        class="btn btn-secondary"
                        :disabled="form.processing"
                        @click="open = false"
                    >
                        กลับไปเลือก
                    </button>
                </div>
            </div></UiDialog
        >
    </AppLayout>
</template>
