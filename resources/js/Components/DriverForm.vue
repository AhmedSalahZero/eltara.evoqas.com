<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — DriverForm ("New driver" / "Edit driver" pop-up)
  Location: resources/js/Components/DriverForm.vue
  Name, mobile (his Driver App sign-in), first PIN (new drivers only;
  empty = generated), driving licence and expiry, pay basis, base
  salary, join date, usual vehicle. Server: Office\DriverController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Field from './Field.vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    show: Boolean,
    driver: { type: Object, default: null },
    vehicles: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const FIELDS = { name: '', mobile: '', pin: '', license_number: '', license_expires_at: '', pay_basis: 'fixed_plus_trip', base_salary: '', joined_at: '', vehicle_id: '', notes: '' };
const form = useForm({ ...FIELDS });
const editing = computed(() => !!props.driver);

watch(() => [props.show, props.driver], () => {
    const values = { ...FIELDS };
    if (props.driver) for (const k of Object.keys(FIELDS)) values[k] = props.driver[k] ?? '';
    values.pin = '';
    form.defaults(values);
    form.reset();
    form.clearErrors();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    form.transform((d) => {
        const out = { ...d, vehicle_id: d.vehicle_id || null };
        if (editing.value || !d.pin) delete out.pin;
        return out;
    });
    editing.value ? form.patch(route('office.drivers.update', props.driver.id), options) : form.post(route('office.drivers.store'), options);
}
</script>

<template>
    <Modal :show="show" wide :title="editing ? t('drv.edit') : t('drv.add')" @close="emit('close')">
        <form id="driver-form" @submit.prevent="submit">
            <div class="fgrid">
                <Field :label="t('drv.name')" :error="form.errors.name"><input v-model="form.name" required></Field>
                <Field :label="t('drv.mobile')" :error="form.errors.mobile"><input v-model="form.mobile" dir="ltr" inputmode="tel" placeholder="01X XXXX XXXX" required></Field>
                <Field v-if="!editing" :label="t('drv.pin')" :hint="t('drv.pinHint')" :error="form.errors.pin">
                    <input v-model="form.pin" dir="ltr" inputmode="numeric" maxlength="4" autocomplete="off">
                </Field>
                <Field :label="t('drv.usualVehicle')" :error="form.errors.vehicle_id">
                    <select v-model="form.vehicle_id">
                        <option value="">{{ t('drv.noVehicle') }}</option>
                        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }}{{ v.taken ? ' — ' + t('drv.vehicleTaken', { name: v.taken }) : '' }}</option>
                    </select>
                </Field>
                <Field :label="t('drv.licenceNo')" :error="form.errors.license_number"><input v-model="form.license_number" dir="ltr"></Field>
                <Field :label="t('drv.licenceExp')" :error="form.errors.license_expires_at"><input v-model="form.license_expires_at" type="date"></Field>
                <Field :label="t('drv.payBasis')" :error="form.errors.pay_basis">
                    <select v-model="form.pay_basis"><option v-for="k in ['fixed', 'fixed_plus_trip', 'per_trip']" :key="k" :value="k">{{ t('drv.pay.' + k) }}</option></select>
                </Field>
                <Field :label="t('drv.baseSalary')" :error="form.errors.base_salary"><input v-model="form.base_salary" type="number" min="0" step="any" dir="ltr"></Field>
                <Field :label="t('drv.joined')" :error="form.errors.joined_at"><input v-model="form.joined_at" type="date"></Field>
            </div>
            <Field :label="t('drv.notes')" :error="form.errors.notes"><textarea v-model="form.notes" /></Field>
        </form>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" form="driver-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
        </template>
    </Modal>
</template>
