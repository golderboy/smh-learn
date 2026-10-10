import { mount, flushPromises } from '@vue/test-utils';
import { expect, it } from 'vitest';
import FormErrors from './FormErrors.vue';
it('opens a collapsed form and focuses its persistent error summary', async () => {
    const host = document.createElement('details');
    document.body.append(host);
    const wrapper = mount(FormErrors, { attachTo: host, props: { errors: {} } });
    await wrapper.setProps({ errors: { title: 'ชื่อยาวเกินกำหนด' } });
    await flushPromises();
    expect(host.open).toBe(true);
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element);
    expect(wrapper.text()).toContain('ชื่อยาวเกินกำหนด');
    wrapper.unmount();
    host.remove();
});
it('reveals an inactive form area before moving keyboard focus', async () => {
    const host = document.createElement('section');
    host.style.display = 'none';
    document.body.append(host);
    host.addEventListener('reveal-form-error', () => {
        host.style.display = '';
    });
    const wrapper = mount(FormErrors, {
        attachTo: host,
        props: { errors: { title: 'บันทึกไม่สำเร็จ' } },
    });
    await flushPromises();
    expect(host.style.display).toBe('');
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element);
    wrapper.unmount();
    host.remove();
});
