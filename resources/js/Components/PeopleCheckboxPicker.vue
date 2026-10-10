<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{
    modelValue: number[];
    people: { id: number; display_name: string; is_active: boolean }[];
}>();
const emit = defineEmits<{ 'update:modelValue': [ids: number[]] }>();
const query = ref('');
const matches = computed(() =>
    props.people.filter((person) =>
        person.display_name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()),
    ),
);
const selected = computed(() =>
    props.people.filter((person) => props.modelValue.includes(person.id)),
);
function toggle(id: number) {
    emit(
        'update:modelValue',
        props.modelValue.includes(id)
            ? props.modelValue.filter((value) => value !== id)
            : [...props.modelValue, id],
    );
}
</script>
<template>
    <div class="picker-tools">
        <div class="field">
            <label for="instructor-search">ค้นหาผู้คุมในรายชื่อ</label
            ><input id="instructor-search" v-model="query" type="search" autocomplete="off" />
        </div>
        <span class="muted">เลือกแล้ว {{ modelValue.length }} คน</span>
    </div>
    <div class="selected-people">
        <button
            v-for="person in selected"
            :key="person.id"
            type="button"
            :aria-label="`ถอนการเลือก ${person.display_name}`"
            @click="toggle(person.id)"
        >
            {{ person.display_name }} · ถอนการเลือก
        </button>
    </div>
    <div class="checkbox-grid">
        <label v-for="person in matches" :key="person.id" class="check-row"
            ><input
                type="checkbox"
                :checked="modelValue.includes(person.id)"
                :disabled="!person.is_active && !modelValue.includes(person.id)"
                @change="toggle(person.id)"
            />{{ person.display_name
            }}<span v-if="!person.is_active" class="helper"
                >(ปิดใช้งานแล้ว กรุณาถอนการมอบหมาย)</span
            ></label
        >
        <p v-if="!matches.length" class="muted">ไม่พบชื่อที่ค้นหา รายการที่เลือกไว้ยังคงอยู่</p>
    </div>
</template>
