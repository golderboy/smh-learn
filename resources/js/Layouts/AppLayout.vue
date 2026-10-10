<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppNavigation from '../Components/AppNavigation.vue';
import UiDialog from '../Components/UiDialog.vue';
import BrandMark from '../Components/BrandMark.vue';
import AppIcon from '../Components/AppIcon.vue';
import SiteFooter from '../Components/SiteFooter.vue';
import type { SharedProps } from '../Types/foundation';
const props = withDefaults(
    defineProps<{ title: string; subtitle?: string; mode?: 'general' | 'learning' | 'exam' }>(),
    { mode: 'general', subtitle: '' },
);
const page = usePage<SharedProps>();
const emit = defineEmits<{ 'reveal-form-error': [event: Event] }>();
const menuOpen = ref(false);
const accountOpen = ref(false);
const compact = computed(() => props.mode !== 'general');
const dashboard = computed(() => page.url.split('?')[0] === '/dashboard');
const section = computed(() => {
    const url = page.url;
    if (url.startsWith('/attempts')) return { name: 'การเรียนของฉัน', href: '/learning' };
    if (url.startsWith('/manage/') || url.startsWith('/versions/'))
        return { name: 'จัดการหลักสูตร', href: '/manage/courses' };
    if (url.startsWith('/courses/')) return { name: 'หลักสูตรทั้งหมด', href: '/courses' };
    if (url.startsWith('/learning/')) return { name: 'การเรียนของฉัน', href: '/learning' };
    if (url.startsWith('/categories/')) return { name: 'หมวดหลักสูตร', href: '/categories' };
    if (url.startsWith('/admin/users/')) return { name: 'บัญชีบุคลากร', href: '/admin/users' };
    return null;
});
</script>
<template>
    <Head :title="`${props.title} | ${page.props.app.name}`"
        ><link rel="icon" :href="page.props.app.branding?.favicon_url || '/favicon.svg'"
    /></Head>
    <a class="skip-link" href="#main-content">ข้ามไปเนื้อหา</a>
    <div class="app-shell" :class="{ 'compact-shell': compact, 'exam-shell': mode === 'exam' }">
        <aside v-if="!compact" class="sidebar desktop-sidebar">
            <Link class="brand" href="/dashboard"
                ><BrandMark /><span
                    >{{ page.props.app.name }}<small>{{ page.props.app.organization }}</small></span
                ></Link
            >
            <AppNavigation />
            <div v-if="page.props.app.branding?.show_sidebar_art" class="sidebar-art">
                <img
                    :src="'/images/cobalt-learner.webp'"
                    alt=""
                    width="220"
                    height="260"
                    loading="lazy"
                />
                <p>{{ page.props.app.branding.banner_text }}</p>
            </div>
            <div class="sidebar-footer">
                <strong>{{ page.props.app.name }}</strong
                ><span>{{ page.props.app.branding?.footer_text }}</span>
            </div>
        </aside>
        <div class="app-content">
            <header class="topbar" :class="{ 'dashboard-topbar': dashboard }">
                <div class="topbar-context">
                    <button
                        class="btn btn-quiet menu-toggle"
                        :class="{ 'compact-menu-toggle': compact }"
                        aria-label="เปิดเมนู"
                        aria-controls="main-sidebar"
                        :aria-expanded="menuOpen"
                        @click="menuOpen = true"
                    >
                        <AppIcon name="menu" /><span>เมนู</span>
                    </button>
                    <Link v-if="compact" class="brand compact-brand" href="/learning"
                        ><BrandMark /><span>{{
                            mode === 'exam' ? 'พื้นที่ทำข้อสอบ' : 'พื้นที่การเรียน'
                        }}</span></Link
                    >
                    <span v-else v-show="!dashboard" class="topbar-label"
                        >{{ page.props.app.organization
                        }}<small>{{ page.props.app.branding?.login_description }}</small></span
                    >
                </div>
                <div class="topbar-actions">
                    <button
                        class="btn btn-quiet account-trigger"
                        aria-label="เปิดบัญชีของฉัน"
                        :aria-expanded="accountOpen"
                        @click="accountOpen = true"
                    >
                        <span class="account-avatar" aria-hidden="true">{{
                            page.props.auth.user?.display_name.slice(0, 1)
                        }}</span
                        ><span
                            >{{ page.props.auth.user?.display_name
                            }}<small>บัญชีของฉัน</small></span
                        >
                    </button>
                </div>
            </header>
            <main
                id="main-content"
                class="workspace"
                tabindex="-1"
                @reveal-form-error="emit('reveal-form-error', $event)"
            >
                <div v-if="page.props.flash.message" class="notice success" role="status">
                    {{ page.props.flash.message }}
                </div>
                <nav v-if="section" class="breadcrumbs" aria-label="เส้นทางหน้า">
                    <Link :href="section.href">{{ section.name }}</Link
                    ><span aria-hidden="true">/</span><span>{{ title }}</span>
                </nav>
                <div
                    class="page-heading"
                    :class="{ 'dashboard-heading': page.url.split('?')[0] === '/dashboard' }"
                >
                    <div>
                        <h1 v-if="page.url.split('?')[0] === '/dashboard'">
                            <span class="sr-only">ภาพรวม · </span
                            >{{ page.props.app.branding?.header_title || 'สวัสดี' }}
                        </h1>
                        <h1 v-else>{{ title }}</h1>
                        <p v-if="subtitle" class="muted">
                            {{
                                page.url.split('?')[0] === '/dashboard'
                                    ? page.props.app.branding?.header_description
                                    : subtitle
                            }}
                        </p>
                    </div>
                    <div class="page-actions">
                        <slot name="heading-actions" /><slot name="actions" />
                    </div>
                </div>
                <slot />
                <SiteFooter />
            </main>
        </div>
    </div>
    <UiDialog v-model:open="menuOpen" title="เมนู" drawer
        ><div id="main-sidebar">
            <Link class="brand" href="/dashboard" @click="menuOpen = false"
                ><BrandMark />{{ page.props.app.name }}</Link
            ><AppNavigation @navigate="menuOpen = false" /></div
    ></UiDialog>
    <UiDialog v-model:open="accountOpen" title="บัญชีของฉัน">
        <p class="account-name">{{ page.props.auth.user?.display_name }}</p>
        <p class="muted">{{ page.props.auth.user?.roles.map((role) => role.name).join(' · ') }}</p>
        <div class="account-actions">
            <Link class="btn btn-secondary" href="/profile" @click="accountOpen = false"
                >ข้อมูลส่วนตัว</Link
            ><Link class="btn btn-secondary" href="/password/change" @click="accountOpen = false"
                >เปลี่ยนรหัสผ่าน</Link
            ><button class="btn btn-quiet" @click="router.post('/logout')">ออกจากระบบ</button>
        </div>
    </UiDialog>
</template>
