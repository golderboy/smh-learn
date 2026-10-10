<script setup lang="ts">
defineOptions({ name: 'LoginPage' });
import { Head, useForm, usePage } from '@inertiajs/vue3';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import type { SharedProps } from '../../Types/foundation';
import BrandMark from '../../Components/BrandMark.vue';
import SiteFooter from '../../Components/SiteFooter.vue';
defineProps<{ thaidAvailable: boolean }>();
const page = usePage<SharedProps>();
const form = useForm({ username: '', password: '' });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>
<template>
    <Head :title="`เข้าสู่ระบบ | ${page.props.app.name}`"
        ><link rel="icon" :href="page.props.app.branding?.favicon_url || '/favicon.svg'"
    /></Head>
    <main class="auth-shell">
        <section class="auth-story">
            <div class="brand">
                <BrandMark /><span
                    >{{ page.props.app.name }}<small>{{ page.props.app.organization }}</small></span
                >
            </div>
            <h2>{{ page.props.app.organization }}</h2>
            <p>{{ page.props.app.branding?.login_description }}</p>
            <img
                :src="page.props.app.branding?.banner_url || '/images/cobalt-hero.webp'"
                alt=""
                width="520"
                height="347"
            />
            <p>{{ page.props.app.branding?.banner_text }}</p>
        </section>
        <section class="auth-panel">
            <div class="auth-form-wrap">
                <div class="auth-brand">
                    <BrandMark />
                    <div>
                        <strong>{{ page.props.app.name }}</strong
                        ><small>{{ page.props.app.organization }}</small>
                    </div>
                </div>
                <h1>{{ page.props.app.branding?.login_heading || 'เข้าสู่ระบบ' }}</h1>
                <p class="muted">ใช้เลขประจำตัวหรือชื่อบัญชีผู้ดูแลระบบ และรหัสผ่านของคุณ</p>
                <div v-if="page.props.flash.message" class="notice" role="status">
                    {{ page.props.flash.message }}
                </div>
                <form class="form-stack" @submit.prevent="submit">
                    <FormErrors :errors="form.errors" />
                    <TextField
                        v-model="form.username"
                        name="username"
                        label="เลขบัตรประชาชน / เลขประจำตัว"
                        :maxlength="15"
                        autocomplete="username"
                        :error="form.errors.username"
                        required
                    />
                    <TextField
                        v-model="form.password"
                        name="password"
                        label="รหัสผ่าน"
                        type="password"
                        autocomplete="current-password"
                        :error="form.errors.password"
                        required
                    />
                    <button
                        class="btn btn-primary full-width"
                        type="submit"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'กำลังเข้าสู่ระบบ…' : 'เข้าสู่ระบบ' }}
                    </button>
                </form>
                <div class="auth-divider"><span>ช่องทางยืนยันตัวตน</span></div>
                <button class="btn btn-secondary full-width" disabled>เข้าสู่ระบบด้วย ThaiD</button>
                <p class="helper">ThaiD ยังไม่เปิดให้บริการในขณะนี้</p>
                <p class="auth-help">หากลืมรหัสผ่านหรือยังไม่มีบัญชี กรุณาติดต่อผู้ดูแลระบบ</p>
                <SiteFooter />
            </div>
        </section>
    </main>
</template>
