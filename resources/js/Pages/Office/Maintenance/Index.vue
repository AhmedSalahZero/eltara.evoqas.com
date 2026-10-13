<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Maintenance log ( /office/maintenance )
  Location: resources/js/Pages/Office/Maintenance/Index.vue

  Open jobs hold the truck (it cannot be booked on a new trip).
  Due is the latest finished periodic service for each truck and
  item, compared with the odometer and today.
  The log is one month of jobs. Their cost is not a trip expense
  and not a G&A line — the maintenance report shows the same jobs.
  Server: App\Http\Controllers\Office\MaintenanceController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import MaintenanceForm from '@/Components/MaintenanceForm.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, inputValue, money, nowInput, num, time } from '@/Utils/format';

const props = defineProps({
    month: String, months: Array, tab: String, vehicle: [Number, null], totals: Object,
    openJobs: Array, due: Array, log: Array, vehicles: Array,
});
const { t } = useI18n();
const { can } = usePermissions();

const go = (extra = {}) => router.get(route('office.maintenance.index'), {
    month: props.month, tab: props.tab, vehicle: props.vehicle || undefined, ...extra,
}, { preserveScroll: true, preserveState: false });

const open = ref(false);
const editing = ref(null);
const confirming = ref(null);

const blank = () => ({
    vehicle_id: props.vehicle ?? '', kind: 'periodic', category: 'oil', status: 'open', opened_at: nowInput(), completed_at: '',
    odometer_km: '', cost: '', workshop: '', note: '', interval_km: '', interval_days: '',
});

const form = useForm(blank());

function openForm(entry = null, preset = null) {
    editing.value = entry;
    form.clearErrors();

    if (entry) {
        form.defaults({
            vehicle_id: entry.vehicle.id, kind: entry.kind, category: entry.category, status: entry.status,
            opened_at: inputValue(entry.opened_at), completed_at: entry.completed_at ? inputValue(entry.completed_at) : '',
            odometer_km: entry.odometer_km ?? '', cost: entry.cost ?? '', workshop: entry.workshop ?? '', note: entry.note ?? '',
            interval_km: entry.interval_km ?? '', interval_days: entry.interval_days ?? '',
        });
    } else {
        const status = preset?.status ?? 'open';
        form.defaults({
            ...blank(),
            vehicle_id: preset?.vehicle_id ?? props.vehicle ?? '',
            kind: preset?.kind ?? 'periodic',
            category: preset?.category ?? 'oil',
            status,
            completed_at: status === 'done' ? nowInput() : '',
        });
    }
    form.reset();
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const clean = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    const payload = (d) => clean({
        vehicle_id: d.vehicle_id, kind: d.kind, category: d.category, status: d.status, opened_at: d.opened_at,
        completed_at: d.status === 'done' ? d.completed_at : null, odometer_km: d.odometer_km, cost: d.cost,
        workshop: d.workshop, note: d.note, interval_km: d.kind === 'periodic' ? d.interval_km : null, interval_days: d.kind === 'periodic' ? d.interval_days : null,
    });

    if (editing.value) {
        form.transform(payload).patch(route('office.maintenance.update', editing.value.id), options);
    } else {
        form.transform(payload).post(route('office.maintenance.store'), options);
    }
}

const remove = () => router.delete(route('office.maintenance.destroy', confirming.value.id), { preserveScroll: true, onFinish: () => (confirming.value = null) });

const tabs = computed(() => [['open', props.totals.open || null], ['due', props.totals.due || null], ['log', null]]);
const canWrite = computed(() => can('maintenance.create') || can('maintenance.edit'));

function nextText(row) {
    const parts = [];
    if (row.next_due_km != null) parts.push(`${num(row.next_due_km)} ${t('maintenance.km')}`);
    if (row.next_due_on) parts.push(date(row.next_due_on));
    return parts.join(' · ') || '—';
}
</script>

