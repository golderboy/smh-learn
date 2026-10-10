<script setup lang="ts">
defineOptions({ name: 'WelfareClaimStatement' });
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps } from '../../Types/foundation';
import type { Claim } from '../../Types/welfare';
import { money } from '../../Types/welfare';
import { useDateTime } from '../../date-time';
defineProps<{ claim: Claim; creator: string }>();
const page = usePage<SharedProps>();
const date = useDateTime();
</script>
<template>
    <AppLayout
        title="ใบสรุปคูปองเพื่อส่งเบิก"
        subtitle="รวมรายการรับคูปองที่เลือกไว้ พร้อมเอกสารส่งหน่วยงาน"
        ><template #actions
            ><Link
                class="btn btn-secondary"
                :href="
                    page.props.auth.user?.permissions.includes('welfare.manage')
                        ? '/admin/welfare'
                        : '/welfare/store'
                "
                >กลับไปรายการ</Link
            ></template
        >
        <section class="welfare-hero">
            <div>
                <p class="eyebrow">{{ claim.organization_name }}</p>
                <h2>{{ claim.store_name }}</h2>
                <p>{{ claim.start_date }} ถึง {{ claim.end_date }}</p>
            </div>
            <div class="welfare-balance">
                <span>{{ claim.coupon_count }} คูปอง</span
                ><strong data-testid="claim-total">{{ money(claim.total_satang) }}</strong
                ><span>บาท • ยอดส่งเบิก</span>
            </div>
        </section>
        <section class="surface section-pad">
            <div class="section-heading">
                <h2>เอกสารส่งเบิก</h2>
                <div class="button-row">
                    <a class="btn btn-primary" :href="`/welfare/claims/${claim.claim_id}/pdf`"
                        >ดาวน์โหลด PDF</a
                    ><a class="btn btn-secondary" :href="`/welfare/claims/${claim.claim_id}/csv`"
                        >ดาวน์โหลด CSV</a
                    >
                </div>
            </div>
            <p class="welfare-callout">
                ใบนี้เป็นสรุปเพื่อส่งเบิก ยังไม่บันทึกการจ่ายเงินจริง
                รายการในใบนี้ถูกกันไว้เพื่อป้องกันส่งเบิกซ้ำ
            </p>
            <p>ผู้จัดทำ {{ creator }} • {{ date(claim.created_at) }}</p>
            <p v-if="claim.note">หมายเหตุ: {{ claim.note }}</p>
            <p class="coupon-reference">เลขใบสรุป {{ claim.claim_id }}</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th>ผู้ถือคูปอง</th>
                            <th>รับเมื่อ</th>
                            <th>มูลค่า (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in claim.items" :key="item.id">
                            <td>
                                <strong>{{ item.reward_name }}</strong
                                ><small class="coupon-reference">{{ item.coupon_id }}</small>
                            </td>
                            <td>{{ item.holder_name }}</td>
                            <td>{{ date(item.received_at) }}</td>
                            <td>{{ money(item.discount_satang) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">รวม {{ claim.coupon_count }} ใบ</th>
                            <td>
                                <strong>{{ money(claim.total_satang) }}</strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
