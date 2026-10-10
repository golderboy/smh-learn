<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import type { SharedProps } from '../../Types/foundation';
import BrandMark from '../../Components/BrandMark.vue';
import SiteFooter from '../../Components/SiteFooter.vue';
const page = usePage<SharedProps>();
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
</script>
<template>
    <Head :title="`เปลี่ยนรหัสผ่าน | ${page.props.app.name}`"
        ><link rel="icon" :href="page.props.app.branding?.favicon_url || '/favicon.svg'"
    /></Head>
    <main class="password-shell">
        <section class="surface password-panel">
            <div class="auth-brand">
                <BrandMark />
                <div>
                    <strong>{{ page.props.app.name }}</strong
                    ><small>{{ page.props.app.organization }}</small>
                </div>
            </div>
            <h1>เปลี่ยนรหัสผ่าน</h1>
            <p class="muted">
                {{
                    page.props.auth.user?.must_change_password
                        ? 'กรุณาตั้งรหัสผ่านของคุณก่อนเริ่มใช้งาน'
                        : 'เปลี่ยนรหัสผ่านสำหรับบัญชีของคุณ'
                }}
            </p>
            <form
                class="form-stack"
                @submit.prevent="form.put('/password/change', { onFinish: () => form.reset() })"
            >
                <FormErrors :errors="form.errors" />
                <TextField
                    v-model="form.current_password"
                    name="current_password"
                    label="รหัสผ่านปัจจุบัน"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.current_password"
                    required
                /><TextField
                    v-model="form.password"
                    name="password"
                    label="รหัสผ่านใหม่ (อย่างน้อย 10 ตัวอักษร)"
                    type="password"
                    autocomplete="new-password"
                    :error="form.errors.password"
                    required
                /><TextField
                    v-model="form.password_confirmation"
                    name="password_confirmation"
                    label="ยืนยันรหัสผ่านใหม่"
                    type="password"
                    autocomplete="new-password"
                    :error="form.errors.password_confirmation"
                    required
                /><button class="btn btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'กำลังบันทึก…' : 'บันทึกรหัสผ่านใหม่' }}
                </button>
            </form>
            <div class="form-actions">
                <Link
                    v-if="!page.props.auth.user?.must_change_password"
                    class="btn btn-quiet"
                    href="/profile"
                    >กลับข้อมูลส่วนตัว</Link
                ><button class="btn btn-quiet" @click="router.post('/logout')">ออกจากระบบ</button>
            </div>
            <SiteFooter />
        </section>
    </main>
</template>
