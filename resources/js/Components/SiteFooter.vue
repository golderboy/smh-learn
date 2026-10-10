<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../Types/foundation';
const page = usePage<SharedProps>();
</script>
<template>
    <footer class="site-footer">
        <span>{{ page.props.app?.branding?.footer_text }}</span>
        <div class="support-links">
            <a
                v-if="page.props.app?.branding?.support_email"
                :href="`mailto:${page.props.app?.branding.support_email}`"
                >{{ page.props.app?.branding.support_email }}</a
            ><span v-if="page.props.app?.branding?.support_phone">{{
                page.props.app?.branding.support_phone
            }}</span
            ><a
                v-if="page.props.app?.branding?.support_url"
                :href="page.props.app?.branding.support_url"
                target="_blank"
                rel="noopener noreferrer"
                >ติดต่อผู้ดูแลระบบ <span class="sr-only">เปิดหน้าต่างใหม่</span></a
            >
        </div>
    </footer>
</template>
