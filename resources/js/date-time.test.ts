import { expect, it, vi } from 'vitest';
import { useDateTime } from './date-time';
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { app: { timezone: 'Asia/Bangkok' } } }),
}));
it('uses the application timezone and Buddhist year for events crossing midnight, and leaves missing dates blank', () => {
    const format = useDateTime();
    const label = format('2026-10-08T18:15:00Z');
    expect(label).toContain('9 ต.ค. 2569');
    expect(label).toContain('01:15');
    expect(format(null)).toBe('—');
    expect(format('not-a-date')).toBe('—');
});
