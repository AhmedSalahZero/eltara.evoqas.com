<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Month close & true profit ( /office/close )
  Location: resources/js/Pages/Office/Close/Index.vue

  Scope §6.13 and §10.
    · month chips (closed / re-opened / open)
    · G&A lines of the month: add, change, delete, import from Excel
    · the allocation flow:  G&A ÷ own-fleet km = EGP per km
    · month revenue, direct profit and TRUE profit
    · every trip of the month with its km, G&A share and true profit
      (trips split between two months and trips still on the road
      are flagged)
    · Close the month (locks it) · Re-open it (reason required)
  Server: App\Http\Controllers\Office\MonthCloseController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import TripStatus from '@/Components/TripStatus.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num } from '@/Utils/format';

const props = defineProps({ months: Array, summary: Object, standard: Array });
const { t } = useI18n();
const { can } = usePermissions();

const go = (month) => router.get(route('office.close.index'), { month }, { preserveScroll: true });
const locked = () => props.summary.closed;

// ── G&A lines ───────────────────────────────────────────────────
const lineOpen = ref(false);
const editingLine = ref(null);
const line = useForm({ month: '', code: '', label: '', amount: '' });
const OWN = '__own';

function openLine(l = null) {
    editingLine.value = l;
    line.clearErrors();
    line.month = props.summary.month;
    line.code = l ? (l.code ?? OWN) : '';
    line.label = l && !l.code ? l.label : '';
    line.amount = l ? l.amount : '';
    lineOpen.value = true;
}
function saveLine() {
    const options = { preserveScroll: true, onSuccess: () => (lineOpen.value = false) };
    line.transform((d) => ({ month: d.month, code: d.code === OWN || d.code === '' ? null : d.code, label: d.code === OWN || d.code === '' ? d.label : null, amount: d.amount }));
    editingLine.value
        ? line.patch(route('office.close.lines.update', editingLine.value.id), options)
        : line.post(route('office.close.lines.store'), options);
}
const delLine = ref(null);
const doDelLine = () => router.delete(route('office.close.lines.destroy', delLine.value.id), { preserveScroll: true, onFinish: () => (delLine.value = null) });

// ── Import ──────────────────────────────────────────────────────
const importOpen = ref(false);
const imp = useForm({ month: '', file: null, replace: false });
function openImport() { imp.reset(); imp.clearErrors(); imp.month = props.summary.month; importOpen.value = true; }
function saveImport() { imp.post(route('office.close.import'), { forceFormData: true, preserveScroll: true, onSuccess: () => (importOpen.value = false) }); }

// ── Close / re-open ─────────────────────────────────────────────
const closing = ref(false);
const doClose = () => router.post(route('office.close.run'), { month: props.summary.month }, { preserveScroll: true, onFinish: () => (closing.value = false) });
const reopenOpen = ref(false);
const reopen = useForm({ month: '', reason: '' });
function openReopen() { reopen.reset(); reopen.clearErrors(); reopen.month = props.summary.month; reopenOpen.value = true; }
function saveReopen() { reopen.post(route('office.close.reopen'), { preserveScroll: true, onSuccess: () => (reopenOpen.value = false) }); }

const stateColor = { closed: 'gn', reopened: 'am', open: 'pl' };
const profitColor = (v) => (v == null ? 'var(--mu)' : v < 0 ? 'var(--rd)' : 'var(--gn)');
</script>

