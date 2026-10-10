import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { learningPost } from './learning-http';

describe('learning requests across Inertia authentication changes', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="old-login-token">';
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; Max-Age=0';
    });
    it('uses the current cookie rather than the stale page token after login', async () => {
        document.cookie = 'XSRF-TOKEN=current%2Btoken';
        const fetcher = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => ({ saved: true }) });
        vi.stubGlobal('fetch', fetcher);
        await expect(
            learningPost('/learning/1/contents/1/heartbeat', { begin: true }),
        ).resolves.toEqual({ saved: true });
        expect(fetcher.mock.calls[0]![1].headers['X-XSRF-TOKEN']).toBe('current+token');
        expect(fetcher.mock.calls[0]![1].headers['X-CSRF-TOKEN']).toBeUndefined();
        expect(fetcher.mock.calls[0]![1].credentials).toBe('same-origin');
    });
    it('reports session expiry instead of claiming progress was saved', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 419,
                json: async () => ({ message: 'CSRF token mismatch' }),
            }),
        );
        await expect(learningPost('/learning/1/contents/1/heartbeat', {})).rejects.toThrow(
            'การเข้าสู่ระบบหมดอายุ',
        );
    });
    it('keeps the server error visible when a playback lease was replaced', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 409,
                json: async () => ({ message: 'มีการเปิดวิดีโอนี้จากอีกหน้าต่าง' }),
            }),
        );
        await expect(learningPost('/learning/1/contents/1/heartbeat', {})).rejects.toThrow(
            'อีกหน้าต่าง',
        );
    });
    it('keeps network failures unsaved and never retries a write automatically', async () => {
        const fetcher = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        vi.stubGlobal('fetch', fetcher);
        await expect(learningPost('/attempts/1/answers', {})).rejects.toMatchObject({ status: 0 });
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
    it('rejects an HTML success response instead of claiming data was saved', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => {
                    throw new SyntaxError('HTML');
                },
            }),
        );
        await expect(learningPost('/attempts/1/answers', {})).rejects.toThrow(
            'ข้อมูลกลับไม่สมบูรณ์',
        );
    });
});
