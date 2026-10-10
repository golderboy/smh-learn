<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import {
    getDocument,
    GlobalWorkerOptions,
    type PDFDocumentProxy,
    type PDFDocumentLoadingTask,
} from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
GlobalWorkerOptions.workerSrc = workerUrl;
const props = defineProps<{ url: string; title: string }>();
const emit = defineEmits<{ ready: [] }>();
const container = ref<HTMLDivElement>();
const canvas = ref<HTMLCanvasElement>();
const page = ref(1);
const count = ref(0);
const busy = ref(true);
const error = ref('');
const rendered = ref(false);
let pdf: PDFDocumentProxy | undefined;
let task: PDFDocumentLoadingTask | undefined;
let observer: ResizeObserver | undefined;
let stopped = false;
let drawing = false;
let redraw = false;
async function draw() {
    if (drawing) {
        redraw = true;
        return;
    }
    if (!pdf || !canvas.value || !container.value || stopped) return;
    drawing = true;
    busy.value = true;
    try {
        const documentPage = await pdf.getPage(page.value);
        if (stopped || !canvas.value) return;
        const base = documentPage.getViewport({ scale: 1 });
        const width = Math.max(100, Math.min(container.value.clientWidth - 2, 900));
        const viewport = documentPage.getViewport({ scale: width / base.width });
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.value.width = Math.floor(viewport.width * ratio);
        canvas.value.height = Math.floor(viewport.height * ratio);
        canvas.value.style.width = `${Math.floor(viewport.width)}px`;
        canvas.value.style.height = `${Math.floor(viewport.height)}px`;
        await documentPage.render({
            canvas: canvas.value,
            viewport,
            transform: [ratio, 0, 0, ratio, 0, 0],
            annotationMode: 0,
        }).promise;
        rendered.value = true;
        emit('ready');
    } catch {
        if (!stopped) error.value = 'แสดงเอกสารไม่ได้ กรุณาเปิดหรือดาวน์โหลด PDF จากปุ่มด้านล่าง';
    } finally {
        drawing = false;
        busy.value = false;
        if (redraw && !stopped) {
            redraw = false;
            void draw();
        }
    }
}
function move(delta: number) {
    page.value = Math.max(1, Math.min(count.value, page.value + delta));
    void draw();
}
onMounted(async () => {
    try {
        task = getDocument({ url: props.url, withCredentials: true, enableXfa: false });
        pdf = await task.promise;
        if (stopped) return;
        count.value = pdf.numPages;
        await draw();
        if (container.value) {
            observer = new ResizeObserver(() => {
                void draw();
            });
            observer.observe(container.value);
        }
    } catch {
        if (!stopped) {
            error.value = 'โหลดเอกสารไม่ได้ กรุณาลองเปิด PDF จากปุ่มด้านล่าง';
            busy.value = false;
        }
    }
});
onBeforeUnmount(() => {
    stopped = true;
    observer?.disconnect();
    void task?.destroy();
});
</script>
<template>
    <section class="pdf-reader" :aria-label="`เอกสารประกอบ ${title}`" :aria-busy="busy">
        <div class="button-row pdf-toolbar">
            <button class="btn btn-secondary" :disabled="busy || page <= 1" @click="move(-1)">
                หน้าก่อน</button
            ><span v-if="count" class="identifier">หน้า {{ page }} / {{ count }}</span
            ><button class="btn btn-secondary" :disabled="busy || page >= count" @click="move(1)">
                หน้าถัดไป
            </button>
        </div>
        <p v-if="busy" class="muted" role="status">กำลังแสดงเอกสารประกอบ</p>
        <p v-if="error" class="notice error" role="alert">{{ error }}</p>
        <div ref="container" class="pdf-canvas-wrap">
            <canvas
                ref="canvas"
                class="pdf-canvas"
                role="img"
                :aria-label="`${title} หน้า ${page}`"
                :data-rendered="rendered"
            ></canvas>
        </div>
    </section>
</template>
