<script setup lang="ts">
import { numberLabel } from '../Types/reporting';
defineProps<{ summary: Record<string, number | null> }>();
</script>
<template>
    <section class="metrics-row" aria-label="ผลการเรียนในรายงาน">
        <div>
            <span>รายการเรียน</span><strong>{{ numberLabel(summary.total) }}</strong
            ><small
                >บุคลากร {{ numberLabel(summary.staff) }} คน · ยกเลิก
                {{ numberLabel(summary.cancelled) }}</small
            >
        </div>
        <div>
            <span>เรียนจบ</span><strong>{{ numberLabel(summary.completed) }}</strong
            ><small
                >จบ {{ numberLabel(summary.completion_rate) }}% · เกินกำหนด
                {{ numberLabel(summary.overdue) }}</small
            >
        </div>
        <div>
            <span>Posttest เฉลี่ย</span
            ><strong
                >{{ numberLabel(summary.posttest_average)
                }}<small class="metric-unit">%</small></strong
            ><small>คะแนนสูงสุดของผู้สอบ {{ numberLabel(summary.posttest_count) }} รายการ</small>
        </div>
        <div>
            <span>เพิ่มขึ้นเฉลี่ย</span><strong>{{ numberLabel(summary.gain_average) }}</strong
            ><small
                >จุดร้อยละ · มี Pre/Post คู่กัน
                {{ numberLabel(summary.paired_count) }} รายการ</small
            >
        </div>
    </section>
</template>
