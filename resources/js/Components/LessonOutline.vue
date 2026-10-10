<script setup lang="ts">
import type { ModuleRecord } from '../Types/learning';
defineProps<{
    modules: ModuleRecord[];
    selectedId?: number;
    completedIds: number[];
    unlockedIds: number[];
}>();
const emit = defineEmits<{ select: [id: number] }>();
</script>
<template>
    <section v-for="module in modules" :key="module.id">
        <h2>{{ module.title }}</h2>
        <button
            v-for="lesson in module.lessons"
            :key="lesson.id"
            class="lesson-choice"
            :class="{ selected: selectedId === lesson.id }"
            :aria-current="selectedId === lesson.id ? 'step' : undefined"
            :disabled="!unlockedIds.includes(lesson.id)"
            @click="emit('select', lesson.id)"
        >
            <span>{{ lesson.title }}</span
            ><small>{{
                !unlockedIds.includes(lesson.id)
                    ? 'รอเรียนบทก่อนหน้า'
                    : completedIds.includes(lesson.id)
                      ? 'เรียนครบแล้ว'
                      : lesson.is_required
                        ? 'จำเป็น'
                        : 'เพิ่มเติม'
            }}</small>
        </button>
    </section>
</template>
