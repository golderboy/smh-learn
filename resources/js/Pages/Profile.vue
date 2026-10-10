<script setup lang="ts">
defineOptions({ name: 'UserProfilePage' });
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import TextField from '../Components/TextField.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { SharedProps } from '../Types/foundation';
const props = defineProps<{
    profile: {
        username: string;
        employee_code: string;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        employment_status: string | null;
        is_root_admin: boolean;
    };
}>();
const page = usePage<SharedProps>();
const form = useForm({ email: props.profile.email ?? '', phone: props.profile.phone ?? '' });
useUnsavedForms(form);
</script>
<template>
    <AppLayout title="ข้อมูลส่วนตัว" subtitle="ตรวจข้อมูลบัญชีและปรับช่องทางติดต่อของคุณ"
        ><div class="dashboard-columns">
            <section class="surface section-pad">
                <h2>ข้อมูลบุคลากร</h2>
                <dl class="detail-list">
                    <div>
                        <dt>เลขประจำตัว</dt>
                        <dd class="identifier">{{ profile.username }}</dd>
                    </div>
                    <div>
                        <dt>รหัสบุคลากร</dt>
                        <dd class="identifier">{{ profile.employee_code }}</dd>
                    </div>
                    <div>
                        <dt>ชื่อ–นามสกุล</dt>
                        <dd>{{ profile.first_name }} {{ profile.last_name }}</dd>
                    </div>
                    <div>
                        <dt>แผนก</dt>
                        <dd>{{ page.props.auth.user?.department ?? 'ยังไม่ได้กำหนด' }}</dd>
                    </div>
                    <div>
                        <dt>ตำแหน่ง</dt>
                        <dd>{{ page.props.auth.user?.position ?? 'ยังไม่ได้กำหนด' }}</dd>
                    </div>
                    <div>
                        <dt>สถานะการจ้างงาน</dt>
                        <dd>{{ profile.employment_status ?? 'ยังไม่ได้กำหนด' }}</dd>
                    </div>
                </dl>
                <p class="helper">หากข้อมูลบุคลากรไม่ถูกต้อง กรุณาติดต่อผู้ดูแลระบบ</p>
            </section>
            <section class="surface section-pad">
                <h2>ช่องทางติดต่อ</h2>
                <p v-if="profile.is_root_admin" class="info-banner">
                    บัญชีแอดมินตั้งต้นได้รับการป้องกัน ข้อมูลบัญชีนี้เป็นแบบอ่านอย่างเดียว
                    เจ้าของบัญชีเปลี่ยนรหัสผ่านของตัวเองได้จากปุ่มด้านล่าง
                </p>
                <form class="form-stack" @submit.prevent="form.put('/profile')">
                    <TextField
                        v-model="form.email"
                        name="email"
                        label="อีเมล"
                        type="email"
                        autocomplete="email"
                        :error="form.errors.email"
                        :readonly="profile.is_root_admin"
                    /><TextField
                        v-model="form.phone"
                        name="phone"
                        label="เบอร์โทรศัพท์"
                        inputmode="tel"
                        :maxlength="30"
                        autocomplete="tel"
                        :error="form.errors.phone"
                        :readonly="profile.is_root_admin"
                    /><button
                        v-if="!profile.is_root_admin"
                        class="btn btn-primary"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'กำลังบันทึก…' : 'บันทึกช่องทางติดต่อ' }}
                    </button>
                </form>
                <hr />
                <h3>รหัสผ่าน</h3>
                <p class="muted">เปลี่ยนรหัสผ่านสำหรับการเข้าสู่ระบบด้วยบัญชีของคุณ</p>
                <Link class="btn btn-secondary" href="/password/change">เปลี่ยนรหัสผ่าน</Link>
            </section>
        </div></AppLayout
    >
</template>
