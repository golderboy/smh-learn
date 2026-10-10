<script setup lang="ts">
defineOptions({ name: 'ApplicationErrorPage' });
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../Types/foundation';
import BrandMark from '../Components/BrandMark.vue';
import SiteFooter from '../Components/SiteFooter.vue';
const props = defineProps<{ status: number }>();
const page = usePage<SharedProps>();
const messages: Record<number, { title: string; text: string }> = {
    403: {
        title: 'ไม่สามารถเปิดหน้านี้ได้',
        text: 'บัญชีนี้ไม่มีสิทธิ์ หรือรายการไม่อยู่ในช่วงที่เปิดใช้งาน กรุณากลับไปตรวจสถานะล่าสุด',
    },
    404: {
        title: 'ไม่พบหน้าหรือรายการนี้',
        text: 'ลิงก์อาจเปลี่ยนหรือรายการถูกนำออกแล้ว กรุณาเลือกจากหน้ารายการอีกครั้ง',
    },
    409: {
        title: 'ข้อมูลเปลี่ยนระหว่างทำรายการ',
        text: 'กรุณากลับไปโหลดข้อมูลล่าสุดแล้วตรวจสอบก่อนทำต่อ',
    },
    419: {
        title: 'การเข้าสู่ระบบหมดอายุ',
        text: 'กรุณาเข้าสู่ระบบใหม่แล้วตรวจสถานะล่าสุด ระบบจะไม่ส่งรายการเดิมซ้ำอัตโนมัติ',
    },
    429: { title: 'ส่งรายการถี่เกินไป', text: 'กรุณารอสักครู่แล้วเปิดหน้ารายการอีกครั้ง' },
    500: {
        title: 'ระบบทำรายการไม่สำเร็จ',
        text: 'กรุณาลองใหม่ภายหลัง หากยังพบปัญหาให้ติดต่อผู้ดูแลพร้อมเวลาที่เกิดเหตุ',
    },
    503: { title: 'ระบบยังไม่พร้อมให้บริการ', text: 'กรุณารอสักครู่แล้วเปิดหน้ารายการอีกครั้ง' },
};
const message = computed(() => messages[props.status] ?? messages[500]!);
</script>
<template>
    <Head :title="`${message.title} | ${page.props.app?.name ?? 'SMH Learning'}`"
        ><link rel="icon" :href="page.props.app?.branding?.favicon_url || '/favicon.svg'"
    /></Head>
    <main class="password-shell">
        <section class="surface section-pad error-panel">
            <div class="error-brand">
                <BrandMark /><strong>{{ page.props.app?.name || 'SMH Learning' }}</strong>
            </div>
            <p class="muted">{{ status }}</p>
            <h1>{{ message.title }}</h1>
            <p>{{ message.text }}</p>
            <div class="button-row">
                <a v-if="status === 419" class="btn btn-primary" href="/login">เข้าสู่ระบบ</a
                ><a v-else class="btn btn-primary" href="/dashboard">กลับภาพรวม</a
                ><a
                    v-if="page.props.auth?.user && status !== 419"
                    class="btn btn-secondary"
                    href="/learning"
                    >การเรียนของฉัน</a
                >
            </div>
            <SiteFooter />
        </section>
    </main>
</template>
