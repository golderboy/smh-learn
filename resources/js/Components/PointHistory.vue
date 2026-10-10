<script setup lang="ts">
import { useDateTime } from '../date-time';
const dateTimeLabel = useDateTime();
import Pagination from './Pagination.vue';
import type { Paginated } from '../Types/foundation';
import type { PointEntry } from '../Types/reporting';
import { pointNames, numberLabel } from '../Types/reporting';
defineProps<{ history: Paginated<PointEntry>; people?: { id: number; display_name: string }[] }>();
</script>
<template>
    <div v-if="!history.total" class="empty-state">
        <strong>ยังไม่มีรายการแต้ม</strong>
        <p>แต้มจากการเรียนและการปรับโดยผู้ดูแลจะแสดงที่นี่</p>
    </div>
    <div
        v-else
        class="table-wrap point-history"
        tabindex="0"
        aria-label="ประวัติแต้ม เลื่อนแนวนอนเพื่อดูทุกคอลัมน์"
    >
        <table v-responsive-table>
            <thead>
                <tr>
                    <th>เวลา</th>
                    <th v-if="people">บุคลากร</th>
                    <th>รายการ</th>
                    <th>รายละเอียด / เหตุผล</th>
                    <th>แต้ม</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in history.data" :key="entry.id">
                    <td>{{ dateTimeLabel(entry.created_at) }}</td>
                    <td v-if="people">
                        {{
                            people.find((person) => person.id === entry.user_id)?.display_name ??
                            'ไม่ระบุ'
                        }}
                    </td>
                    <td>{{ pointNames[entry.transaction_type] ?? entry.transaction_type }}</td>
                    <td class="point-description">{{ entry.description }}</td>
                    <td class="point-amount" :class="{ negative: entry.points < 0 }">
                        {{ entry.points > 0 ? '+' : '' }}{{ numberLabel(entry.points) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <Pagination v-bind="history" />
</template>
