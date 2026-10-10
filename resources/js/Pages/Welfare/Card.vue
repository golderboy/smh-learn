<script setup lang="ts">
defineOptions({ name: 'WelfareCouponCard' });
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AppIcon from '../../Components/AppIcon.vue';
import type { Coupon } from '../../Types/welfare';
import { money, couponStatus } from '../../Types/welfare';
import { useDateTime } from '../../date-time';
const props = defineProps<{
    coupon: Coupon & { coupon_code: string | null; holder_name: string; store_status: string };
}>();
const date = useDateTime();
const reveal = ref(false);
const copyState = ref('');
async function copy() {
    if (!props.coupon.coupon_code) return;
    try {
        await navigator.clipboard.writeText(props.coupon.coupon_code);
        copyState.value = 'คัดลอกรหัสแล้ว';
    } catch {
        copyState.value = 'คัดลอกไม่สำเร็จ แตะค้างที่รหัสเพื่อคัดลอก หรือแสดงบัตรให้ร้านอ่าน';
    }
}
</script>
<template>
    <AppLayout title="บัตรคูปองออนไลน์" subtitle="แสดงบัตรนี้แก่ร้านที่ระบุเพื่อรับส่วนลด"
        ><template #actions
            ><Link class="btn btn-secondary" href="/coupons">คูปองของฉัน</Link></template
        >
        <article class="online-coupon">
            <header>
                <span class="eyebrow"><AppIcon name="star" /> LEARNING REWARDS</span
                ><span class="coupon-state">{{
                    couponStatus[coupon.status] ?? coupon.status
                }}</span>
            </header>
            <div class="online-coupon-value">
                <span>ส่วนลดเครื่องดื่ม</span
                ><strong>{{ money(coupon.discount_satang) }} <small>บาท</small></strong>
            </div>
            <h2>{{ coupon.reward_name }}</h2>
            <p class="online-coupon-shop">{{ coupon.store_name }}</p>
            <div class="online-coupon-holder">
                <span>ผู้ถือคูปอง</span><strong>{{ coupon.holder_name }}</strong>
            </div>
            <div v-if="coupon.coupon_code" class="coupon-code-area">
                <p>รหัสใช้คูปอง • แสดงเฉพาะเมื่อพร้อมรับส่วนลด</p>
                <template v-if="reveal"
                    ><code data-testid="coupon-code">{{ coupon.coupon_code }}</code>
                    <div class="button-row">
                        <button class="btn btn-primary" @click="copy">คัดลอกรหัส</button
                        ><button class="btn btn-secondary" @click="reveal = false">ซ่อนรหัส</button>
                    </div></template
                ><button v-else class="btn btn-primary" @click="reveal = true">
                    แสดงรหัสให้ร้าน
                </button>
                <p role="status">{{ copyState }}</p>
            </div>
            <div v-else class="coupon-code-area">
                <h3>
                    {{
                        coupon.status === 'RECEIVED'
                            ? 'รับส่วนลดแล้ว'
                            : coupon.status === 'EXPIRED'
                              ? 'คูปองหมดอายุแล้ว'
                              : 'ร้านยังไม่พร้อมรับคูปอง'
                    }}
                </h3>
                <p v-if="coupon.received_at">ใช้เมื่อ {{ date(coupon.received_at) }}</p>
                <p v-else>ติดต่อร้านหรือผู้ดูแลระบบเพื่อสอบถามสถานะ</p>
            </div>
            <dl class="coupon-facts">
                <div>
                    <dt>แลกเมื่อ</dt>
                    <dd>{{ date(coupon.requested_at) }}</dd>
                </div>
                <div>
                    <dt>หมดอายุ</dt>
                    <dd>{{ date(coupon.expires_at) }}</dd>
                </div>
                <div>
                    <dt>แต้มที่ใช้</dt>
                    <dd>{{ coupon.points_used.toLocaleString('th-TH') }} แต้ม</dd>
                </div>
            </dl>
            <footer>
                <p>ใช้ได้เฉพาะร้านบนบัตรและใช้ได้ครั้งเดียว ร้านจะตรวจและยืนยันผ่านระบบ</p>
                <small>เลขอ้างอิง (ใช้แทนรหัสรับคูปองไม่ได้)</small
                ><span class="coupon-reference">{{ coupon.coupon_id }}</span>
            </footer>
        </article>
    </AppLayout>
</template>
