<script setup lang="ts">
defineOptions({ name: 'PaginationControls' });
import { Link } from '@inertiajs/vue3';
import type { PageLink } from '../Types/foundation';
defineProps<{ links: PageLink[]; total: number; from: number | null; to: number | null }>();
</script>
<template>
    <div class="pagination-row">
        <span class="muted">{{ from ?? 0 }}–{{ to ?? 0 }} จาก {{ total }} รายการ</span>
        <nav aria-label="เปลี่ยนหน้ารายการ" class="pagination">
            <template v-for="(link, index) in links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="page-link"
                    :class="{ selected: link.active }"
                    :aria-current="link.active ? 'page' : undefined"
                    >{{
                        index === 0 ? 'ก่อนหน้า' : index === links.length - 1 ? 'ถัดไป' : link.label
                    }}</Link
                >
                <span v-else class="page-link muted" aria-disabled="true">{{
                    index === 0 ? 'ก่อนหน้า' : index === links.length - 1 ? 'ถัดไป' : link.label
                }}</span>
            </template>
        </nav>
    </div>
</template>
