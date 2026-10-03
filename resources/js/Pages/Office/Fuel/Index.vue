<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Fuel log ( /office/fuel )
  Location: resources/js/Pages/Office/Fuel/Index.vue

  Scope §6.10, one month at a time:
    tiles  litres · cost · fleet average km/L · share paid by the
           company fuel card · trucks flagged for possible over-draw
    tabs   Refuels (each fill-up with its km/L)
           Per truck (km/L against the truck's standard)
           Waiting for litres (fuel the drivers paid on trips that has
           no litres / odometer yet — pick one to complete it)
  Add / change / delete a refuel with the form. A refuel on a trip is
  the same money as the trip's fuel expense (counted once).
  Server: App\Http\Controllers\Office\FuelController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, inputValue, money, nowInput, num, time } from '@/Utils/format';

const props = defineProps({
    month: String, months: Array, tab: String, vehicle: [Number, null], totals: Object, trucks: Array, entries: Array,
    waiting: Array, options: Object, vehicles: Array,
});
const { t } = useI18n();
const { can } = usePermissions();

const go = (extra = {}) => router.get(route('office.fuel.index'), { month: props.month, tab: props.tab, vehicle: props.vehicle || undefined, ...extra }, { preserveScroll: true, preserveState: false });
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');

// ── The form ────────────────────────────────────────────────────
const open = ref(false);
const editing = ref(null);      // a refuel being changed
const fromExpense = ref(null);  // a waiting trip expense being completed
const confirming = ref(null);

const localNow = () => nowInput();
const toLocal = (iso) => inputValue(iso);

const form = useForm({
    vehicle_id: '', driver_id: '', trip_id: '', trip_expense_id: '', filled_at: localNow(), litres: '', price_per_litre: '', amount: '',
    odometer_km: '', station: '', paid_by: 'card', note: '',
});

function openForm(entry = null, expense = null) {
    editing.value = entry;
    fromExpense.value = expense;
    form.clearErrors();

    if (entry) {
        form.defaults({
            vehicle_id: entry.vehicle.id, driver_id: '', trip_id: entry.trip?.id ?? '', trip_expense_id: '', filled_at: toLocal(entry.filled_at),
            litres: entry.litres, price_per_litre: entry.price_per_litre ?? '', amount: entry.amount, odometer_km: entry.odometer_km ?? '',
            station: entry.station ?? '', paid_by: entry.paid_by, note: entry.note ?? '',
        });
    } else if (expense) {
        form.defaults({
            vehicle_id: expense.vehicle.id, driver_id: '', trip_id: expense.trip.id, trip_expense_id: expense.id, filled_at: toLocal(expense.spent_at),
            litres: '', price_per_litre: '', amount: expense.amount, odometer_km: '', station: '', paid_by: expense.paid_by, note: '',
        });
    } else {
        form.defaults({
            vehicle_id: props.vehicle ?? '', driver_id: '', trip_id: '', trip_expense_id: '', filled_at: localNow(), litres: '', price_per_litre: '', amount: '',
            odometer_km: '', station: '', paid_by: 'card', note: '',
        });
    }
    form.reset();
    open.value = true;
}

// Choosing a trip fixes the truck and suggests its driver.
watch(() => form.trip_id, (id) => {
    const trip = props.options?.trips.find((x) => x.id === Number(id));
    if (trip && !editing.value && !fromExpense.value) {
        form.vehicle_id = trip.vehicle_id;
        form.driver_id = trip.driver_id ?? '';
    }
    if (!id && form.paid_by === 'custody') form.paid_by = 'card';
});
watch(() => form.vehicle_id, (id) => {
    const v = props.options?.vehicles.find((x) => x.id === Number(id));
    if (v && !editing.value && !fromExpense.value && !form.trip_id) form.driver_id = v.driver_id ?? '';
});

const tripsOfTruck = computed(() => (props.options?.trips ?? []).filter((x) => !form.vehicle_id || x.vehicle_id === Number(form.vehicle_id)));
const chosenTruck = computed(() => props.options?.vehicles.find((x) => x.id === Number(form.vehicle_id)));

// What the form will work out, shown while typing.
const preview = computed(() => {
    const litres = parseFloat(form.litres);
    const price = parseFloat(form.price_per_litre);
    const amount = parseFloat(form.amount);
    const diesel = props.options?.diesel ?? 0;
    if (litres > 0 && price > 0 && !(amount > 0)) return t('fuel.calcAmount', { amount: money(litres * price, 2) });
    if (litres > 0 && amount > 0) return t('fuel.calcPrice', { price: num(amount / litres, 2) });
    if (amount > 0 && !(litres > 0)) return t('fuel.calcLitres', { litres: num(amount / (price > 0 ? price : diesel), 2), price: num(price > 0 ? price : diesel, 2) });
    return '';
});

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const clean = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));

    if (editing.value) {
        form.transform((d) => clean({ filled_at: d.filled_at, litres: d.litres, price_per_litre: d.price_per_litre, amount: d.amount, odometer_km: d.odometer_km, station: d.station, note: d.note }))
            .patch(route('office.fuel.update', editing.value.id), options);
    } else {
        form.transform(clean).post(route('office.fuel.store'), options);
    }
}

