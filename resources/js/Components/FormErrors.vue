<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
const props = defineProps<{ errors: Record<string, string> }>();
const summary = ref<HTMLDivElement>();
watch(
    () => props.errors,
    async (errors) => {
        if (Object.keys(errors).length) {
            await nextTick();
            summary.value?.dispatchEvent(new CustomEvent('reveal-form-error', { bubbles: true }));
            for (let parent = summary.value?.parentElement; parent; parent = parent.parentElement) {
                if (parent instanceof HTMLDetailsElement) parent.open = true;
            }
            await nextTick();
            summary.value?.focus();
        }
    },
    { deep: true, immediate: true },
);
</script>
<template>
    <div
        v-if="Object.keys(errors).length"
        ref="summary"
        class="notice error"
        role="alert"
        tabindex="-1"
    >
        <strong>กรุณาตรวจข้อมูลต่อไปนี้</strong>
        <p v-for="(error, key) in errors" :key="key">{{ error }}</p>
    </div>
</template>
