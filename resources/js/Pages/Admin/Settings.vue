<script setup lang="ts">
defineOptions({ name: 'SystemSettingsPage' });
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import AppIcon from '../../Components/AppIcon.vue';
import type { BrandingSettings } from '../../Types/foundation';
import { useUnsavedForms } from '../../unsaved-forms';
const props = defineProps<{
    settings: BrandingSettings & { organization_name: string; session_timeout_minutes: number };
}>();
const initial = () => ({
    ...props.settings,
    _method: 'put',
    logo_file: null as File | null,
    banner_file: null as File | null,
    favicon_file: null as File | null,
    remove_logo: false,
    remove_banner: false,
    remove_favicon: false,
});
const form = useForm(initial());
useUnsavedForms(form);
const preview = ref<Record<string, string>>({});
const inputKey = ref(0);
const kinds = [
    {
        key: 'logo',
        title: 'โลโก้ / ไอคอนเว็บไซต์',
        help: 'PNG/JPG/WebP ไม่เกิน 2 MB · ภาพโปร่งใสเหมาะกับ Sidebar',
    },
    {
        key: 'banner',
        title: 'ภาพแบนเนอร์',
        help: 'PNG/JPG/WebP ไม่เกิน 5 MB · แนะนำภาพแนวนอนหรือภาพพื้นโปร่งใส',
    },
    {
        key: 'favicon',
        title: 'ไอคอนแท็บเบราว์เซอร์',
        help: 'PNG/JPG/WebP ไม่เกิน 2 MB · แนะนำภาพสี่เหลี่ยมจัตุรัส',
    },
] as const;
const release = () => {
    Object.values(preview.value).forEach(URL.revokeObjectURL);
    preview.value = {};
};
onBeforeUnmount(release);
function choose(event: Event, kind: 'logo' | 'banner' | 'favicon') {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (preview.value[kind]) URL.revokeObjectURL(preview.value[kind]);
    form[`${kind}_file`] = file;
    form[`remove_${kind}`] = false;
    preview.value[kind] = file ? URL.createObjectURL(file) : '';
}
function restoreDefault(kind: 'logo' | 'banner' | 'favicon') {
    form[`${kind}_file`] = null;
    if (preview.value[kind]) URL.revokeObjectURL(preview.value[kind]);
    preview.value[kind] = '';
    const input = document.getElementById(`${kind}_file`) as HTMLInputElement | null;
    if (input) input.value = '';
}
const logo = computed(() =>
    form.remove_logo ? null : preview.value.logo || props.settings.logo_url,
);
const banner = computed(() =>
    form.remove_banner
        ? '/images/cobalt-hero.webp'
        : preview.value.banner || props.settings.banner_url || '/images/cobalt-hero.webp',
);
function submit() {
    form.post('/admin/settings', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            release();
            inputKey.value++;
            form.defaults(initial());
            form.reset();
        },
    });
}
</script>
<template>
    <AppLayout
        title="ตั้งค่าระบบ"
        subtitle="ปรับชื่อ ภาพ และข้อความให้เป็นพื้นที่เรียนรู้ขององค์กรคุณ"
    >
        <div class="branding-settings-grid">
            <form class="form-stack" @submit.prevent="submit">
                <FormErrors :errors="form.errors" />
                <section class="surface section-pad">
                    <h2 class="section-title">
                        <AppIcon name="settings" /> ชื่อและข้อความเว็บไซต์
                    </h2>
                    <div class="form-grid">
                        <TextField
                            v-model="form.product_name"
                            name="product_name"
                            label="ชื่อระบบ"
                            :maxlength="100"
                            :error="form.errors.product_name"
                            required
                        />
                        <TextField
                            v-model="form.organization_name"
                            name="organization_name"
                            label="ชื่อองค์กร"
                            :maxlength="150"
                            :error="form.errors.organization_name"
                            required
                        />
                        <TextField
                            v-model="form.header_title"
                            name="header_title"
                            label="ข้อความหัว Dashboard"
                            :maxlength="100"
                            :error="form.errors.header_title"
                        />
                        <TextField
                            v-model="form.header_description"
                            name="header_description"
                            label="คำอธิบายส่วนหัว"
                            :maxlength="250"
                            :error="form.errors.header_description"
                        />
                        <TextField
                            v-model="form.banner_text"
                            name="banner_text"
                            label="ข้อความประกอบแบนเนอร์"
                            :maxlength="250"
                            :error="form.errors.banner_text"
                        />
                        <TextField
                            v-model="form.footer_text"
                            name="footer_text"
                            label="ข้อความท้ายเว็บไซต์"
                            :maxlength="250"
                            :error="form.errors.footer_text"
                        />
                        <TextField
                            v-model="form.login_heading"
                            name="login_heading"
                            label="หัวข้อหน้าเข้าสู่ระบบ"
                            :maxlength="100"
                            :error="form.errors.login_heading"
                        />
                        <TextField
                            v-model="form.login_description"
                            name="login_description"
                            label="คำอธิบายหน้าเข้าสู่ระบบ"
                            :maxlength="500"
                            :error="form.errors.login_description"
                        />
                    </div>
                </section>
                <section class="surface section-pad">
                    <h2 class="section-title"><AppIcon name="book" /> ภาพและไอคอน</h2>
                    <p class="helper">
                        ไม่ระบุภาพจะใช้ภาพมาตรฐานของระบบ รองรับภาพไม่เกิน 16 ล้านพิกเซล ไม่รองรับ
                        SVG
                    </p>
                    <div v-for="kind in kinds" :key="kind.key" class="branding-upload">
                        <label :for="`${kind.key}_file`">{{ kind.title }}</label>
                        <input
                            :id="`${kind.key}_file`"
                            :key="`${kind.key}-${inputKey}`"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            :aria-invalid="!!form.errors[`${kind.key}_file`]"
                            @change="choose($event, kind.key)"
                        />
                        <p class="helper">{{ kind.help }}</p>
                        <p v-if="form[`${kind.key}_file`]" class="helper">
                            {{ form[`${kind.key}_file`]?.name }} ·
                            {{ ((form[`${kind.key}_file`]?.size || 0) / 1048576).toFixed(2) }} MB
                        </p>
                        <p v-if="form.errors[`${kind.key}_file`]" class="field-error" role="alert">
                            {{ form.errors[`${kind.key}_file`] }}
                        </p>
                        <label
                            v-if="
                                props.settings[`${kind.key}_url`] ||
                                form[`${kind.key}_file`] ||
                                form[`remove_${kind.key}`]
                            "
                            class="checkbox-label"
                            ><input
                                v-model="form[`remove_${kind.key}`]"
                                type="checkbox"
                                @change="restoreDefault(kind.key)"
                            />
                            กลับไปใช้ภาพมาตรฐาน</label
                        >
                    </div>
                    <label class="checkbox-label"
                        ><input v-model="form.show_sidebar_art" type="checkbox" />
                        แสดงภาพประกอบส่วนท้ายเมนูบนหน้าจอขนาดใหญ่</label
                    >
                </section>
                <section class="surface section-pad">
                    <h2 class="section-title"><AppIcon name="user" /> ช่องทางติดต่อ</h2>
                    <div class="form-grid">
                        <TextField
                            v-model="form.support_email"
                            name="support_email"
                            label="อีเมลผู้ดูแลระบบ"
                            type="email"
                            :maxlength="150"
                            :error="form.errors.support_email"
                        />
                        <TextField
                            v-model="form.support_phone"
                            name="support_phone"
                            label="โทรศัพท์ / เบอร์ภายใน"
                            :maxlength="50"
                            :error="form.errors.support_phone"
                        />
                        <TextField
                            v-model="form.support_url"
                            name="support_url"
                            label="ลิงก์ติดต่อหรือคู่มือใช้งาน"
                            :maxlength="500"
                            :error="form.errors.support_url"
                        />
                    </div>
                    <p class="helper">
                        ช่องทางติดต่อแสดงที่ท้ายเว็บไซต์และหน้าเข้าสู่ระบบ ใช้ลิงก์ http:// หรือ
                        https:// เท่านั้น
                    </p>
                </section>
                <section class="surface section-pad">
                    <h2 class="section-title"><AppIcon name="shield" /> การเข้าใช้งาน</h2>
                    <div class="field">
                        <label for="session-minutes">หมดอายุเมื่อไม่ใช้งาน (นาที)</label
                        ><input
                            id="session-minutes"
                            v-model.number="form.session_timeout_minutes"
                            type="number"
                            min="15"
                            max="240"
                            required
                        />
                        <p class="helper">กำหนดได้ 15–240 นาที ตามนโยบายเดิมของระบบ</p>
                        <p
                            v-if="form.errors.session_timeout_minutes"
                            class="field-error"
                            role="alert"
                        >
                            {{ form.errors.session_timeout_minutes }}
                        </p>
                    </div>
                </section>
                <p v-if="form.progress" class="notice" role="status">
                    กำลังอัปโหลด {{ form.progress.percentage }}%
                </p>
                <button class="btn btn-primary" :disabled="form.processing">
                    <AppIcon name="check" />
                    {{ form.processing ? 'กำลังบันทึก…' : 'บันทึกการตั้งค่า' }}
                </button>
            </form>
            <aside class="branding-preview" aria-label="ตัวอย่างการตั้งค่า">
                <h2>ตัวอย่างก่อนบันทึก</h2>
                <div class="branding-preview-nav">
                    <img v-if="logo" :src="logo" alt="" /><AppIcon
                        v-else
                        name="book"
                        :size="40"
                    /><strong
                        >{{ form.product_name }}<small>{{ form.organization_name }}</small></strong
                    >
                </div>
                <div class="branding-preview-hero">
                    <h3>{{ form.header_title }}</h3>
                    <p>{{ form.header_description }}</p>
                    <img :src="banner" alt="" />
                    <p>{{ form.banner_text }}</p>
                </div>
                <p class="helper">
                    แสดงตัวอย่างภาพและข้อความ การตั้งค่าจะมีผลกับทุกคนเมื่อบันทึกสำเร็จ
                </p>
            </aside>
        </div>
    </AppLayout>
</template>
