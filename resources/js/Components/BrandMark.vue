<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../Types/foundation';
const page = usePage<SharedProps>();
</script>
<template>
    <img
        v-if="page.props.app?.branding?.logo_url"
        :src="page.props.app?.branding.logo_url"
        class="brand-logo"
        alt=""
        width="48"
        height="48"
    />
    <svg v-else class="brand-logo" width="48" height="48" viewBox="0 0 48 48" aria-hidden="true">
        <path d="M4 12c7-1 14 2 20 8v24C17 37 11 35 4 35V12Z" fill="#FF735E" />
        <path d="M44 12c-7-1-14 2-20 8v24c7-7 13-9 20-9V12Z" fill="#FF735E" />
        <path d="M8 5c7 0 12 4 16 10v25c-5-6-10-9-16-10V5Z" fill="#2454FF" />
        <path d="M40 5c-7 0-12 4-16 10v25c5-6 10-9 16-10V5Z" fill="#25CAE5" />
        <path d="M24 12c0-7 5-10 10-11-1 6-4 9-10 11Z" fill="#25CAE5" />
    </svg>
</template>
