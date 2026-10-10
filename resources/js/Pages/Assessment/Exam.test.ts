import { mount, flushPromises, type VueWrapper } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import Exam from './Exam.vue';
import type { EnrollmentRecord } from '../../Types/learning';
const { learningPost, post } = vi.hoisted(() => ({ learningPost: vi.fn(), post: vi.fn() }));
vi.mock('../../learning-http', () => ({ learningPost }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { errors: {} } }),
    router: { post, reload: vi.fn(), on: () => () => {} },
}));
vi.mock('../../Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('../../Components/UiDialog.vue', () => ({
    default: { props: ['open'], template: '<div v-if="open"><slot /></div>' },
}));
let wrapper: VueWrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.clearAllMocks();
});
const attempt = {
    id: 17,
    exam_id: 5,
    enrollment_id: 31,
    attempt_number: 1,
    status: 'IN_PROGRESS',
    revision: 0,
    expires_at: null,
    submitted_at: null,
    score: null,
    max_score: null,
    percentage: null,
    passed: null,
};
function render() {
    wrapper = mount(Exam, {
        props: {
            attempt,
            examType: 'POSTTEST',
            serverNow: new Date().toISOString(),
            enrollment: {
                id: 31,
                session: { version: { course: { title: 'ทดสอบ' } } },
            } as EnrollmentRecord,
            summary: {
                pretestReady: true,
                posttestReady: true,
                usedPosttestAttempts: 1,
                maxPosttestAttempts: 1,
                bestPosttest: null,
                attempts: [attempt],
            },
            questions: [
                {
                    question_id: 90,
                    question_text: 'หกตัวเลือกตามลำดับจากระบบ',
                    max_score: 1,
                    selected_answer: null,
                    choices: ['F', 'A', 'B', 'C', 'D', 'E'].map((key) => ({
                        key,
                        text: `ตัวเลือก ${key}`,
                    })),
                },
                {
                    question_id: 22,
                    question_text: 'อีกข้อ',
                    max_score: 1,
                    selected_answer: null,
                    choices: ['E', 'D', 'C', 'B', 'A'].map((key) => ({
                        key,
                        text: `ตัวเลือก ${key}`,
                    })),
                },
            ],
        },
    });
}
it('preserves original choice keys and both answers when navigating before submitting the same attempt', async () => {
    learningPost.mockResolvedValue({ attempt: { ...attempt, revision: 1 } });
    render();
    expect(wrapper.findAll('.exam-choice')[0]!.text()).toBe('ก. ตัวเลือก F');
    await wrapper.get('input[name="q-90"][value="F"]').setValue(true);
    await flushPromises();
    await wrapper.findAll('.question-numbers button')[1]!.trigger('click');
    expect(wrapper.findAll('.exam-question')[0]!.isVisible()).toBe(false);
    await wrapper.get('input[name="q-22"][value="E"]').setValue(true);
    await flushPromises();
    await wrapper.findAll('.question-numbers button')[0]!.trigger('click');
    expect((wrapper.get('input[name="q-90"][value="F"]').element as HTMLInputElement).checked).toBe(
        true,
    );
    await wrapper.get('form').trigger('submit');
    expect(post).not.toHaveBeenCalled();
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'ยืนยันส่งข้อสอบ')!
        .trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith(
        '/attempts/17/submit',
        {
            revision: 1,
            answers: [
                { question_id: 90, selected_answer: 'F' },
                { question_id: 22, selected_answer: 'E' },
            ],
        },
        expect.any(Object),
    );
});
it('does not claim all answers are saved while queued writes remain, and a different success cannot hide a failed answer', async () => {
    let finishFirst!: (value: unknown) => void;
    learningPost
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finishFirst = resolve;
                }),
        )
        .mockRejectedValueOnce(new Error('บันทึกข้อ 2 ไม่สำเร็จ'))
        .mockResolvedValue({ attempt: { ...attempt, revision: 2 } });
    render();
    await wrapper.get('input[name="q-90"][value="F"]').setValue(true);
    await flushPromises();
    await wrapper.findAll('.question-numbers button')[1]!.trigger('click');
    await wrapper.get('input[name="q-22"][value="E"]').setValue(true);
    expect(wrapper.get('.exam-status').text()).toContain('กำลังบันทึกคำตอบ');
    expect(learningPost).toHaveBeenCalledTimes(1);
    finishFirst({ attempt: { ...attempt, revision: 1 } });
    await flushPromises();
    expect(wrapper.text()).toContain('บันทึกข้อ 2 ไม่สำเร็จ');
    await wrapper.findAll('.question-numbers button')[0]!.trigger('click');
    await wrapper.get('input[name="q-90"][value="A"]').setValue(true);
    await flushPromises();
    expect(wrapper.text()).toContain('บันทึกข้อ 2 ไม่สำเร็จ');
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'ลองบันทึกคำตอบอีกครั้ง')!
        .trigger('click');
    await flushPromises();
    expect(learningPost).toHaveBeenLastCalledWith('/attempts/17/answers', {
        revision: 2,
        answers: [{ question_id: 22, selected_answer: 'E' }],
    });
    expect(wrapper.text()).not.toContain('บันทึกข้อ 2 ไม่สำเร็จ');
});
