import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TextField from './TextField.vue';

describe('citizen username field', () => {
    it('preserves leading zeroes as text rather than coercing identifiers to numbers', async () => {
        const wrapper = mount(TextField, {
            props: {
                name: 'username',
                label: 'เลขประจำตัว',
                modelValue: '',
                inputmode: 'numeric',
                maxlength: 15,
            },
        });
        const input = wrapper.get('input');
        expect(input.attributes('type')).toBe('text');
        expect(input.attributes('maxlength')).toBe('15');
        await input.setValue('000000000000015');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['000000000000015']);
    });
    it('connects an error message to the actual labelled input for assistive technology', () => {
        const wrapper = mount(TextField, {
            props: {
                name: 'username',
                label: 'เลขประจำตัว',
                modelValue: 'ABC',
                error: 'ต้องเป็นตัวเลข',
            },
        });
        const input = wrapper.get('input');
        expect(wrapper.get('label').attributes('for')).toBe(input.attributes('id'));
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe(
            'ต้องเป็นตัวเลข',
        );
    });
});
