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

    it('uses stock lines for the company workshop and a typed bill for an outside workshop', async () => {
        const plain = mountForm();
        expect(plain.wrapper.find('option[value="own"]').exists()).toBe(false);

        const form = reactive({
            vehicle_id: 1, kind: 'periodic', category: 'oil', status: 'done', source: 'external',
            opened_at: '2026-10-01T10:00', completed_at: '2026-10-01T12:00',
            odometer_km: '10000', cost: '500', workshop: 'Outside', note: '', interval_km: '5000', interval_days: '90',
            store_id: 1, lines: [], errors: {},
        });
        const wrapper = mount(MaintenanceForm, {
            props: {
                form,
                workshop: true,
                vehicles: [{ id: 1, number: '2140', letters: 'ن ق ل', odometer: 9000 }],
                stores: [{ id: 1, name: 'ورشة الشركة' }],
                stock: [{ id: 7, name: 'Oil', unit: 'litre', places: [{ store_id: 1, qty: 20 }] }],
            },
        });

        expect(wrapper.text()).toContain('Outside workshop');
        expect(wrapper.text()).toContain('Cost (EGP)');

        form.source = 'own';
        await nextTick();

        expect(form.cost).toBe('');
        expect(form.workshop).toBe('');
        expect(form.lines).toHaveLength(1);
        expect(wrapper.text()).toContain('The cost is the value issued from the store.');
        expect(wrapper.text()).toContain('ورشة الشركة');
        expect(wrapper.text()).toContain('Oil (20 Litre)');
        expect(wrapper.text()).not.toContain('Cost (EGP)');

        form.source = 'external';
        await nextTick();
        expect(form.lines).toEqual([]);
        expect(wrapper.text()).toContain('Cost (EGP)');
    });
});