const remove = () => router.delete(route('office.fuel.destroy', confirming.value.id), { preserveScroll: true, onFinish: () => (confirming.value = null) });

const tabs = computed(() => [['refuels', props.totals.fills], ['trucks', props.totals.flagged || null], ['waiting', props.totals.waiting || null]]);
const showTruck = (id) => go({ vehicle: id, tab: 'refuels' });
</script>

<template>
    <PortalLayout :title="t('fuel.title')">
        <PageHeader :title="t('fuel.title')" :sub="t('fuel.sub')">
            <select class="sel" :value="month" @change="go({ month: $event.target.value })">
                <option v-for="m in months" :key="m.key" :value="m.key">{{ m.key }}</option>
            </select>
            <button v-if="can('fuel.create')" class="btn btn-cu" @click="openForm()"><AppIcon name="plus" /> {{ t('fuel.add') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('fuel.litres')" :value="num(totals.litres, 1)" unit="L" color="var(--bl)" :foot="t('fuel.refuelsN', { n: totals.fills })" />
            <Kpi :label="t('fuel.cost')" :value="money(totals.cost)" unit="EGP" color="var(--am)" :foot="totals.card_share == null ? '' : t('fuel.cardShareFoot', { n: num(totals.card_share, 1) })" />
            <Kpi :label="t('fuel.fleetKmpl')" :value="totals.kmpl == null ? '—' : num(totals.kmpl, 2)" unit="km/L" color="var(--gn)" :foot="totals.km ? t('fuel.kmCounted', { n: num(totals.km) }) : t('fuel.needOdometer')" />
            <Kpi :label="t('fuel.flagged')" :value="num(totals.flagged)" :color="totals.flagged ? 'var(--rd)' : 'var(--gn)'" :foot="t('fuel.flagRule', { n: num(totals.percent, 1) })" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="[key, count] in tabs" :key="key" class="tab" :class="{ on: tab === key }" @click="go({ tab: key })">
                {{ t('fuel.tabs.' + key) }}<span v-if="count" class="c">{{ count }}</span>
            </button>
        </div>

        <div v-if="vehicle" class="chips" style="margin-bottom:12px">
            <button class="chip on" @click="go({ vehicle: undefined })"><AppIcon name="x" :size="13" />
                <template v-for="v in vehicles.filter((x) => x.id === vehicle)" :key="v.id">{{ v.number }} {{ v.letters }}</template>
            </button>
        </div>

        <!-- Refuels -->
        <div v-if="tab === 'refuels'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('fuel.cols.when') }}</th><th>{{ t('fuel.cols.truck') }}</th><th class="e">{{ t('fuel.cols.litres') }}</th><th class="e">{{ t('fuel.cols.price') }}</th>
                    <th class="e">{{ t('fuel.cols.amount') }}</th><th>{{ t('fuel.cols.paidBy') }}</th><th class="e">{{ t('fuel.cols.odometer') }}</th><th class="e">{{ t('fuel.cols.kmpl') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-if="!entries.length"><td colspan="9"><div class="empty" style="border:0">{{ t('fuel.none') }}</div></td></tr>
                    <tr v-for="e in entries" :key="e.id">
                        <td class="num sm">{{ date(e.filled_at) }}<div class="xs mu">{{ time(e.filled_at) }}</div></td>
                        <td>
                            <Plate :number="e.vehicle.number" :letters="e.vehicle.letters" />
                            <div class="xs mu">{{ e.driver || '—' }}<template v-if="e.station"> · {{ e.station }}</template></div>
                            <Link v-if="e.trip && can('trips.view')" :href="route('office.trips.show', e.trip.id)" class="xs num" style="color:var(--cu)">{{ e.trip.number }}</Link>
                        </td>
                        <td class="e num">{{ num(e.litres, 1) }}<span v-if="e.litres_estimated" class="xs mu" :title="t('fuel.estimatedTip')"> ~</span></td>
                        <td class="e num">{{ e.price_per_litre == null ? '—' : num(e.price_per_litre, 2) }}</td>
                        <td class="e"><b class="num">{{ money(e.amount) }}</b></td>
                        <td><span class="bd nodot" :class="e.paid_by === 'card' ? 'bl' : 'am'">{{ t('fuel.paid.' + e.paid_by) }}</span></td>
                        <td class="e num">{{ e.odometer_km == null ? '—' : num(e.odometer_km) }}<div v-if="e.bad_odometer" class="xs rd">{{ t('fuel.badOdo') }}</div></td>
                        <td class="e">
                            <template v-if="e.kmpl != null"><b class="num" :class="e.flagged ? 'rd' : 'gn'">{{ num(e.kmpl, 2) }}</b><div v-if="e.flagged" class="xs rd">{{ t('fuel.overdraw') }}</div></template>
                            <span v-else class="mu">—</span>
                        </td>
                        <td class="e" style="white-space:nowrap">
                            <button v-if="can('fuel.edit')" class="ib" :title="t('common.edit')" @click="openForm(e)"><AppIcon name="edit" /></button>
                            <button v-if="can('fuel.delete')" class="ib" :title="t('common.delete')" @click="confirming = e"><AppIcon name="trash" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Per truck -->
        <template v-if="tab === 'trucks'">
            <p class="xs mu" style="margin:-6px 0 12px">{{ t('fuel.trucksHint', { n: num(totals.percent, 1) }) }}</p>
            <div class="tw">
                <table>
                    <thead><tr>
                        <th>{{ t('fuel.cols.truck') }}</th><th class="e">{{ t('fuel.cols.standard') }}</th><th class="e">{{ t('fuel.cols.refuels') }}</th><th class="e">{{ t('fuel.cols.litres') }}</th>
                        <th class="e">{{ t('fuel.cols.amount') }}</th><th class="e">{{ t('fuel.cols.kmKnown') }}</th><th class="e">{{ t('fuel.cols.kmpl') }}</th><th class="e">{{ t('fuel.cols.vsStandard') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!trucks.length"><td colspan="9"><div class="empty" style="border:0">{{ t('fuel.none') }}</div></td></tr>
                        <tr v-for="x in trucks" :key="x.id" class="ck" @click="showTruck(x.id)">
                            <td><Plate :number="x.number" :letters="x.letters" /></td>
                            <td class="e num">{{ x.standard == null ? '—' : num(x.standard, 2) }}</td>
                            <td class="e num">{{ x.fills }}</td>
                            <td class="e num">{{ num(x.litres, 1) }}</td>
                            <td class="e num">{{ money(x.cost) }}</td>
                            <td class="e num">{{ x.km ? num(x.km) : '—' }}</td>
                            <td class="e"><b class="num">{{ x.kmpl == null ? '—' : num(x.kmpl, 2) }}</b></td>
                            <td class="e num" :class="x.variance == null ? 'mu' : x.flagged ? 'rd' : 'gn'">{{ x.variance == null ? '—' : (x.variance > 0 ? '+' : '') + num(x.variance, 1) + '%' }}</td>
                            <td class="e">
                                <span v-if="x.flagged" class="bd rd">{{ t('fuel.overdraw') }}</span>
                                <span v-else-if="x.kmpl != null && x.standard != null" class="bd gn nodot">{{ t('fuel.normal') }}</span>
                                <span v-else class="bd pl nodot">{{ x.standard == null ? t('fuel.noStandard') : t('fuel.noData') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Waiting for litres -->
        <template v-if="tab === 'waiting'">
            <p class="xs mu" style="margin:-6px 0 12px">{{ t('fuel.waitingHint') }}</p>
            <div class="tw">
                <table>
                    <thead><tr>
                        <th>{{ t('fuel.cols.trip') }}</th><th>{{ t('fuel.cols.truck') }}</th><th>{{ t('fuel.cols.when') }}</th><th class="e">{{ t('fuel.cols.amount') }}</th><th>{{ t('fuel.cols.paidBy') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!waiting.length"><td colspan="6"><div class="empty" style="border:0">{{ t('fuel.noneWaiting') }}</div></td></tr>
                        <tr v-for="w in waiting" :key="w.id">
                            <td><Link v-if="can('trips.view')" :href="route('office.trips.show', w.trip.id)" class="num b" style="color:var(--cu)">{{ w.trip.number }}</Link><b v-else class="num">{{ w.trip.number }}</b><div class="xs mu">{{ w.driver || '—' }}</div></td>
                            <td><Plate :number="w.vehicle.number" :letters="w.vehicle.letters" /></td>
                            <td class="num sm">{{ when(w.spent_at) }}</td>
                            <td class="e"><b class="num">{{ money(w.amount) }}</b></td>
                            <td><span class="bd nodot" :class="w.paid_by === 'card' ? 'bl' : 'am'">{{ t('fuel.paid.' + w.paid_by) }}</span></td>
                            <td class="e"><button v-if="can('fuel.create')" class="btn btn-ln btn-sm" @click="openForm(null, w)"><AppIcon name="plus" /> {{ t('fuel.addLitres') }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Form -->
        <Modal :show="open" :title="editing ? t('fuel.editTitle') : fromExpense ? t('fuel.completeTitle') : t('fuel.addTitle')" wide @close="open = false">
            <div v-if="fromExpense" class="note" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('fuel.fromExpenseNote', { trip: fromExpense.trip.number, amount: money(fromExpense.amount) }) }}</div></div>
            <div v-else-if="editing?.linked_expense" class="note" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('fuel.amountLocked') }}</div></div>
            <form id="fuel-form" @submit.prevent="save">
                <div class="fgrid">
                    <template v-if="!editing && !fromExpense && options">
                        <Field :label="t('fuel.f.trip')" :hint="t('common.optional')" :error="form.errors.trip_id">
                            <select v-model="form.trip_id"><option value="">{{ t('fuel.f.noTrip') }}</option><option v-for="x in tripsOfTruck" :key="x.id" :value="x.id">{{ x.number }}</option></select>
                        </Field>
                        <Field :label="t('fuel.f.truck')" :error="form.errors.vehicle_id">
                            <select v-model="form.vehicle_id" :disabled="!!form.trip_id" required>
                                <option value="" disabled>{{ t('common.choose') }}</option>
                                <option v-for="v in options.vehicles" :key="v.id" :value="v.id">{{ v.number }} {{ v.letters }}</option>
                            </select>
                        </Field>
                        <Field :label="t('fuel.f.driver')" :hint="t('common.optional')" :error="form.errors.driver_id">
                            <select v-model="form.driver_id" :disabled="!!form.trip_id"><option value="">—</option><option v-for="d in options.drivers" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                        </Field>
                    </template>
                    <Field :label="t('fuel.f.when')" :error="form.errors.filled_at"><input v-model="form.filled_at" type="datetime-local" dir="ltr" required></Field>
                    <Field :label="t('fuel.f.litres')" :error="form.errors.litres"><input v-model="form.litres" type="number" min="0.01" step="any" dir="ltr"></Field>
                    <Field :label="t('fuel.f.price')" :hint="options ? t('fuel.f.priceHint', { n: num(options.diesel, 2) }) : ''" :error="form.errors.price_per_litre"><input v-model="form.price_per_litre" type="number" min="0.01" step="any" dir="ltr"></Field>
                    <Field :label="t('fuel.f.amount')" :error="form.errors.amount"><input v-model="form.amount" type="number" min="0.01" step="any" dir="ltr" :disabled="!!fromExpense || !!editing?.linked_expense"></Field>
                    <Field :label="t('fuel.f.odometer')" :hint="chosenTruck?.odometer ? t('fuel.f.lastOdo', { n: num(chosenTruck.odometer) }) : t('common.optional')" :error="form.errors.odometer_km"><input v-model="form.odometer_km" type="number" min="0" step="1" dir="ltr"></Field>
                    <Field :label="t('fuel.f.station')" :hint="t('common.optional')" :error="form.errors.station"><input v-model="form.station" maxlength="120"></Field>
                </div>
                <p v-if="preview" class="xs mu" style="margin:6px 0 0">{{ preview }}</p>

                <div v-if="!editing && !fromExpense" class="fld" style="margin-top:12px"><span class="fl">{{ t('fuel.f.paidBy') }}</span>
                    <div class="opts">
                        <label class="opt"><input v-model="form.paid_by" type="radio" value="card"><div><b>{{ t('fuel.paid.card') }}</b><span>{{ t('fuel.f.cardSub') }}</span></div></label>
                        <label class="opt"><input v-model="form.paid_by" type="radio" value="custody" :disabled="!form.trip_id"><div><b>{{ t('fuel.paid.custody') }}</b><span>{{ t('fuel.f.custodySub') }}</span></div></label>
                    </div>
                    <span v-if="form.errors.paid_by" class="err">{{ form.errors.paid_by }}</span>
                </div>
                <p v-if="form.trip_id && !editing && !fromExpense" class="xs mu" style="margin:8px 0 0">{{ t('fuel.f.tripNote') }}</p>
                <Field :label="t('fuel.f.note')" :hint="t('common.optional')" :error="form.errors.note"><input v-model="form.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="open = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="fuel-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!confirming" :title="t('common.delete')" :message="t('fuel.deleteConfirm')" danger @confirm="remove" @cancel="confirming = null" />
    </PortalLayout>
</template>
