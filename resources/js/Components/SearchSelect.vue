<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{
    id: string;
    label: string;
    modelValue: string;
    options: { id: number; name: string }[];
    required?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const query = ref('');
const matches = computed(() =>
    props.options.filter(
        (option) =>
            String(option.id) === props.modelValue ||
            option.name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()),
    ),
);
</script>
<template>
    <div class="field">
        <label :for="`${id}-search`">ค้นหา{{ label }}ในรายชื่อ</label
        ><input :id="`${id}-search`" v-model="query" type="search" autocomplete="off" />
    </div>
    <div class="field">
        <label :for="id">{{ label }}</label
        ><select
            :id="id"
            :value="modelValue"
            :required="required"
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option value="">เลือก{{ label }}</option>
            <option v-for="option in matches" :key="option.id" :value="String(option.id)">
                {{ option.name }}
            </option></select
        ><slot /><small class="helper"
            >แสดง {{ matches.length }} จาก {{ options.length }} รายชื่อ</small
        >
    </div>
</template>
