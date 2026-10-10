<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TextField from './TextField.vue';
import FormErrors from './FormErrors.vue';
import UiDialog from './UiDialog.vue';
import { useUnsavedForms } from '../unsaved-forms';
import type { LessonRecord } from '../Types/learning';
const props = defineProps<{ lesson: LessonRecord; editable: boolean; expanded?: boolean }>();
const metadata = useForm({
    lesson_id: props.lesson.id,
    title: props.lesson.title,
    description: props.lesson.description ?? '',
    sort_order: String(props.lesson.sort_order),
    is_required: props.lesson.is_required,
    status: props.lesson.status,
});
const content = useForm({
    title: '',
    content_type: 'document',
    video_source: 'upload',
    duration_seconds: '',
    file: null as File | null,
    body: '',
    external_url: '',
    is_required: true,
    sort_order: String(props.lesson.contents.length + 1),
});
useUnsavedForms(metadata, content);
const removeOpen = ref(false);
const removeTarget = ref<{ kind: 'lesson' | 'content'; id: number; title: string }>();
const removal = useForm({});
function remove(id: number) {
    removeTarget.value = {
        kind: 'content',
        id,
        title: props.lesson.contents.find((item) => item.id === id)?.title ?? 'เนื้อหา',
    };
    removeOpen.value = true;
}
function removeLesson() {
    removeTarget.value = { kind: 'lesson', id: props.lesson.id, title: props.lesson.title };
    removeOpen.value = true;
}
function confirmRemove() {
    if (!removeTarget.value) return;
    removal.delete(
        `/${removeTarget.value.kind === 'lesson' ? 'lessons' : 'contents'}/${removeTarget.value.id}`,
        {
            onSuccess: () => {
                removeOpen.value = false;
            },
        },
    );
}
</script>
<template>
    <details
        class="lesson-editor"
        :data-lesson-id="lesson.id"
        :open="expanded"
        :class="{ 'selected-editor': expanded }"
    >
        <summary>
            {{ lesson.title }}
            <span class="muted"
                >{{ lesson.is_required ? 'จำเป็น' : 'เพิ่มเติม' }} ·
                {{ lesson.contents.length }} รายการ</span
            >
        </summary>
        <div class="section-pad">
            <form
                v-if="editable"
                class="stack-form"
                @submit.prevent="
                    metadata.post(
                        `/modules/${(lesson as LessonRecord & { module_id: number }).module_id}/lessons`,
                    )
                "
            >
                <FormErrors :errors="metadata.errors" />
                <div class="form-grid">
                    <TextField
                        v-model="metadata.title"
                        :name="`lesson-title-${lesson.id}`"
                        label="ชื่อบทเรียน"
                        required
                    /><TextField
                        v-model="metadata.sort_order"
                        :name="`lesson-order-${lesson.id}`"
                        label="ลำดับบท"
                        type="number"
                        required
                    />
                    <div class="field">
                        <label :for="`lesson-description-${lesson.id}`">คำอธิบายบท</label
                        ><textarea
                            :id="`lesson-description-${lesson.id}`"
                            v-model="metadata.description"
                            rows="2"
                        ></textarea>
                    </div>
                    <div class="field">
                        <label :for="`lesson-status-${lesson.id}`">สถานะบท</label
                        ><select :id="`lesson-status-${lesson.id}`" v-model="metadata.status">
                            <option value="active">เปิดใช้</option>
                            <option value="inactive">ปิดใช้</option>
                        </select>
                    </div>
                </div>
                <label class="checkbox-label"
                    ><input v-model="metadata.is_required" type="checkbox" />บทเรียนที่จำเป็น</label
                ><button class="btn btn-secondary" :disabled="metadata.processing">
                    บันทึกบทเรียน
                </button>
                <button type="button" class="btn btn-quiet danger-text" @click="removeLesson">
                    นำบทเรียนออก
                </button>
            </form>
            <ul class="content-list">
                <li v-for="item in lesson.contents" :key="item.id">
                    <div>
                        <strong>{{ item.title }}</strong
                        ><small
                            >{{
                                {
                                    video: 'วิดีโอ',
                                    document: 'เอกสาร PDF',
                                    image: 'ภาพประกอบ',
                                    article: 'บทความ',
                                    link: 'ลิงก์ภายนอก',
                                }[item.content_type]
                            }}
                            · {{ item.is_required ? 'จำเป็น' : 'เพิ่มเติม'
                            }}<span v-if="item.duration_seconds">
                                · {{ Math.ceil(item.duration_seconds / 60) }} นาที</span
                            ></small
                        >
                    </div>
                    <a
                        v-if="['video', 'document', 'image'].includes(item.content_type)"
                        class="text-link"
                        :href="item.external_url || `/media/${item.id}`"
                        target="_blank"
                        rel="noopener"
                        >เปิดไฟล์</a
                    ><button
                        v-if="editable"
                        class="btn btn-quiet danger-text"
                        type="button"
                        @click="remove(item.id)"
                    >
                        นำออก
                    </button>
                </li>
            </ul>
            <details v-if="editable">
                <summary>แนบเนื้อหาและเอกสาร</summary>
                <form
                    class="stack-form"
                    @submit.prevent="
                        content.post(`/lessons/${lesson.id}/contents`, {
                            forceFormData: true,
                            onSuccess: () => {
                                content.reset();
                                content.sort_order = String(lesson.contents.length + 1);
                                content.defaults('sort_order', content.sort_order);
                            },
                        })
                    "
                >
                    <FormErrors :errors="content.errors" />
                    <div class="form-grid">
                        <TextField
                            v-model="content.title"
                            :name="`content-title-${lesson.id}`"
                            label="ชื่อเนื้อหา"
                            required
                        />
                        <div class="field">
                            <label :for="`content-type-${lesson.id}`">ชนิดเนื้อหา</label
                            ><select
                                :id="`content-type-${lesson.id}`"
                                v-model="content.content_type"
                            >
                                <option value="document">เอกสาร PDF</option>
                                <option value="video">วิดีโอ (ไฟล์ MP4 / ลิงก์)</option>
                                <option value="image">ภาพ JPG / PNG / WebP</option>
                                <option value="article">บทความ</option>
                                <option value="link">ลิงก์ภายนอก</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="content.content_type === 'video'" class="field">
                        <label :for="`video-source-${lesson.id}`">แหล่งวิดีโอ</label>
                        <select :id="`video-source-${lesson.id}`" v-model="content.video_source">
                            <option value="upload">อัปโหลดไฟล์ MP4</option>
                            <option value="youtube">ลิงก์ YouTube</option>
                            <option value="remote">URL ไฟล์ .mp4 โดยตรง</option>
                        </select>
                    </div>
                    <TextField
                        v-if="content.content_type === 'video' && content.video_source !== 'upload'"
                        v-model="content.duration_seconds"
                        :name="`video-duration-${lesson.id}`"
                        label="ความยาววิดีโอจริง (วินาที ปัดขึ้น)"
                        type="number"
                        required
                    />
                    <p
                        v-if="content.content_type === 'video' && content.video_source !== 'upload'"
                        class="muted"
                    >
                        ตรวจสอบคลิปและระบุความยาวจริงก่อนเผยแพร่
                        ตัวเล่นจะตรวจว่าความยาวตรงกันก่อนบันทึกการรับชม
                        ลิงก์ต้องเปิดเล่นและอนุญาตการฝังได้
                    </p>
                    <div
                        v-if="
                            ['document', 'image'].includes(content.content_type) ||
                            (content.content_type === 'video' && content.video_source === 'upload')
                        "
                        class="field"
                    >
                        <label :for="`content-file-${lesson.id}`">เลือกไฟล์ (ไม่เกิน 100 MB)</label
                        ><input
                            :id="`content-file-${lesson.id}`"
                            type="file"
                            :accept="
                                content.content_type === 'video'
                                    ? '.mp4'
                                    : content.content_type === 'image'
                                      ? '.jpg,.jpeg,.png,.webp'
                                      : '.pdf'
                            "
                            required
                            @change="
                                content.file =
                                    ($event.target as HTMLInputElement).files?.[0] ?? null
                            "
                        />
                    </div>
                    <div v-if="content.content_type === 'article'" class="field">
                        <label :for="`content-body-${lesson.id}`"
                            >บทความ (รองรับหัวข้อ ย่อหน้า และรายการ HTML)</label
                        ><textarea
                            :id="`content-body-${lesson.id}`"
                            v-model="content.body"
                            rows="5"
                            required
                        ></textarea>
                    </div>
                    <TextField
                        v-if="
                            content.content_type === 'link' ||
                            (content.content_type === 'video' && content.video_source !== 'upload')
                        "
                        v-model="content.external_url"
                        :name="`content-url-${lesson.id}`"
                        label="ลิงก์ HTTPS"
                        type="url"
                        required
                    /><TextField
                        v-model="content.sort_order"
                        :name="`content-order-${lesson.id}`"
                        label="ลำดับเนื้อหา"
                        type="number"
                        required
                    /><label class="checkbox-label"
                        ><input
                            v-model="content.is_required"
                            type="checkbox"
                        />ต้องเรียนเนื้อหานี้เพื่อจบบท</label
                    >
                    <p v-if="content.file" class="muted">
                        {{ content.file.name }} ·
                        {{ (content.file.size / 1024 / 1024).toFixed(2) }} MB
                    </p>
                    <p v-if="content.progress" role="status">
                        อัปโหลด {{ content.progress.percentage }}%
                    </p>
                    <button class="btn btn-primary" :disabled="content.processing">
                        แนบเนื้อหา
                    </button>
                </form>
            </details>
        </div>
        <UiDialog v-model:open="removeOpen" title="นำออกจากฉบับร่าง"
            ><p>{{ removeTarget?.title }}</p>
            <p class="muted">
                {{
                    removeTarget?.kind === 'lesson'
                        ? 'บทเรียนและเนื้อหาในบทจะถูกนำออก ข้อมูลที่ยังไม่บันทึกของบทนี้จะถูกทิ้ง'
                        : 'นำเนื้อหานี้ออกจากบทเรียนฉบับร่าง'
                }}
            </p>
            <FormErrors :errors="removal.errors" />
            <div class="button-row">
                <button
                    class="btn btn-danger"
                    :disabled="removal.processing"
                    @click="confirmRemove"
                >
                    ยืนยันนำออก</button
                ><button class="btn btn-secondary" @click="removeOpen = false">ยกเลิก</button>
            </div></UiDialog
        >
    </details>
</template>
