import { mount } from '@vue/test-utils';
import { expect, it, vi } from 'vitest';
import ImportPage from './Import.vue';
import type { CourseRecord, VersionRecord } from '../../Types/learning';
const { posted } = vi.hoisted(() => ({ posted: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Link: { template: '<a><slot /></a>' },
        usePage: () => ({ props: { app: { timezone: 'Asia/Bangkok' } } }),
        useForm: (data: Record<string, unknown>) => {
            const form = reactive({
                token: data.token,
                ...data,
                errors: {},
                processing: false,
                progress: null,
                reset: vi.fn(),
                post: (url: string) => posted(url, form.token),
            });
            return form;
        },
    };
});
vi.mock('../../Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('../../Components/ExamSettings.vue', () => ({ default: { template: '<div />' } }));
const course = { id: 4, title: 'หลักสูตร' } as CourseRecord;
const version = {
    id: 7,
    version_name: 'เวอร์ชันที่เผยแพร่',
    published_at: '2026-10-09',
} as VersionRecord;
const questions = { data: [], links: [], total: 0, from: null, to: null };
const exam = {
    id: 2,
    type: 'PRETEST',
    count: 45,
    status: 'draft',
    question_count: null,
    time_limit_minutes: null,
    randomize_questions: false,
    randomize_choices: false,
    has_attempts: false,
};
it('paginates preview locally while committing the original whole-file token', async () => {
    const rows = Array.from({ length: 45 }, (_, index) => ({
        code: `PRE${String(index + 1).padStart(3, '0')}`,
        usage: 'PRETEST',
        question_text: `ข้อ ${index + 1}`,
        choices: { A: 'หนึ่ง', B: 'สอง', C: 'สาม', D: 'สี่' },
        correct_answer: 'A',
        max_score: 1,
        explanation: null,
    }));
    const wrapper = mount(ImportPage, {
        props: {
            course,
            version,
            questions,
            exams: [exam],
            preview: {
                token: 'whole-file-token',
                file_name: 'original.xlsx',
                expires_at: new Date(Date.now() + 60000).toISOString(),
                rows,
            },
        },
    });
    expect(wrapper.findAll('tbody tr')).toHaveLength(20);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'หน้าถัดไป')!
        .trigger('click');
    expect(wrapper.find('tbody tr').text()).toContain('PRE021');
    expect(wrapper.findAll('tbody tr')).toHaveLength(20);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'หน้าถัดไป')!
        .trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(5);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'ยืนยันนำเข้า 45 ข้อ')!
        .trigger('click');
    expect(posted).toHaveBeenLastCalledWith('/versions/7/questions/commit', 'whole-file-token');
    wrapper.unmount();
});
it('separates the content publication lock from the assessment import lock', async () => {
    const wrapper = mount(ImportPage, {
        props: { course, version, questions, preview: null, exams: [exam] },
    });
    expect(wrapper.get('input[type="file"]').attributes('disabled')).toBeUndefined();
    await wrapper.setProps({ exams: [{ ...exam, status: 'published' }] });
    expect(wrapper.get('input[type="file"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
});
