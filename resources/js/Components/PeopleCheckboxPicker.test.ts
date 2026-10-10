import { mount } from '@vue/test-utils';
import { expect, it } from 'vitest';
import Picker from './PeopleCheckboxPicker.vue';
it('keeps the selection outside the search results and allows removing an inactive instructor', async () => {
    const wrapper = mount(Picker, {
        props: {
            modelValue: [1],
            people: [
                { id: 1, display_name: 'ผู้คุมเดิม', is_active: false },
                { id: 2, display_name: 'ผู้คุมใหม่', is_active: true },
            ],
        },
    });
    await wrapper.get('input[type="search"]').setValue('ใหม่');
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);
    expect(wrapper.get('.selected-people').text()).toContain('ผู้คุมเดิม');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    expect(wrapper.emitted('update:modelValue')![0]).toEqual([[1, 2]]);
    await wrapper.get('.selected-people button').trigger('click');
    expect(wrapper.emitted('update:modelValue')![1]).toEqual([[]]);
    wrapper.unmount();
});
it('searches one thousand people while retaining selection outside the result', async () => {
    const wrapper = mount(Picker, {
        props: {
            modelValue: [1],
            people: Array.from({ length: 1000 }, (_, index) => ({
                id: index + 1,
                display_name: `บุคลากร ${index + 1}`,
                is_active: true,
            })),
        },
    });
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1000);
    await wrapper.get('input[type="search"]').setValue('บุคลากร 1000');
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1);
    expect(wrapper.get('.selected-people').text()).toContain('บุคลากร 1');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    expect(wrapper.emitted('update:modelValue')![0]).toEqual([[1, 1000]]);
    wrapper.unmount();
});
