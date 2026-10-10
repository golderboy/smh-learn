import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from './Types/foundation';
export function useDateTime() {
    const page = usePage<SharedProps>();
    return (value: string | null | undefined) => {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '—';
        return date.toLocaleString('th-TH', {
            timeZone: page.props.app.timezone,
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };
}
