<script setup lang="ts">
import { ref } from 'vue';
const reveal = ref(false);
withDefaults(
    defineProps<{
        name: string;
        label: string;
        modelValue: string;
        error?: string;
        type?: string;
        maxlength?: number;
        inputmode?: 'numeric' | 'text' | 'email' | 'tel';
        autocomplete?: string;
        required?: boolean;
        readonly?: boolean;
    }>(),
    {
        type: 'text',
        autocomplete: 'off',
        error: '',
        maxlength: undefined,
        inputmode: 'text',
        required: false,
        readonly: false,
    },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>
<template>
    <div class="field">
        <label :for="`field-${name}`"
            >{{ label }}
            <span v-if="required" aria-hidden="true" class="required-mark">*</span></label
        >
        <div :class="{ 'password-control': type === 'password' }">
            <input
                :id="`field-${name}`"
                :name="name"
                :type="type === 'password' && reveal ? 'text' : type"
                :value="modelValue"
                :maxlength="maxlength"
                :inputmode="inputmode"
                :autocomplete="autocomplete"
                :required="required"
                :readonly="readonly"
                :aria-invalid="!!error"
                :aria-describedby="error ? `error-${name}` : undefined"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
            <button
                v-if="type === 'password'"
                type="button"
                class="btn btn-quiet"
                :aria-label="`${reveal ? 'ซ่อน' : 'แสดง'}${label}`"
                :aria-pressed="reveal"
                @click="reveal = !reveal"
            >
                {{ reveal ? 'ซ่อน' : 'แสดง' }}
            </button>
        </div>
        <p v-if="error" :id="`error-${name}`" class="field-error" role="alert">{{ error }}</p>
    </div>
</template>
