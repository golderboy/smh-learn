<script setup lang="ts">
import { ref } from 'vue';

type CheckResult = { ok: boolean; checks: Record<string, boolean> };
const state = ref<'idle' | 'loading' | 'success' | 'error'>('idle');
const result = ref<CheckResult | null>(null);
const checks = [
    { key: 'database', label: 'MariaDB' },
    { key: 'redis', label: 'Redis' },
    { key: 'storage', label: 'พื้นที่เก็บไฟล์' },
];

async function checkConnections() {
    state.value = 'loading';
    result.value = null;
    try {
        const response = await fetch('/dev/status', { headers: { Accept: 'application/json' } });
        result.value = (await response.json()) as CheckResult;
        state.value = response.ok && result.value.ok ? 'success' : 'error';
    } catch {
        state.value = 'error';
    }
}
</script>

<template>
    <section class="rounded-panel border border-divider bg-surface p-6 shadow-sm">
        <h2 class="text-xl font-semibold text-foreground">ตรวจการเชื่อมต่อบริการ</h2>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
            ตรวจฐานข้อมูล Redis และการอ่านเขียนไฟล์ผ่าน Laravel ที่รันอยู่ใน Docker
        </p>
        <button
            type="button"
            class="btn btn-primary mt-5 w-full sm:w-auto"
            :disabled="state === 'loading'"
            @click="checkConnections"
        >
            {{ state === 'loading' ? 'กำลังตรวจสอบ…' : 'ตรวจการเชื่อมต่อ' }}
        </button>
        <div class="mt-4" role="status" aria-live="polite">
            <p v-if="state === 'success'" class="font-semibold text-primary">
                เชื่อมต่อบริการทั้งหมดสำเร็จ
            </p>
            <p v-else-if="state === 'error'" class="font-semibold text-red-700">
                ตรวจสอบไม่สำเร็จ กรุณาตรวจสถานะบริการแล้วลองอีกครั้ง
            </p>
            <p v-else-if="state === 'idle'" class="text-sm text-muted-foreground">
                ยังไม่ได้ตรวจการเชื่อมต่อในครั้งนี้
            </p>
        </div>
        <ul v-if="result" class="mt-4 grid gap-3 sm:grid-cols-3">
            <li
                v-for="check in checks"
                :key="check.key"
                class="flex items-center justify-between gap-3 rounded-lg bg-background p-3 text-sm"
            >
                <span>{{ check.label }}</span>
                <span :class="result.checks[check.key] ? 'text-primary' : 'text-red-700'">
                    {{ result.checks[check.key] ? 'ผ่าน' : 'ไม่ผ่าน' }}
                </span>
            </li>
        </ul>
    </section>
</template>
