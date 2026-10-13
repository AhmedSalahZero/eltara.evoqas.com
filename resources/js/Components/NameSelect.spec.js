import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axios from 'axios';
import { describe, expect, it, vi } from 'vitest';
import NameSelect from '@/Components/NameSelect.vue';
import { setLocale } from '@/lang/i18n';

vi.mock('axios', () => ({
    default: { post: vi.fn() },
}));

setLocale('en');

describe('NameSelect', () => {
    it('puts add above the list and selects the new name', async () => {
        axios.post.mockResolvedValue({ data: { id: 1, name: 'القاهرة', language: 'ar' } });

        const wrapper = mount(NameSelect, {
            props: {
                modelValue: '',
                options: ['الإسكندرية'],
                addUrl: '/office/route-places',
                extra: { language: 'ar' },
                label: 'From',
            },
        });

        const html = wrapper.html();
        expect(html.indexOf('data-testid="add-name"')).toBeLessThan(html.indexOf('data-testid="name-select"'));
        expect(wrapper.get('[data-testid="name-select"]').text()).toContain('الإسكندرية');

        await wrapper.get('[data-testid="add-name"]').trigger('click');
        await wrapper.get('[data-testid="new-name"]').setValue('  القاهرة  ');
        await wrapper.get('[data-testid="confirm-name"]').trigger('click');
        await flushPromises();
        await nextTick();

        expect(axios.post).toHaveBeenCalledWith(
            '/office/route-places',
            { name: 'القاهرة', language: 'ar' },
            expect.any(Object),
        );
        expect(wrapper.emitted('update:modelValue')[0]).toEqual(['القاهرة']);
        expect(wrapper.get('[data-testid="name-select"]').text()).toContain('القاهرة');
    });
});
