<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — TripForm (new trip / edit trip)
  Location: resources/js/Components/TripForm.vue

  <TripForm :show="open" :options="options" @close="open = false" />            new
  <TripForm :show="open" :options="options" :trip="trip" @close="…" />          edit

  Scope §6.3. As the office user fills it in:
    · the price fills in from the customer's rate card for the route
      (typing another price needs "Trips → Edit price");
    · the custody fills in from the route's budget (+ buffer, rounded
      up to 500); a hired truck gets none and asks for the owner's fee;
    · the truck's usual driver is suggested; trucks in maintenance or
      with a booked next trip cannot be chosen;
    · the transfer policy starts from Company settings.
  The server checks everything again (App\Services\Trips\TripService).
  When editing, customer and route stay as they are; the truck and
  driver can change only while the trip is planned.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, nextTick, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Field from './Field.vue';
import ListSelect from './ListSelect.vue';
import AppIcon from './AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { inputValue, money, nowInput, num } from '@/Utils/format';

const props = defineProps({
    show: Boolean,
    options: { type: Object, default: null },
    trip: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t } = useI18n();
const { can } = usePermissions();

const editing = computed(() => !!props.trip);
const vehicleChangeable = computed(() => !editing.value || props.trip.status === 'planned');

/** ISO time → the value a datetime-local box wants ("2026-10-02T08:00"). */
function localInput(iso) {
    return iso ? inputValue(iso) : nowInput(3600000);
}

const form = useForm({});
let filling = false; // true while the form is being filled in — the helpers below then keep still

function reset() {
    filling = true;
    nextTick(() => (filling = false));
    const tr = props.trip;
    const d = props.options?.defaults ?? {};
    form.defaults({
        customer_id: tr?.customer.id ?? '',
        trip_route_id: tr?.route.id ?? '',
        vehicle_id: tr?.vehicle?.id ?? '',
        driver_id: tr?.driver?.id ?? '',
        loading_at: localInput(tr?.loading_at),
        cargo_type_id: tr?.cargo_type_id ?? '',
        weight_tons: tr?.weight_tons ?? '',
        notes: tr?.notes ?? '',
        freight_price: tr?.freight_price ?? '',
        client_pays_cash: tr?.client_pays_cash ?? false,
        custody_planned: tr?.custody_planned ?? '',
        transfer_policy: d.policy ?? 'limit',
        auto_transfer_limit: d.limit ?? 1000,
        hire_fee: '',
    });
    form.reset();
    form.clearErrors();
}
watch(() => props.show, (open) => open && reset(), { immediate: true });

const routes = computed(() => props.options?.routes ?? []);
const vehicles = computed(() => props.options?.vehicles ?? []);
const drivers = computed(() => props.options?.drivers ?? []);
const rateFor = (customerId, routeId) => props.options?.rates?.[customerId]?.[routeId] ?? null;

const route_ = computed(() => routes.value.find((r) => r.id === Number(form.trip_route_id)) ?? null);
const vehicle = computed(() => vehicles.value.find((v) => v.id === Number(form.vehicle_id)) ?? null);
const hired = computed(() => vehicle.value?.hired ?? (editing.value && props.trip.is_hired));
const agreed = computed(() => rateFor(form.customer_id, form.trip_route_id));
const priceEditable = computed(() => can('trips.edit_price'));

// Routes with an agreed price for this customer come first.
const sortedRoutes = computed(() => [...routes.value].sort((a, b) =>
    (rateFor(form.customer_id, b.id) != null) - (rateFor(form.customer_id, a.id) != null)));

watch(() => [form.customer_id, form.trip_route_id], ([customer], [oldCustomer] = []) => {
    if (editing.value || filling) return;
    form.freight_price = agreed.value ?? '';
    if (customer !== oldCustomer) {
        form.client_pays_cash = props.options?.customers.find((c) => c.id === Number(customer))?.pays_cash ?? false;
    }
    if (route_.value && !hired.value) form.custody_planned = route_.value.custody;
    if (route_.value?.weight != null) form.weight_tons = route_.value.weight; // the route's weight; change it if this load differs
});

watch(() => form.vehicle_id, () => {
    if (filling || !vehicle.value || (editing.value && !vehicleChangeable.value)) return;
    form.driver_id = vehicle.value.hired ? '' : (vehicle.value.driver_id ?? '');
    if (vehicle.value.hired) form.custody_planned = 0;
    else if (route_.value && !editing.value) form.custody_planned = route_.value.custody;
});

function vehicleLabel(v) {
    const state = v.maintenance ? t('trip.inMaintenance') : v.booked ? t('trip.booked', { trip: v.booked }) : v.on_trip ? t('trip.onTrip', { trip: v.on_trip }) : '';
    return `${v.number} ${v.letters}${v.hired ? ' · ' + t('trip.hired') : ''}${v.driver ? ' · ' + v.driver : ''}${state ? ' — ' + state : ''}`;
}
function driverLabel(d) {
    const state = d.booked ? t('trip.booked', { trip: d.booked }) : d.on_trip ? t('trip.onTrip', { trip: d.on_trip }) : '';
    return d.name + (state ? ' — ' + state : '');
}

const expected = computed(() => (route_.value && form.freight_price !== '' ? Number(form.freight_price) - route_.value.standard : null));

function save() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editing.value) {
        form.transform((data) => {
            const out = {
                loading_at: data.loading_at, cargo_type_id: data.cargo_type_id || null, weight_tons: data.weight_tons === '' ? null : data.weight_tons, notes: data.notes, client_pays_cash: data.client_pays_cash,
                custody_planned: data.custody_planned,
            };
            if (vehicleChangeable.value) Object.assign(out, { vehicle_id: data.vehicle_id, driver_id: data.driver_id || null });
            if (priceEditable.value) out.freight_price = data.freight_price;
            return out;
        }).patch(route('office.trips.update', props.trip.id), options);
    } else {
        form.transform((data) => ({ ...data, driver_id: data.driver_id || null, cargo_type_id: data.cargo_type_id || null, weight_tons: data.weight_tons === '' ? null : data.weight_tons })).post(route('office.trips.store'), options);
    }
}
</script>

