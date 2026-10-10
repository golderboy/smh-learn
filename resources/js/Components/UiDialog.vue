<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
const props = withDefaults(defineProps<{ open: boolean; title: string; drawer?: boolean }>(), {
    drawer: false,
});
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const dialog = ref<HTMLDialogElement>();
let trigger: HTMLElement | null = null;
let previousOverflow = '';
watch(
    () => props.open,
    async (open) => {
        await nextTick();
        if (open && dialog.value && !dialog.value.open) {
            trigger = document.activeElement as HTMLElement;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            dialog.value.showModal();
        } else if (!open && dialog.value?.open) dialog.value.close();
    },
    { immediate: true },
);
function closed() {
    document.body.style.overflow = previousOverflow;
    emit('update:open', false);
    if (trigger?.isConnected) trigger.focus();
}
onBeforeUnmount(() => {
    if (dialog.value?.open) {
        dialog.value.close();
        document.body.style.overflow = previousOverflow;
    }
});
</script>
<template>
    <dialog
        ref="dialog"
        class="ui-dialog"
        :class="{ 'ui-drawer': drawer }"
        :aria-label="title"
        @close="closed"
        @cancel="emit('update:open', false)"
    >
        <header class="dialog-heading">
            <h2>{{ title }}</h2>
            <button
                type="button"
                class="btn btn-quiet"
                aria-label="ปิดหน้าต่าง"
                @click="emit('update:open', false)"
            >
                ปิด
            </button>
        </header>
        <div class="dialog-content"><slot /></div>
    </dialog>
</template>
