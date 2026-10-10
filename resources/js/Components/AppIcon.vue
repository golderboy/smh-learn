<script setup lang="ts">
withDefaults(defineProps<{ name: string; size?: number }>(), { size: 24 });
const paths: Record<string, string[]> = {
    home: ['M3 10 12 3l9 7', 'M5 9v12h5v-7h4v7h5V9'],
    book: ['M3 4c4-1 7 0 9 2v15c-2-2-5-3-9-2V4Z', 'M21 4c-4-1-7 0-9 2v15c2-2 5-3 9-2V4Z'],
    cap: ['m2 9 10-5 10 5-10 5L2 9Z', 'M6 11v6c4 3 8 3 12 0v-6', 'M22 9v7'],
    chart: ['M4 20V10', 'M10 20V4', 'M16 20v-7', 'M22 20V8'],
    document: ['M14 2H5v20h14V7l-5-5Z', 'M14 2v5h5', 'M8 11h8', 'M8 15h8', 'M8 18h5'],
    settings: [
        'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
        'm10 2-.5 3-2 .8L5 4.5 2.5 8 5 10v4l-2.5 2L5 19.5l2.5-1.3 2 .8.5 3h4l.5-3 2-.8 2.5 1.3 2.5-3.5-2.5-2v-4l2.5-2L19 4.5l-2.5 1.3-2-.8-.5-3h-4Z',
    ],
    star: ['m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6Z'],
    ticket: ['M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4V5Z', 'M15 5v3', 'M15 11v2', 'M15 16v3'],
    users: [
        'M16 21v-3c0-3-3-5-6-5s-6 2-6 5v3',
        'M10 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
        'M17 4c5 0 5 7 0 7',
        'M18 14c3 1 4 3 4 6',
    ],
    user: ['M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z', 'M4 21v-2c0-4 4-6 8-6s8 2 8 6v2'],
    arrow: ['M4 12h16', 'm14 6 6 6-6 6'],
    chevron: ['m9 5 7 7-7 7'],
    menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
    check: ['m5 12 4 4L19 6'],
    play: ['m8 4 12 8-12 8V4Z'],
    lock: ['M5 10h14v11H5V10Z', 'M8 10V6a4 4 0 0 1 8 0v4'],
    clock: ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Z', 'M12 6v6l4 2'],
    shield: ['m12 2 9 4v6c0 5-5 8-9 10-4-2-9-5-9-10V6l9-4Z', 'm8 12 3 3 5-6'],
    folder: ['M2 5h8l2 3h10v12H2V5Z'],
};
</script>
<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="app-icon"
    >
        <path v-for="(path, index) in paths[name] || paths.book" :key="index" :d="path" />
    </svg>
</template>
