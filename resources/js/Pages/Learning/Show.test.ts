import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { expect, it, vi } from 'vitest';
import LearningWorkspace from './Show.vue';
import LearningVideo from '../../Components/LearningVideo.vue';
import type { EnrollmentRecord, ModuleRecord } from '../../Types/learning';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { post, get },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('../../Layouts/AppLayout.vue', () => ({
    default: { template: '<main><slot /></main>' },
}));
vi.mock('../../Components/LearningPdf.vue', () => ({ default: { template: '<div />' } }));

function fixture() {
    const enrollment: EnrollmentRecord = {
        id: 31,
        course_session_id: 4,
        user_id: 5,
        state: 'LEARNING',
        progress_percent: '0.00',
        due_date: null,
        completed_at: null,
        cancelled_at: null,
        pretest_completed_at: '2026-10-08T09:00:00+07:00',
        posttest_completed_at: null,
        content_completed_at: null,
        session: {
            id: 4,
            name: 'ทดสอบ',
            fiscal_year: 2570,
            start_date: '2026-10-01',
            end_date: '2027-10-01',
            enrollment_mode: 'optional',
            status: 'open',
            max_attempts: 1,
            required_video_watch_percent: '80',
            required_progress_percent: '100',
            passing_score: '80',
            posttest_required: true,
            pretest_required: true,
            points_enabled: false,
            point_rules_snapshot: null,
        },
    };
    const modules: ModuleRecord[] = [
        {
            id: 1,
            title: 'คลิปสุดท้าย',
            sort_order: 1,
            lessons: [
                {
                    id: 1,
                    module_id: 1,
                    title: 'บทสุดท้าย',
                    description: null,
                    sort_order: 1,
                    is_required: true,
                    status: 'active',
                    contents: [
                        {
                            id: 22,
                            title: 'วิดีโอ',
                            content_type: 'video',
                            original_name: 'test.mp4',
                            file_size: 100,
                            duration_seconds: 10,
                            body: null,
                            external_url: null,
                            is_required: true,
                            sort_order: 1,
                        },
                    ],
                },
            ],
        },
    ];
    return { enrollment, modules };
}
it('opens Posttest immediately when the final video heartbeat confirms completion, without a page reload', async () => {
    const { enrollment, modules } = fixture();
    const wrapper = mount(LearningWorkspace, {
        props: {
            enrollment,
            modules,
            progress: [],
            canLearn: true,
            assessment: {
                pretestReady: true,
                posttestReady: true,
                usedPosttestAttempts: 0,
                maxPosttestAttempts: 1,
                bestPosttest: null,
                attempts: [],
            },
        },
        global: { stubs: { LearningVideo: true } },
    });
    expect(wrapper.text()).not.toContain('ทำ Posttest หลังเรียน');
    wrapper.getComponent(LearningVideo).vm.$emit(
        'saved',
        {
            id: 1,
            content_id: 22,
            last_position_seconds: '8',
            watched_seconds: '8',
            watch_percent: '80',
            opened_at: '2026-10-08T10:00:00+07:00',
            completed_at: '2026-10-08T10:00:08+07:00',
        },
        {
            ...enrollment,
            state: 'CONTENT_COMPLETED',
            progress_percent: '100.00',
            content_completed_at: '2026-10-08T10:00:08+07:00',
        },
    );
    await nextTick();
    expect(wrapper.text()).toContain(
        'เรียนครบตามเกณฑ์แล้ว ต้องส่ง Posttest อย่างน้อย 1 ครั้งก่อนจบหลักสูตร',
    );
    const button = wrapper
        .findAll('button')
        .find((item) => item.text() === 'ทำ Posttest หลังเรียน')!;
    expect(button.attributes('disabled')).toBeUndefined();
    await button.trigger('click');
    expect(post).toHaveBeenCalledWith('/learning/31/exams/start', { exam_type: 'POSTTEST' });
    wrapper.unmount();
});

it('keeps Next and future outline disabled until both the video and required attachment finish, then allows server-checked navigation', async () => {
    const { enrollment, modules } = fixture();
    const first = modules[0]!.lessons[0]!;
    const video = first.contents[0]!;
    first.documents = [{ ...video, id: 23, title: 'เอกสารที่จำเป็น', content_type: 'document' }];
    modules[0]!.lessons.push({
        ...first,
        id: 2,
        title: 'บทถัดไปสำหรับทดสอบ',
        sort_order: 2,
        documents: [],
        contents: [{ ...video, id: 24 }],
    });
    const wrapper = mount(LearningWorkspace, {
        props: {
            enrollment,
            modules,
            progress: [],
            canLearn: true,
            assessment: {
                pretestReady: true,
                posttestReady: true,
                usedPosttestAttempts: 0,
                maxPosttestAttempts: 1,
                bestPosttest: null,
                attempts: [],
            },
        },
        global: { stubs: { LearningVideo: true } },
    });
    const next = () => wrapper.findAll('button').find((item) => item.text() === 'บทถัดไป')!;
    expect(next().attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('.lesson-choice')[1]!.attributes('disabled')).toBeDefined();
    const progress = {
        id: 1,
        content_id: 22,
        last_position_seconds: '8',
        watched_seconds: '8',
        watch_percent: '80',
        opened_at: '2026-10-08T10:00:00+07:00',
        completed_at: '2026-10-08T10:00:08+07:00',
    };
    wrapper.getComponent(LearningVideo).vm.$emit('saved', progress, enrollment);
    await nextTick();
    expect(next().attributes('disabled')).toBeDefined();
    await wrapper.setProps({
        progress: [progress, { ...progress, id: 2, content_id: 23 }],
    });
    expect(next().attributes('disabled')).toBeUndefined();
    expect(wrapper.findAll('.lesson-choice')[1]!.attributes('disabled')).toBeUndefined();
    await next().trigger('click');
    expect(get).toHaveBeenCalledWith('/learning/31', { lesson: 2 }, { preserveScroll: true });
    wrapper.unmount();
});
