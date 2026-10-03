<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — VehicleForm ("New vehicle" / "Edit vehicle" pop-up)
  Location: resources/js/Components/VehicleForm.vue
  Plate, truck type (pick, or add a new one), model, ownership (hired → owner's name), usual driver,
  odometer, standard km/L, status, and the three documents with their
  expiry dates. Server checks: Office\VehicleController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Field from './Field.vue';
import ListSelect from './ListSelect.vue';
import AppIcon from './AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    show: Boolean,
    vehicle: { type: Object, default: null },
    drivers: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const { can } = usePermissions();

const FIELDS = {
    plate_number: '', plate_letters: '', vehicle_type_id: '', model: '', year: '', capacity_tons: '',
    ownership: 'own', owner_name: '', owner_phone: '', driver_id: '', odometer_km: '', std_km_per_litre: '',
    status: 'available', licence_number: '', licence_expires_at: '', insurance_company: '', insurance_policy_number: '',
    insurance_expires_at: '', inspection_expires_at: '', notes: '',
};

const form = useForm({ ...FIELDS });
const editing = computed(() => !!props.vehicle);

watch(() => [props.show, props.vehicle], () => {
    const values = { ...FIELDS };
    if (props.vehicle) for (const k of Object.keys(FIELDS)) values[k] = props.vehicle[k] ?? '';
    form.defaults(values);
    form.reset();
    form.clearErrors();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    form.transform((d) => ({ ...d, driver_id: d.driver_id || null }));
    editing.value ? form.patch(route('office.vehicles.update', props.vehicle.id), options) : form.post(route('office.vehicles.store'), options);
}
</script>

<template>
    <Modal :show="show" wide :title="editing ? t('veh.edit') : t('veh.add')" @close="emit('close')">
        <form id="vehicle-form" @submit.prevent="submit">
            <div class="fgrid3">
                <Field :label="t('veh.plateNumber')" :error="form.errors.plate_number"><input v-model="form.plate_number" dir="ltr" inputmode="numeric" required></Field>
                <Field :label="t('veh.plateLetters')" :error="form.errors.plate_letters"><input v-model="form.plate_letters" placeholder="ن ق ل" required></Field>
                <ListSelect v-model="form.vehicle_type_id" :label="t('veh.type')" :placeholder="t('veh.chooseType')" :options="types"
                            :add-url="route('office.vehicle_types.store')" :can-add="can('vehicles.create')" :error="form.errors.vehicle_type_id" required />
                <Field :label="t('veh.model')" :error="form.errors.model"><input v-model="form.model" dir="ltr"></Field>
                <Field :label="t('veh.year')" :error="form.errors.year"><input v-model="form.year" type="number" min="1970" dir="ltr"></Field>
                <Field :label="t('veh.capacity')" :error="form.errors.capacity_tons"><input v-model="form.capacity_tons" type="number" step="0.5" min="0" dir="ltr"></Field>
            </div>

            <div class="sec" style="margin-top:16px">{{ t('veh.ownership') }}</div>
            <div class="opts" style="grid-template-columns:1fr 1fr">
                <label class="opt"><input v-model="form.ownership" type="radio" value="own"><div><b>{{ t('veh.own') }}</b></div></label>
                <label class="opt"><input v-model="form.ownership" type="radio" value="hired"><div><b>{{ t('veh.hired') }}</b></div></label>
            </div>
            <template v-if="form.ownership === 'hired'">
                <div class="note am" style="margin-top:10px"><AppIcon name="info" /><div>{{ t('veh.hiredNote') }}</div></div>
                <div class="fgrid">
                    <Field :label="t('veh.ownerName')" :error="form.errors.owner_name"><input v-model="form.owner_name" required></Field>
                    <Field :label="t('veh.ownerPhone')" :error="form.errors.owner_phone"><input v-model="form.owner_phone" dir="ltr" inputmode="tel"></Field>
                </div>
            </template>

            <div class="fgrid">
                <Field :label="t('veh.driver')" :error="form.errors.driver_id">
                    <select v-model="form.driver_id">
                        <option value="">{{ t('veh.noDriver') }}</option>
                        <option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }}{{ d.has_vehicle ? ' — ' + t('veh.driverHas', { plate: d.has_vehicle }) : '' }}</option>
                    </select>
                </Field>
                <Field :label="t('veh.status')" :hint="t('veh.statusHint')" :error="form.errors.status">
                    <select v-model="form.status"><option value="available">{{ t('veh.available') }}</option><option value="maintenance">{{ t('veh.maintenance') }}</option></select>
                </Field>
                <Field v-if="form.ownership === 'own'" :label="t('veh.odometerKm')" :error="form.errors.odometer_km"><input v-model="form.odometer_km" type="number" min="0" dir="ltr"></Field>
                <Field v-if="form.ownership === 'own'" :label="t('veh.stdKmpl')" :error="form.errors.std_km_per_litre"><input v-model="form.std_km_per_litre" type="number" step="0.01" min="0" dir="ltr"></Field>
            </div>

            <template v-if="form.ownership === 'own'">
                <div class="sec" style="margin-top:16px">{{ t('veh.documents') }} <span class="xs mu">· {{ t('doc.alertHint') }}</span></div>
                <div class="fgrid">
                    <Field :label="t('veh.licenceNo')" :error="form.errors.licence_number"><input v-model="form.licence_number" dir="ltr"></Field>
                    <Field :label="t('doc.licence')" :error="form.errors.licence_expires_at"><input v-model="form.licence_expires_at" type="date"></Field>
                    <Field :label="t('veh.insuranceCo')" :error="form.errors.insurance_company"><input v-model="form.insurance_company"></Field>
                    <Field :label="t('veh.policyNo')" :error="form.errors.insurance_policy_number"><input v-model="form.insurance_policy_number" dir="ltr"></Field>
                    <Field :label="t('doc.insurance')" :error="form.errors.insurance_expires_at"><input v-model="form.insurance_expires_at" type="date"></Field>
                    <Field :label="t('doc.inspection')" :error="form.errors.inspection_expires_at"><input v-model="form.inspection_expires_at" type="date"></Field>
                </div>
            </template>
            <Field :label="t('veh.notes')" :error="form.errors.notes"><textarea v-model="form.notes" /></Field>
        </form>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" form="vehicle-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
        </template>
    </Modal>
</template>
