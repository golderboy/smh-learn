<script setup lang="ts">
defineOptions({ name: 'MyPoints' });
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PointHistory from '../../Components/PointHistory.vue';
import AppIcon from '../../Components/AppIcon.vue';
import type { Paginated } from '../../Types/foundation';
import type { PointEntry } from '../../Types/reporting';
import { numberLabel } from '../../Types/reporting';
defineProps<{ balance: number; history: Paginated<PointEntry>; canManage: boolean }>();
</script>
<template>
    <AppLayout title="แต้มของฉัน" subtitle="แต้มสะสมและประวัติการได้รับแต้ม"
        ><template #actions
            ><Link class="btn btn-primary" href="/coupons">แลกแต้มเป็นคูปอง</Link
            ><Link v-if="canManage" class="btn btn-secondary" href="/admin/points"
                >จัดการแต้ม</Link
            ></template
        >
        <section class="surface section-pad point-balance">
            <div>
                <p class="eyebrow"><AppIcon name="star" />แต้มคงเหลือ</p>
                <strong data-testid="point-balance">{{ numberLabel(balance) }}</strong
                ><span> แต้ม</span>
            </div>
            <p class="muted">
                แต่ละโบนัสให้ครั้งเดียวต่อรอบเรียน การสอบซ้ำใช้คะแนนสูงสุดเพื่อพิจารณาโบนัส
            </p>
        </section>
        <section class="surface section-pad">
            <h2>ประวัติแต้ม</h2>
            <PointHistory :history="history" />
        </section>
    </AppLayout>
</template>
