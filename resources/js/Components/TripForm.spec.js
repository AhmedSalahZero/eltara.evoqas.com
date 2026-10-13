import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import TripForm from '@/Components/TripForm.vue';
import { setLocale } from '@/lang/i18n';

vi.mock('@inertiajs/vue3', () => ({
    useForm: () => {
        const form = reactive({
            errors: {},
            processing: false,
            customer_id: '',
            trip_route_id: '',
            vehicle_id: '',
            driver_id: '',
            loading_at: '',
            cargo_type_id: '',
            weight_tons: '',
            notes: '',
            freight_price: '',
            client_pays_cash: false,
            custody_planned: '',
            transfer_policy: 'limit',
            auto_transfer_limit: 1000,
            hire_fee: '',
        });
        form.defaults = () => {};
        form.reset = () => {};
        form.clearErrors = () => {};
        form.transform = () => form;
        form.post = () => {};
        form.patch = () => {};

        return form;
    },
    usePage: () => ({ props: { auth: { user: { permissions: [] } } } }),
}));

setLocale('en');
globalThis.route = (name) => `/${name}`;

describe('TripForm', () => {
    it('opens in the wider window', () => {
        const wrapper = mount(TripForm, {
            attachTo: document.body,
            global: { config: { globalProperties: { route: (name) => `/${name}` } } },
            props: {
                show: true,
                options: {
                    customers: [],
                    routes: [],
                    vehicles: [],
                    drivers: [],
                    cargo_types: [],
                    defaults: { policy: 'limit', limit: 1000, buffer: 5 },
                },
            },
        });

        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog.classList.contains('xl')).toBe(true);
        expect(dialog.classList.contains('w')).toBe(false);
        wrapper.unmount();
    });
});
