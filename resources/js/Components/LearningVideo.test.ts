import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import LearningVideo from './LearningVideo.vue';
import type { ContentRecord } from '../Types/learning';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('../learning-http', () => ({ learningPost: post }));
afterEach(() => vi.restoreAllMocks());

function fixture() {
    const content: ContentRecord = {
        id: 1,
        title: 'คลิปสั้น',
        content_type: 'video',
        video_source: 'remote',
        original_name: null,
        file_size: null,
        duration_seconds: 6,
        body: null,
        external_url: 'https://example.org/clip.mp4',
        is_required: true,
        sort_order: 1,
    };
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    const pause = vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (
        this: HTMLMediaElement,
    ) {
        this.dispatchEvent(new Event('pause'));
    });
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (
        this: HTMLMediaElement,
    ) {
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
    });
    const wrapper = mount(LearningVideo, { props: { content, enrollmentId: 2, threshold: '100' } });
    const video = wrapper.get('video').element as HTMLVideoElement;
    Object.defineProperty(video, 'duration', { configurable: true, value: 5.055 });
    return { wrapper, video, pause, play };
}

it('pauses initial playback until the server confirms begin, then resumes without a second begin', async () => {
    let finish!: (value: unknown) => void;
    post.mockReset().mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const { wrapper, video, pause, play } = fixture();
    try {
        await video.play();
        await flushPromises();
        expect(pause).toHaveBeenCalledOnce();
        expect(play).toHaveBeenCalledOnce();
        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0]?.[1]).toMatchObject({
            begin: true,
            position: 0,
            duration: 5.055,
        });
        finish({
            key: 'confirmed-key',
            progress: { watch_percent: '0', content_id: 1 },
            enrollment: {},
        });
        await flushPromises();
        expect(play).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenCalledOnce();
        expect(wrapper.text()).toContain('หยุดชั่วคราว');
    } finally {
        wrapper.unmount();
    }
});

it('keeps playback paused and reports failure when begin is rejected', async () => {
    post.mockReset().mockRejectedValue(new Error('การเข้าสู่ระบบหมดอายุ'));
    const { wrapper, video, play } = fixture();
    try {
        await video.play();
        await flushPromises();
        expect(play).toHaveBeenCalledOnce();
        expect(wrapper.get('[role="alert"]').text()).toBe('การเข้าสู่ระบบหมดอายุ');
    } finally {
        wrapper.unmount();
    }
});
