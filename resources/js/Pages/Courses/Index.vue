<script setup lang="ts">
defineOptions({ name: 'CourseCatalogue' });
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TextField from '../../Components/TextField.vue';
import FormErrors from '../../Components/FormErrors.vue';
import Pagination from '../../Components/Pagination.vue';
import AppIcon from '../../Components/AppIcon.vue';
import { useUnsavedForms } from '../../unsaved-forms';
import type { CourseRecord } from '../../Types/learning';
import type { Paginated } from '../../Types/foundation';
defineProps<{
    courses: Paginated<CourseRecord>;
    categories: { id: number; name: string }[];
    manage: boolean;
    canManage: boolean;
}>();
const form = useForm({ category_id: '', code: '', title: '', description: '' });
useUnsavedForms(form);
</script>
<template>
    <AppLayout
        :title="manage ? 'จัดการหลักสูตร' : 'หลักสูตรทั้งหมด'"
        :subtitle="
            manage
                ? 'จัดการเวอร์ชัน บทเรียน และรอบเรียนตามหมวดที่คุณควบคุม'
                : 'เลือกหลักสูตรและรอบเรียนที่เปิดให้ลงทะเบียน'
        "
    >
        <template #actions
            ><Link class="btn btn-secondary" :href="manage ? '/courses' : '/learning'">{{
                manage ? 'ดูหลักสูตรสำหรับผู้เรียน' : 'การเรียนของฉัน'
            }}</Link
            ><Link v-if="canManage && !manage" class="btn btn-primary" href="/manage/courses"
                >จัดการหลักสูตร</Link
            ></template
        >
        <details v-if="manage" class="surface section-pad">
            <summary>เพิ่มหลักสูตร</summary>
            <form class="stack-form" @submit.prevent="form.post('/manage/courses')">
                <FormErrors :errors="form.errors" />
                <div class="form-grid">
                    <div class="field">
                        <label for="course-category">หมวดหลักสูตร</label
                        ><select id="course-category" v-model="form.category_id" required>
                            <option value="">เลือกหมวด</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    <TextField
                        v-model="form.code"
                        name="course-code"
                        label="รหัสหลักสูตร"
                        :maxlength="50"
                        required
                    /><TextField
                        v-model="form.title"
                        name="course-title"
                        label="ชื่อหลักสูตร"
                        :maxlength="200"
                        required
                    />
                    <div class="field">
                        <label for="course-description">คำอธิบาย</label
                        ><textarea
                            id="course-description"
                            v-model="form.description"
                            rows="3"
                        ></textarea>
                    </div>
                </div>
                <button class="btn btn-primary" :disabled="form.processing">สร้างฉบับร่าง</button>
            </form>
        </details>
        <section class="surface section-pad">
            <div v-if="!courses.data.length" class="empty-state">
                <strong>{{
                    manage ? 'ยังไม่มีหลักสูตรในหมวดที่คุณควบคุม' : 'ยังไม่มีหลักสูตรเผยแพร่'
                }}</strong>
            </div>
            <div v-else-if="!manage" class="catalogue-grid">
                <article v-for="course in courses.data" :key="course.id" class="catalogue-card">
                    <div class="catalogue-art" aria-hidden="true">
                        <img
                            :src="'/images/cobalt-hero.webp'"
                            alt=""
                            width="240"
                            height="160"
                            loading="lazy"
                        /><span>{{ course.category.name }}</span>
                    </div>
                    <div class="catalogue-copy">
                        <p class="eyebrow">{{ course.code }}</p>
                        <h2>{{ course.title }}</h2>
                        <p class="muted">
                            {{ course.description || 'เลือกดูรอบเรียนและเงื่อนไขก่อนสมัคร' }}
                        </p>
                        <Link class="btn btn-primary" :href="`/courses/${course.id}`"
                            >ดูรอบเรียน<AppIcon name="arrow"
                        /></Link>
                    </div>
                </article>
            </div>
            <div v-else class="table-wrap">
                <table v-responsive-table>
                    <thead>
                        <tr>
                            <th>หลักสูตร</th>
                            <th>หมวด</th>
                            <th v-if="manage">สถานะ</th>
                            <th>การดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="course in courses.data" :key="course.id">
                            <td>
                                <strong>{{ course.title }}</strong
                                ><small class="muted block">{{ course.code }}</small>
                            </td>
                            <td>{{ course.category.name }}</td>
                            <td v-if="manage">
                                {{
                                    {
                                        draft: 'ฉบับร่าง',
                                        published: 'เผยแพร่แล้ว',
                                        archived: 'เก็บเข้าประวัติ',
                                    }[course.status]
                                }}
                            </td>
                            <td>
                                <Link
                                    class="btn btn-secondary"
                                    :href="
                                        manage
                                            ? `/manage/courses/${course.id}/edit`
                                            : `/courses/${course.id}`
                                    "
                                    >{{ manage ? 'จัดการ' : 'ดูรอบเรียน' }}</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination
                :links="courses.links"
                :total="courses.total"
                :from="courses.from"
                :to="courses.to"
            />
        </section>
    </AppLayout>
</template>
