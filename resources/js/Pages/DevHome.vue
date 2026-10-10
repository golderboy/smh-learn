<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import ConnectionCheck from '../Components/ConnectionCheck.vue';
import type { SharedProps } from '../Types/foundation';
const page = usePage<SharedProps>();

defineProps<{ laravelVersion: string; phpVersion: string }>();
</script>

<template>
    <Head :title="`สถานะบริการ | ${page.props.app.name}`" />
    <main class="min-h-screen bg-background px-4 py-10 text-foreground sm:px-8 sm:py-16">
        <div class="mx-auto max-w-4xl">
            <header class="mb-8">
                <p class="text-sm font-semibold tracking-wide text-primary">
                    {{ page.props.app.name }}
                </p>
                <h1 class="mt-3 text-3xl font-bold leading-snug tracking-tight sm:text-4xl">
                    สถานะบริการระบบ
                </h1>
                <p class="mt-4 max-w-2xl leading-7 text-muted-foreground">
                    ตรวจสอบการเชื่อมต่อฐานข้อมูล พื้นที่เก็บไฟล์ และบริการพื้นฐาน
                </p>
            </header>
            <div class="mb-6 grid gap-4 sm:grid-cols-2">
                <div class="rounded-panel border border-divider bg-surface p-5">
                    <p class="text-sm text-muted-foreground">Backend ใน Docker</p>
                    <p class="mt-2 font-semibold">Laravel {{ laravelVersion }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">PHP {{ phpVersion }}</p>
                </div>
                <div class="rounded-panel border border-divider bg-surface p-5">
                    <p class="text-sm text-muted-foreground">Frontend</p>
                    <p class="mt-2 font-semibold">Vue 3 + TypeScript + Inertia.js</p>
                    <p class="mt-1 text-sm text-muted-foreground">Tailwind CSS และ Vite</p>
                </div>
            </div>
            <ConnectionCheck />
            <p class="mt-6 text-sm leading-6 text-muted-foreground">
                ติดต่อผู้ดูแลระบบหากบริการเชื่อมต่อไม่สำเร็จ
            </p>
        </div>
    </main>
</template>
