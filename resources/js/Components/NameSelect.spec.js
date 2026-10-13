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
        const plus = wrapper.get('[data-testid="add-name"]');
        expect(html.indexOf('data-testid="add-name"')).toBeLessThan(html.indexOf('data-testid="name-select"'));
        expect(plus.classes()).toContain('name-plus');
        expect(plus.classes().includes('btn')).toBe(false);
        expect(wrapper.get('[data-testid="name-select"]').text()).toContain('الإسكندرية');

        await plus.trigger('click');
        const pop = wrapper.get('.name-pop');
        expect(pop.element.parentElement).toBe(wrapper.get('[data-testid="name-select"]').element.parentElement);
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
