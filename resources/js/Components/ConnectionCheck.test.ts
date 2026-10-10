import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConnectionCheck from './ConnectionCheck.vue';

afterEach(() => vi.unstubAllGlobals());

describe('connection check interaction', () => {
    it('prevents duplicate clicks while waiting and shows actual response results', async () => {
        let resolveResponse!: (value: Response) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((resolve) => {
                        resolveResponse = resolve;
                    }),
            ),
        );
        const wrapper = mount(ConnectionCheck);
        await wrapper.get('button').trigger('click');
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('เชื่อมต่อบริการทั้งหมดสำเร็จ');
        resolveResponse(
            new Response(
                JSON.stringify({
                    ok: true,
                    checks: { database: true, redis: true, storage: true },
                }),
                { status: 200 },
            ),
        );
        await flushPromises();
        expect(wrapper.text()).toContain('เชื่อมต่อบริการทั้งหมดสำเร็จ');
        expect(wrapper.findAll('li')).toHaveLength(3);
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
    });

    it('does not show success when the server returns a failed service check', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        ok: false,
                        checks: { database: false, redis: true, storage: true },
                    }),
                    { status: 503 },
                ),
            ),
        );
        const wrapper = mount(ConnectionCheck);
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('ตรวจสอบไม่สำเร็จ');
        expect(wrapper.findAll('li')[0]?.text()).toContain('ไม่ผ่าน');
        expect(wrapper.text()).not.toContain('เชื่อมต่อบริการทั้งหมดสำเร็จ');
    });

    it('allows retry after a network error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network unavailable')));
        const wrapper = mount(ConnectionCheck);
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('ตรวจสอบไม่สำเร็จ');
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
    });
});
