import { nextTick, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MaintenanceForm from '@/Components/MaintenanceForm.vue';
import { setLocale } from '@/lang/i18n';

setLocale('en');

function mountForm(kind = 'periodic', category = 'oil') {
    const form = reactive({
        vehicle_id: 1, kind, category, status: 'done', opened_at: '2026-10-01T10:00', completed_at: '2026-10-01T12:00',
        odometer_km: '10000', cost: '500', workshop: '', note: '', interval_km: '5000', interval_days: '90', errors: {},
    });
    const wrapper = mount(MaintenanceForm, {
        props: { form, vehicles: [{ id: 1, number: '2140', letters: 'ن ق ل', odometer: 9000 }] },
    });

    return { wrapper, form };
}

describe('MaintenanceForm', () => {
    it('shows the kilometre and day intervals for periodic work', () => {
        const { wrapper } = mountForm();

        expect(wrapper.find('[data-testid="interval-km"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="interval-days"]').exists()).toBe(true);
    });

    it('hides and clears the intervals when the job is a breakdown', async () => {
        const { wrapper, form } = mountForm();

        await wrapper.get('[data-testid="kind"]').setValue('emergency');
        await nextTick();

        expect(wrapper.find('[data-testid="interval-km"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="interval-days"]').exists()).toBe(false);
        expect(form.interval_km).toBe('');
        expect(form.interval_days).toBe('');
        expect(wrapper.get('[data-testid="note"]').element.required).toBe(true);
    });

    it('requires a note when the item is Other', async () => {
        const { wrapper } = mountForm('periodic', 'oil');

        expect(wrapper.get('[data-testid="note"]').element.required).toBe(false);

        await wrapper.get('[data-testid="category"]').setValue('other');
        await nextTick();

        expect(wrapper.get('[data-testid="note"]').element.required).toBe(true);
    });
});