<template>
    <PortalLayout :title="t('maintenance.title')">
        <PageHeader :title="t('maintenance.title')" :sub="t('maintenance.sub')">
            <select class="sel" :value="month" @change="go({ month: $event.target.value })">
                <option v-for="m in months" :key="m.key" :value="m.key">{{ m.key }}</option>
            </select>
            <button v-if="can('maintenance.create')" class="btn btn-cu" @click="openForm()"><AppIcon name="plus" /> {{ t('maintenance.add') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('maintenance.openNow')" :value="num(totals.open)" :color="totals.open ? 'var(--am)' : 'var(--gn)'" :foot="t('maintenance.openFoot')" />
            <Kpi :label="t('maintenance.dueNow')" :value="num(totals.due)" :color="totals.due ? 'var(--rd)' : 'var(--gn)'" :foot="t('maintenance.dueFoot')" />
            <Kpi :label="t('maintenance.jobsMonth')" :value="num(totals.jobs)" color="var(--bl)" />
            <Kpi :label="t('maintenance.costMonth')" :value="money(totals.cost)" unit="EGP" color="var(--am)" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="[key, count] in tabs" :key="key" class="tab" :class="{ on: tab === key }" @click="go({ tab: key })">
                {{ t('maintenance.tabs.' + key) }}<span v-if="count" class="c">{{ count }}</span>
            </button>
        </div>

        <div v-if="vehicle" class="chips" style="margin-bottom:12px">
            <button class="chip on" @click="go({ vehicle: undefined })"><AppIcon name="x" :size="13" />
                <template v-for="v in vehicles.filter((x) => x.id === vehicle)" :key="v.id">{{ v.number }} {{ v.letters }}</template>
            </button>
        </div>

        <div v-if="tab === 'open'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('maintenance.cols.when') }}</th><th>{{ t('maintenance.cols.truck') }}</th><th>{{ t('maintenance.cols.kind') }}</th>
                    <th>{{ t('maintenance.cols.category') }}</th><th>{{ t('maintenance.cols.note') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-if="!openJobs.length"><td colspan="6"><div class="empty" style="border:0">{{ t('maintenance.noneOpen') }}</div></td></tr>
                    <tr v-for="e in openJobs" :key="e.id">
                        <td class="num sm">{{ date(e.opened_at) }}<div class="xs mu">{{ time(e.opened_at) }}</div></td>
                        <td><button type="button" style="background:none;border:0;padding:0;cursor:pointer" @click="go({ vehicle: e.vehicle.id })"><Plate :number="e.vehicle.number" :letters="e.vehicle.letters" /></button></td>
                        <td><span class="bd nodot" :class="e.kind === 'emergency' ? 'rd' : 'bl'">{{ t('maintenance.kinds.' + e.kind) }}</span></td>
                        <td>{{ t('maintenance.categories.' + e.category) }}</td>
                        <td class="sm">{{ e.note || '—' }}<div v-if="e.workshop" class="xs mu">{{ e.workshop }}</div></td>
                        <td class="e" style="white-space:nowrap">
                            <button v-if="can('maintenance.edit')" class="ib" :title="t('common.edit')" @click="openForm(e)"><AppIcon name="edit" /></button>
                            <button v-if="can('maintenance.delete')" class="ib" :title="t('common.delete')" @click="confirming = e"><AppIcon name="trash" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="tab === 'due'">
            <p class="xs mu" style="margin:-6px 0 12px">{{ t('maintenance.dueHint') }}</p>
            <div class="tw">
                <table>
                    <thead><tr>
                        <th>{{ t('maintenance.cols.truck') }}</th><th>{{ t('maintenance.cols.category') }}</th><th>{{ t('maintenance.cols.state') }}</th>
                        <th>{{ t('maintenance.cols.next') }}</th><th class="e">{{ t('maintenance.cols.odometer') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!due.length"><td colspan="6"><div class="empty" style="border:0">{{ t('maintenance.noneDue') }}</div></td></tr>
                        <tr v-for="e in due" :key="e.id">
                            <td><button type="button" style="background:none;border:0;padding:0;cursor:pointer" @click="go({ vehicle: e.vehicle.id })"><Plate :number="e.vehicle.number" :letters="e.vehicle.letters" /></button></td>
                            <td>{{ t('maintenance.categories.' + e.category) }}</td>
                            <td><span class="bd nodot" :class="e.state === 'overdue' ? 'rd' : 'am'">{{ t('maintenance.states.' + e.state) }}</span></td>
                            <td class="num sm">{{ nextText(e) }}</td>
                            <td class="e num">{{ e.vehicle.odometer == null ? '—' : num(e.vehicle.odometer) }}</td>
                            <td class="e"><button v-if="can('maintenance.create')" class="btn btn-ln btn-sm" @click="openForm(null, { vehicle_id: e.vehicle.id, kind: 'periodic', category: e.category, status: 'done' })">{{ t('maintenance.recordDue') }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div v-if="tab === 'log'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('maintenance.cols.when') }}</th><th>{{ t('maintenance.cols.truck') }}</th><th>{{ t('maintenance.cols.kind') }}</th>
                    <th>{{ t('maintenance.cols.category') }}</th><th>{{ t('maintenance.cols.status') }}</th><th class="e">{{ t('maintenance.cols.cost') }}</th>
                    <th class="e">{{ t('maintenance.cols.odometer') }}</th><th>{{ t('maintenance.cols.state') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-if="!log.length"><td colspan="9"><div class="empty" style="border:0">{{ t('maintenance.noneLog') }}</div></td></tr>
                    <tr v-for="e in log" :key="e.id">
                        <td class="num sm">{{ date(e.opened_at) }}</td>
                        <td><Plate :number="e.vehicle.number" :letters="e.vehicle.letters" /><div v-if="e.workshop" class="xs mu">{{ e.workshop }}</div></td>
                        <td>{{ t('maintenance.kinds.' + e.kind) }}</td>
                        <td>{{ t('maintenance.categories.' + e.category) }}</td>
                        <td><span class="bd nodot" :class="e.status === 'open' ? 'am' : 'gn'">{{ t('maintenance.statuses.' + e.status) }}</span></td>
                        <td class="e num">{{ e.cost == null ? '—' : money(e.cost) }}</td>
                        <td class="e num">{{ e.odometer_km == null ? '—' : num(e.odometer_km) }}</td>
                        <td><span v-if="e.state" class="bd nodot" :class="e.state === 'overdue' ? 'rd' : (e.state === 'soon' ? 'am' : 'gn')">{{ t('maintenance.states.' + e.state) }}</span><span v-else class="mu">—</span></td>
                        <td class="e" style="white-space:nowrap">
                            <button v-if="can('maintenance.edit')" class="ib" :title="t('common.edit')" @click="openForm(e)"><AppIcon name="edit" /></button>
                            <button v-if="can('maintenance.delete')" class="ib" :title="t('common.delete')" @click="confirming = e"><AppIcon name="trash" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="open" :title="editing ? t('maintenance.editTitle') : t('maintenance.addTitle')" wide @close="open = false">
            <form id="maintenance-form" @submit.prevent="save">
                <MaintenanceForm :form="form" :vehicles="vehicles" />
                <p v-if="form.status === 'open'" class="xs mu" style="margin:8px 0 0">{{ t('maintenance.holdsTruck') }}</p>
                <p class="xs mu" style="margin:8px 0 0">{{ t('maintenance.costOutside') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="open = false">{{ t('common.cancel') }}</button>
                <button v-if="canWrite" type="submit" form="maintenance-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!confirming" :title="t('common.delete')" :message="t('maintenance.deleteConfirm')" danger @confirm="remove" @cancel="confirming = null" />
    </PortalLayout>
</template>