<template>
    <PortalLayout :title="t('close.title')">
        <PageHeader :title="t('close.title')" :sub="t('close.sub')">
            <a class="btn btn-ln" :href="route('office.close.export', { month: summary.month })"><AppIcon name="download" /> {{ t('close.export') }}</a>
        </PageHeader>

        <div class="chips" style="margin-bottom:16px">
            <button v-for="m in months" :key="m.key" class="chip" :class="{ on: m.key === summary.month }" @click="go(m.key)">
                {{ m.key }} <span class="bd nodot" :class="stateColor[m.state]" style="margin-inline-start:6px">{{ t('close.status.' + m.state) }}</span>
            </button>
        </div>

        <!-- Status banner -->
        <div v-if="summary.closed" class="banner">
            <AppIcon name="lock" />
            <div><b>{{ t('close.closedBanner', { month: summary.month }) }}</b>
                <span class="xs">{{ t('close.closedBy', { name: summary.closed_by || '—', d: summary.closed_at ? date(summary.closed_at) : '' }) }}</span></div>
            <button v-if="can('month_close.reopen')" class="btn btn-ln btn-sm" @click="openReopen"><AppIcon name="unlock" /> {{ t('close.reopen') }}</button>
        </div>
        <div v-else-if="summary.reopen" class="note am"><AppIcon name="info" /><div>{{ t('close.reopenedNote', { n: summary.reopen.count, reason: summary.reopen.reason }) }}</div></div>

        <!-- Allocation flow -->
        <div class="pn" style="margin-top:6px">
            <div class="pn-h"><b>{{ t('close.flowTitle') }}</b>
                <span class="xs mu">{{ t('close.flowSub', { rule: t('close.rules.' + summary.rule), basis: t('close.basis.' + summary.basis) }) }}</span></div>
            <div class="alloc-flow">
                <div><span>{{ t('close.gaTotal') }}</span><b>{{ money(summary.ga_total) }}</b></div>
                <div><span class="op">÷</span><span>{{ t(summary.basis === 'all_km' ? 'close.kmAll' : 'close.kmOwn') }}</span><b>{{ num(summary.km) }} <small>km</small></b></div>
                <div><span class="op">=</span><span>{{ t('close.rate') }}</span><b>{{ summary.rate == null ? '—' : num(summary.rate, 2) }} <small>{{ t('close.perKm') }}</small></b></div>
            </div>
            <div v-if="!summary.closed && summary.blockers.length" class="note am" style="margin-top:12px"><AppIcon name="info" />
                <div>{{ t('close.cannotClose') }} {{ summary.blockers.map((b) => t('close.blockers.' + b)).join(' · ') }}</div></div>
            <div v-if="can('month_close.approve') && !summary.closed" style="margin-top:12px;text-align:end">
                <button class="btn btn-cu" :disabled="!summary.can_close" @click="closing = true"><AppIcon name="lock" /> {{ t('close.closeMonth') }}</button>
            </div>
        </div>

        <div class="g3" style="margin-top:16px">
            <Kpi :label="t('close.revenue')" :value="money(summary.figures.revenue)" unit="EGP" color="var(--bl)" :foot="t('close.tripsDelivered', { n: summary.figures.trips })" />
            <Kpi :label="t('close.direct')" :value="money(summary.figures.direct_profit)" unit="EGP" :color="profitColor(summary.figures.direct_profit)" :foot="t('close.directFoot')" />
            <Kpi :label="t('close.true')" :value="money(summary.figures.true_profit)" unit="EGP" :color="profitColor(summary.figures.true_profit)" :foot="t('close.trueFoot')" />
        </div>

        <!-- G&A lines -->
        <div class="pn" style="margin-top:16px">
            <div class="pn-h"><b>{{ t('close.linesTitle') }}</b>
                <span v-if="!locked() && can('month_close.create')" style="display:flex;gap:8px;flex-wrap:wrap">
                    <a class="btn btn-ln btn-sm" :href="route('office.close.template')"><AppIcon name="download" /> {{ t('close.template') }}</a>
                    <button class="btn btn-ln btn-sm" @click="openImport"><AppIcon name="file" /> {{ t('close.import') }}</button>
                    <button class="btn btn-cu btn-sm" @click="openLine()"><AppIcon name="plus" /> {{ t('close.addLine') }}</button>
                </span>
            </div>
            <div v-if="!summary.lines.length" class="empty" style="border:0">{{ t('close.noLines') }}</div>
            <div v-for="l in summary.lines" :key="l.id" class="kv">
                <span>{{ l.label }}</span>
                <b class="num">{{ money(l.amount) }}
                    <template v-if="!locked() && can('month_close.create')">
                        <button class="ib" :title="t('common.edit')" @click="openLine(l)"><AppIcon name="edit" /></button>
                        <button class="ib" :title="t('common.delete')" @click="delLine = l"><AppIcon name="trash" /></button>
                    </template>
                </b>
            </div>
            <div v-if="summary.lines.length" class="kv" style="font-weight:800"><span>{{ t('common.total') }}</span><b class="num">{{ money(summary.ga_total) }}</b></div>
            <p v-if="locked()" class="xs mu" style="margin:8px 0 0">{{ t('close.linesLocked') }}</p>
        </div>

        <!-- Trips -->
        <div class="pn" style="margin-top:16px;padding:0">
            <div class="pn-h" style="padding:14px 16px 0"><b>{{ t('close.tripsTitle') }}</b>
                <span class="xs mu">{{ t('close.tripsSub', { split: summary.split, running: summary.running }) }}</span></div>
            <div class="tw" style="border:0">
                <table>
                    <thead><tr>
                        <th>{{ t('close.cols.trip') }}</th><th>{{ t('close.cols.customer') }}</th><th class="e">{{ t('close.cols.kmTrip') }}</th><th class="e">{{ t('close.cols.kmMonth') }}</th>
                        <th class="e">{{ t('close.cols.ga') }}</th><th class="e">{{ t('close.cols.direct') }}</th><th class="e">{{ t('close.cols.true') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!summary.rows.length"><td colspan="7"><div class="empty" style="border:0">{{ t('close.noTrips') }}</div></td></tr>
                        <tr v-for="r in summary.rows" :key="r.id">
                            <td>
                                <Link v-if="can('trips.view')" :href="route('office.trips.show', r.id)" class="num b" style="color:var(--cu)">{{ r.number }}</Link><b v-else class="num">{{ r.number }}</b>
                                <div style="display:flex;gap:6px;align-items:center;margin-top:3px;flex-wrap:wrap">
                                    <TripStatus :status="r.status" />
                                    <span v-if="r.split" class="bd nodot vi" :title="t('close.splitTip')">{{ t('close.split') }}</span>
                                    <span v-if="r.running" class="bd nodot bl" :title="t('close.runningTip')">{{ t('close.running') }}</span>
                                    <span v-if="r.is_hired" class="bd nodot pl">{{ t('close.hired') }}</span>
                                </div>
                            </td>
                            <td class="sm">{{ r.customer }}<div v-if="r.vehicle"><Plate :number="r.vehicle.number" :letters="r.vehicle.letters" /></div></td>
                            <td class="e num">{{ r.km == null ? '—' : num(r.km) }}</td>
                            <td class="e num"><b>{{ num(r.km_month) }}</b></td>
                            <td class="e num">{{ r.ga_share == null ? '—' : money(r.ga_share) }}</td>
                            <td class="e num">{{ money(r.direct) }}</td>
                            <td class="e"><b class="num" :style="{ color: profitColor(r.true_profit) }">{{ r.true_profit == null ? '—' : money(r.true_profit) }}</b>
                                <div v-if="r.true_profit != null && !r.final" class="xs mu">{{ t('close.estimate') }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Line form -->
        <Modal :show="lineOpen" :title="editingLine ? t('close.editLine') : t('close.addLine')" @close="lineOpen = false">
            <form id="line-form" @submit.prevent="saveLine">
                <Field :label="t('close.f.line')" :error="line.errors.code">
                    <select v-model="line.code" required>
                        <option value="" disabled>{{ t('common.choose') }}</option>
                        <option v-for="s in standard" :key="s.code" :value="s.code">{{ s.label }}</option>
                        <option :value="OWN">{{ t('close.f.own') }}</option>
                    </select>
                </Field>
                <Field v-if="line.code === OWN" :label="t('close.f.label')" :error="line.errors.label"><input v-model="line.label" maxlength="120" required></Field>
                <Field :label="t('close.f.amount')" :error="line.errors.amount"><input v-model="line.amount" type="number" min="0.01" step="any" dir="ltr" required></Field>
                <span v-if="line.errors.month" class="err">{{ line.errors.month }}</span>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="lineOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="line-form" class="btn btn-cu" :disabled="line.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <!-- Import -->
        <Modal :show="importOpen" :title="t('close.importTitle')" @close="importOpen = false">
            <p class="xs mu" style="margin-top:12px">{{ t('close.importHint') }}</p>
            <form id="imp-form" @submit.prevent="saveImport">
                <Field :label="t('close.f.file')" :error="imp.errors.file"><input type="file" accept=".xlsx,.xls,.csv" required @change="imp.file = $event.target.files[0]"></Field>
                <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input v-model="imp.replace" type="checkbox"> <span class="sm">{{ t('close.f.replace') }}</span></label>
                <span v-if="imp.errors.month" class="err">{{ imp.errors.month }}</span>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="importOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="imp-form" class="btn btn-cu" :disabled="imp.processing || !imp.file"><AppIcon name="check" /> {{ t('close.import') }}</button>
            </template>
        </Modal>

        <!-- Re-open -->
        <Modal :show="reopenOpen" :title="t('close.reopenTitle', { month: summary.month })" @close="reopenOpen = false">
            <div class="note am" style="margin-top:12px"><AppIcon name="alert" /><div>{{ t('close.reopenWarn') }}</div></div>
            <form id="reopen-form" @submit.prevent="saveReopen">
                <Field :label="t('close.f.reason')" :error="reopen.errors.reason"><input v-model="reopen.reason" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="reopenOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="reopen-form" class="btn btn-rd" :disabled="reopen.processing"><AppIcon name="unlock" /> {{ t('close.reopen') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!delLine" :title="t('common.delete')" :message="t('close.deleteLine', { n: delLine?.label ?? '' })" danger @confirm="doDelLine" @cancel="delLine = null" />
        <ConfirmDialog :show="closing" :title="t('close.closeMonth')" :message="t('close.closeConfirm', { month: summary.month, rate: summary.rate == null ? '' : num(summary.rate, 2) })" @confirm="doClose" @cancel="closing = false" />
    </PortalLayout>
</template>