<template>
    <Modal :show="show" wide :title="editing ? t('trip.edit') : t('trip.add')" @close="emit('close')">
        <form v-if="options" id="trip-form" @submit.prevent="save">
            <div class="fgrid">
                <Field :label="t('trip.customer')" :error="form.errors.customer_id">
                    <select v-if="!editing" v-model="form.customer_id" class="sel" required>
                        <option value="" disabled>{{ t('trip.chooseCustomer') }}</option>
                        <option v-for="c in options.customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <input v-else :value="trip.customer.name" disabled>
                </Field>
                <Field :label="t('trip.route')" :error="form.errors.trip_route_id">
                    <select v-if="!editing" v-model="form.trip_route_id" class="sel" required>
                        <option value="" disabled>{{ t('trip.chooseRoute') }}</option>
                        <option v-for="r in sortedRoutes" :key="r.id" :value="r.id">
                            {{ r.name }} — {{ rateFor(form.customer_id, r.id) != null ? t('trip.agreed', { price: money(rateFor(form.customer_id, r.id)) }) : t('trip.noAgreed') }}
                        </option>
                    </select>
                    <input v-else :value="trip.route.name" disabled>
                </Field>

                <Field :label="t('trip.vehicle')" :error="form.errors.vehicle_id" :hint="editing && !vehicleChangeable ? t('trip.planOnlyVehicle') : ''">
                    <select v-model="form.vehicle_id" class="sel" required :disabled="!vehicleChangeable">
                        <option value="" disabled>{{ t('trip.chooseVehicle') }}</option>
                        <option v-for="v in vehicles" :key="v.id" :value="v.id" :disabled="v.maintenance || !!v.booked">{{ vehicleLabel(v) }}</option>
                    </select>
                </Field>
                <Field :label="t('trip.driver')" :error="form.errors.driver_id" :hint="hired ? t('trip.hiredDriver') : t('trip.driverSuggested')">
                    <select v-model="form.driver_id" class="sel" :required="!hired" :disabled="!vehicleChangeable">
                        <option value="">{{ hired ? t('trip.noDriver') : t('trip.chooseDriver') }}</option>
                        <option v-for="d in drivers" :key="d.id" :value="d.id" :disabled="!!d.booked">{{ driverLabel(d) }}</option>
                    </select>
                </Field>

                <Field :label="t('trip.loadingAt')" :error="form.errors.loading_at"><input v-model="form.loading_at" type="datetime-local" dir="ltr" required></Field>
                <ListSelect v-model="form.cargo_type_id" :label="t('trip.cargo')" :placeholder="t('trip.chooseCargo')" :options="options?.cargo_types ?? []"
                            :add-url="route('office.cargo_types.store')" :can-add="can('trips.create')" :error="form.errors.cargo_type_id" />
                <Field :label="t('trip.weight')" :hint="t('trip.weightHint')" :error="form.errors.weight_tons">
                    <input v-model="form.weight_tons" type="number" min="0" max="1000" step="any" dir="ltr">
                </Field>

                <Field :label="t('trip.freight')" :error="form.errors.freight_price"
                       :hint="agreed != null && Number(form.freight_price) === Number(agreed) ? t('trip.priceFromCard') : (!priceEditable ? t('trip.priceLocked') : '')">
                    <input v-model="form.freight_price" type="number" min="0" step="any" dir="ltr" :readonly="!priceEditable && (editing || agreed != null)" :required="agreed == null">
                </Field>
                <Field v-if="!hired" :label="t('trip.custody')" :error="form.errors.custody_planned"
                       :hint="route_ ? t('trip.custodyHint', { amount: money(route_.custody), buffer: num(options.defaults.buffer) }) : ''">
                    <input v-model="form.custody_planned" type="number" min="0" step="any" dir="ltr">
                </Field>
                <Field v-else-if="!editing" :label="t('trip.hireFee')" :hint="t('trip.hireFeeHint')" :error="form.errors.hire_fee">
                    <input v-model="form.hire_fee" type="number" min="0" step="any" dir="ltr">
                </Field>

                <template v-if="!editing && can('trips.edit_policy')">
                    <Field :label="t('trip.policy')" :error="form.errors.transfer_policy">
                        <select v-model="form.transfer_policy" class="sel">
                            <option v-for="p in ['approval', 'limit', 'auto']" :key="p" :value="p">{{ t('trip.policies.' + p, { limit: money(form.auto_transfer_limit) }) }}</option>
                        </select>
                    </Field>
                    <Field v-if="form.transfer_policy === 'limit'" :label="t('trip.limit')" :error="form.errors.auto_transfer_limit">
                        <input v-model="form.auto_transfer_limit" type="number" min="0" step="any" dir="ltr">
                    </Field>
                </template>
            </div>

            <label class="cb" style="margin-top:12px"><input v-model="form.client_pays_cash" type="checkbox"> {{ t('trip.clientPaysCash') }}</label>
            <Field :label="t('trip.notes')" :error="form.errors.notes"><textarea v-model="form.notes" maxlength="2000" /></Field>

            <div v-if="route_" class="kv" style="margin-top:12px">
                <div><span>{{ t('trip.km') }}</span><b class="num">{{ num(route_.km) }}</b></div>
                <div><span>{{ t('trip.standard') }}</span><b class="num">{{ money(route_.standard) }}</b></div>
                <div><span>{{ t('trip.expected') }}</span><b class="num" :class="expected == null ? '' : expected >= 0 ? 'gn' : 'rd'">{{ expected == null ? '—' : money(expected) }}</b></div>
            </div>
            <div v-if="hired" class="note am" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('trip.hireFeeHint') }}</div></div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" form="trip-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ editing ? t('common.save') : t('trip.create') }}</button>
        </template>
    </Modal>
</template>
