<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Trip file ( /office/trips/{id} )
  Location: resources/js/Pages/Office/Trips/Show.vue

  Scope §6.3 – §6.5, the whole trip on one screen:
    header     route, number, status, customer, truck, driver, km,
               dates, the step tracker and ONE "next step" button
    wallets    custody · collections · own pocket, each with its
               issued / spent / moved / returned lines and balance;
               the trip's transfer policy (can be changed)
    transfers  every collections → custody move with its approval
               (approve / reject within your limit, mark reviewed)
    cash log   cash from the client with driver ✓ / client ✓ /
               disputed (disputes are resolved here)
    revenue &  freight, extras, deductions; every expense line with
    expenses   who paid it, the usual amount and the over-budget flag
               (the company's % from Company settings, 15% by default)
    budget     standard vs actual per category
    profit     per km, direct profit, G&A share, true profit (estimate)
    settlement what the driver hands over now, what blocks, the button
    timeline   the key moments · photos (delivery note, receipts)
  All numbers come from the server (App\Services\Trips\TripFigures);
  buttons show only to people with the permission, and the server
  checks again.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Plate from '@/Components/Plate.vue';
import TripStatus from '@/Components/TripStatus.vue';
import Stars from '@/Components/Stars.vue';
import TripForm from '@/Components/TripForm.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, inputValue, money, nowInput, num, time } from '@/Utils/format';

const props = defineProps({ feedback: Object,
    trip: Object, figures: Object, wallets: Object, available: Number, settlement: Object, nextStep: String,
    charges: Array, expenses: Array, collections: Array, transfers: Array, events: Array, options: Object,
});
const { t } = useI18n();
const { can } = usePermissions();

const tr = computed(() => props.trip);
const open = computed(() => !['settled', 'cancelled'].includes(tr.value.status));
const started = computed(() => open.value && tr.value.status !== 'planned');
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');
const nowLocal = () => nowInput();

// ── Step tracker ──────────────────────────────────────────────────
const steps = computed(() => {
    const list = [
        ['planned', props.events.find((e) => e.type === 'created')?.at],
        ['accepted', tr.value.accepted_at],
        ...(tr.value.is_hired ? [] : [['custody', tr.value.custody_issued_at]]),
        ['loading', tr.value.loading_started_at],
        ['on_road', tr.value.departed_at],
        ['delivered', tr.value.delivered_at],
        ['settled', tr.value.settled_at],
    ];
    const firstOpen = list.findIndex(([, at]) => !at);
    return list.map(([key, at], i) => ({ key, at, state: at ? 'ok' : (i === firstOpen && tr.value.status !== 'cancelled' ? 'now' : '') }));
});

// ── The one "next step" button ────────────────────────────────────
const nextAllowed = computed(() => ({
    accept: can('trips.edit'), loading: can('trips.edit'), depart: can('trips.edit'), deliver: can('trips.edit'),
    custody: can('wallet_transfers.create'), settle: can('trip_settlement.approve'),
}[props.nextStep] ?? false));

function doNext() {
    const s = props.nextStep;
    if (['accept', 'loading', 'depart'].includes(s)) router.post(route('office.trips.step', tr.value.id), { step: s }, { preserveScroll: true });
    else if (s === 'custody') openCustody();
    else if (s === 'deliver') openModal('deliver');
    else if (s === 'settle') openModal('settle');
}

// ── Modals ────────────────────────────────────────────────────────
const modal = ref(null);
const openModal = (kind) => { modal.value = kind; };
const close = () => { modal.value = null; };
const done = { preserveScroll: true, onSuccess: close };

const custodyForm = useForm({ amount: '', note: '' });
function openCustody() {
    custodyForm.reset(); custodyForm.clearErrors();
    custodyForm.amount = props.wallets.custody.issued > 0 ? '' : tr.value.custody_planned;
    openModal('custody');
}

const deliverForm = useForm({ pod: null, receiver: '' });
const cashForm = useForm({ amount: '', received_at: '', note: '', receipt: null });
const transferForm = useForm({ amount: '', reason: '' });
const chargeForm = useForm({ kind: 'extra', label: '', amount: '' });
const policyForm = useForm({ transfer_policy: '', auto_transfer_limit: '' });
const cancelForm = useForm({ reason: '' });
const settleForm = useForm({ note: '', received_by: '', accept_unconfirmed: false });
const decisionForm = useForm({ note: '' });
const resolveForm = useForm({ resolution: 'accepted', note: '' });
const target = ref(null); // the transfer / collection / expense / charge being acted on

function openPolicy() {
    policyForm.transfer_policy = tr.value.transfer_policy;
    policyForm.auto_transfer_limit = tr.value.auto_transfer_limit;
    openModal('policy');
}

// ── Expenses ──────────────────────────────────────────────────────
const expenseForm = useForm({ is_personal: false, expense_category_id: null, paid_from: 'custody', amount: '', note: '', spent_at: '', receipt: null });
const editingExpense = ref(null);
function openExpense(e = null) {
    editingExpense.value = e;
    expenseForm.defaults({
        is_personal: e?.is_personal ?? false,
        expense_category_id: e?.category_id ?? null,
        paid_from: e?.paid_from ?? (tr.value.is_hired || !tr.value.driver ? 'company' : 'custody'),
        amount: e?.amount ?? '',
        note: e?.note ?? '',
        spent_at: e ? inputValue(e.spent_at) : nowLocal(),
        receipt: null,
    });
    expenseForm.reset(); expenseForm.clearErrors();
    openModal('expense');
}
const expCategory = computed(() => props.options.categories.find((c) => c.id === expenseForm.expense_category_id));
const paidOptions = computed(() => {
    if (expenseForm.is_personal) return ['custody', 'collections'];
    if (!tr.value.driver || !started.value) return ['company'];
    return tr.value.is_hired ? ['collections', 'own_pocket', 'company'] : ['custody', 'collections', 'own_pocket', 'company'];
});
function pickCategory(id) {
    expenseForm.is_personal = id === 'personal';
    expenseForm.expense_category_id = id === 'personal' ? null : id;
    if (!paidOptions.value.includes(expenseForm.paid_from)) expenseForm.paid_from = paidOptions.value[0];
}
/** What the trip's policy will do with a collections → custody move of this amount (same rule as the server). */
const policyResult = (amount) => (tr.value.transfer_policy === 'auto' || (tr.value.transfer_policy === 'limit' && Number(amount) <= tr.value.auto_transfer_limit) ? 'instant' : 'needsApproval');
function saveExpense() {
    const url = editingExpense.value
        ? route('office.trips.expenses.update', [tr.value.id, editingExpense.value.id])
        : route('office.trips.expenses.store', tr.value.id);
    expenseForm.post(url, { ...done, forceFormData: true });
}
function deleteExpense() {
    router.delete(route('office.trips.expenses.destroy', [tr.value.id, target.value.id]), { preserveScroll: true, onFinish: close });
}

// ── Transfers & disputes ──────────────────────────────────────────
const approve = (x) => router.post(route('office.transfers.approve', x.id), {}, { preserveScroll: true });
const review = (x) => router.post(route('office.transfers.review', x.id), {}, { preserveScroll: true });
function openReject(x) { target.value = x; decisionForm.reset(); decisionForm.clearErrors(); openModal('reject'); }
function openResolve(c) { target.value = c; resolveForm.reset(); resolveForm.clearErrors(); openModal('resolve'); }
const removeCharge = () => router.delete(route('office.trips.charges.destroy', [tr.value.id, target.value.id]), { preserveScroll: true, onFinish: close });

// ── Display helpers ───────────────────────────────────────────────
const tColour = { pending: 'am', approved: 'gn', auto: 'bl', rejected: 'rd', cancelled: 'pl' };
const cColour = { confirmed: 'gn', awaiting_client: 'am', awaiting_driver: 'am', disputed: 'rd', cancelled: 'pl' };
const pColour = { custody: 'bl', collections: 'vi', own_pocket: 'am', company: 'pl' };
const evColour = { created: 'var(--mu)', accepted: 'var(--cu)', custody_issued: 'var(--bl)', custody_received: 'var(--gn)', custody_requested: 'var(--am)', loading: 'var(--am)', departed: 'var(--bl)', delivered: 'var(--vi)', settled: 'var(--gn)', cancelled: 'var(--rd)', price_changed: 'var(--am)', policy_changed: 'var(--am)' };
const receipts = computed(() => [
    ...(tr.value.has_pod ? [{ key: 'pod', url: route('office.trips.pod', tr.value.id), label: t('trip.pod'), amount: null }] : []),
    ...props.expenses.filter((e) => e.has_receipt).map((e) => ({ key: 'e' + e.id, url: route('office.trips.expenses.receipt', [tr.value.id, e.id]), label: e.category ?? t('trip.personal'), amount: e.amount })),
    ...props.collections.filter((c) => c.has_receipt).map((c) => ({ key: 'c' + c.id, url: route('office.trips.collections.receipt', [tr.value.id, c.id]), label: t('trip.cash'), amount: c.amount })),
]);
const pendingCount = computed(() => props.transfers.filter((x) => x.status === 'pending').length);
</script>

<template>
    <PortalLayout :title="`${trip.number} · ${trip.route.name}`">
        <Link :href="route('office.trips.index')" class="back"><AppIcon name="back" :size="15" /> {{ t('trip.back') }}</Link>

        <!-- ── Header ─────────────────────────────────────────── -->
        <div class="tr-hero">
            <div class="rt" style="justify-content:space-between">
                <h1><AppIcon name="route" />{{ trip.route.name }} <span class="num mu" style="font-size:15px">{{ trip.number }}</span> <TripStatus :status="trip.status" /></h1>
                <div class="acts" style="display:flex;gap:8px;flex-wrap:wrap">
                    <a :href="route('office.trips.print', trip.id)" target="_blank" class="btn btn-ln"><AppIcon name="file" /> {{ t('trip.print') }}</a>
                    <button v-if="open && can('trips.edit') && options.form" class="btn btn-ln" @click="openModal('edit')"><AppIcon name="edit" /> {{ t('common.edit') }}</button>
                    <button v-if="['planned', 'accepted'].includes(trip.status) && can('trips.delete')" class="btn btn-rd" @click="cancelForm.reset(); openModal('cancel')"><AppIcon name="x" /> {{ t('trip.cancel') }}</button>
                    <button v-if="nextStep && nextAllowed" class="btn btn-cu" @click="doNext"><AppIcon name="arrow" /> {{ t('trip.next.' + nextStep) }}</button>
                </div>
            </div>
            <div class="tr-meta">
                <div><span>{{ t('trip.customer') }}</span><b>{{ trip.customer.name }}</b></div>
                <div><span>{{ t('trip.vehicle') }}</span><b><Plate v-if="trip.vehicle" :number="trip.vehicle.number" :letters="trip.vehicle.letters" /> <span v-if="trip.is_hired" class="bd am nodot">{{ t('trip.hired') }}</span></b></div>
                <div><span>{{ t('trip.driver') }}</span><b>{{ trip.driver?.name ?? '—' }}</b><span v-if="trip.driver" class="xs mu num">{{ trip.driver.mobile }}</span></div>
                <div><span>{{ t('trip.cargo') }}</span><b>{{ trip.cargo ?? '—' }}</b></div>
                <div><span>{{ t('trip.weight') }}</span><b class="num">{{ trip.weight_tons != null ? num(trip.weight_tons, 2) : '—' }}</b></div>
                <div><span>{{ t('trip.distance') }}</span><b class="num">{{ num(trip.km) }} km</b></div>
                <div><span>{{ t('trip.loadingAt') }}</span><b class="num">{{ when(trip.loading_at) }}</b></div>
                <div><span>{{ t('trip.delivery') }}</span><b class="num">{{ when(trip.delivered_at) }}</b></div>
            </div>
            <div v-if="trip.status !== 'cancelled'" class="steps">
                <div v-for="s in steps" :key="s.key" class="step" :class="s.state">
                    <i><AppIcon v-if="s.state === 'ok'" name="check" /></i>{{ t('trip.steps.' + s.key) }}<small>{{ s.at ? `${date(s.at)} ${time(s.at)}` : '' }}</small>
                </div>
            </div>
            <p v-if="nextStep && ['accept', 'loading', 'depart', 'deliver'].includes(nextStep)" class="xs mu" style="margin:10px 0 0">{{ t('trip.nextHint') }}</p>
        </div>

        <div v-if="trip.status === 'cancelled'" class="note rd" style="margin-top:12px"><AppIcon name="x" /><div>{{ t('trip.cancelledNote', { date: date(trip.cancelled_at), reason: trip.cancel_reason }) }}</div></div>
        <div v-if="trip.is_hired" class="note am" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('trip.hiredNote', { owner: trip.vehicle?.owner ?? '' }) }}</div></div>

        <div class="g-73" style="margin-top:14px;align-items:start">
            <!-- ═════════ Left column ═════════ -->
            <div style="display:grid;gap:14px;min-width:0">
                <!-- Revenue & expenses -->
                <div class="pn">
                    <div class="pn-h">
                        <div><h3><AppIcon name="receipt" />{{ t('trip.revenue') }}</h3></div>
                        <div style="display:flex;gap:6px">
                            <button v-if="open && can('trips.edit')" class="btn btn-ln btn-sm" @click="chargeForm.reset(); chargeForm.clearErrors(); openModal('charge')"><AppIcon name="plus" /> {{ t('trip.addCharge') }}</button>
                            <button v-if="open && can('trip_expenses.create')" class="btn btn-cu btn-sm" @click="openExpense()"><AppIcon name="plus" /> {{ t('trip.addExpense') }}</button>
                        </div>
                    </div>
                    <div class="rowlist">
                        <div class="r"><div><b>{{ t('trip.freightLine') }}</b> <small class="xs mu">· {{ t('trip.priceSource.' + trip.price_source) }}</small></div><b class="num">{{ money(figures.freight) }}</b></div>
                        <div v-for="c in charges" :key="c.id" class="r">
                            <span>{{ t('trip.' + c.kind) }} · {{ c.label }}
                                <button v-if="open && can('trips.edit')" class="ib" style="width:22px;height:22px;display:inline-grid" @click="target = c; openModal('removeCharge')"><AppIcon name="x" :size="12" /></button>
                            </span>
                            <b class="num" :class="c.kind === 'deduction' ? 'rd' : 'gn'">{{ c.kind === 'deduction' ? '−' : '+' }}{{ money(c.amount) }}</b>
                        </div>
                        <div class="r"><b>{{ t('trip.revenueTotal') }}</b><b class="num" style="font-size:15px">{{ money(figures.revenue) }}</b></div>
                    </div>

                    <div class="sec" style="margin-top:16px">{{ t('trip.expenses') }}</div>
                    <div v-if="!expenses.length" class="empty">{{ t('trip.noExpenses') }}</div>
                    <div v-else class="tw" style="border:0">
                        <table>
                            <thead><tr><th>{{ t('trip.category') }}</th><th>{{ t('trip.paidFrom') }}</th><th class="e">{{ t('trip.amount') }}</th><th class="e">{{ t('trip.stdCol') }}</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="e in expenses" :key="e.id">
                                    <td>
                                        <b class="sm">{{ e.is_personal ? t('trip.personal') : e.category }}</b>
                                        <span v-if="e.over" class="bd rd nodot" style="margin-inline-start:4px">{{ t('trip.over') }}</span>
                                        <div class="xs mu"><span class="num">{{ when(e.spent_at) }}</span> · {{ e.source === 'driver' ? t('trip.fromDriver') : t('trip.fromOffice') }}<template v-if="e.note"> · {{ e.note }}</template></div>
                                    </td>
                                    <td>
                                        <span class="bd nodot" :class="pColour[e.paid_from]">{{ t('trip.paid.' + e.paid_from) }}</span>
                                        <div v-if="e.transfer" class="xs mu">{{ t('trip.tStatus.' + e.transfer) }}</div>
                                        <div v-if="e.is_personal" class="xs am">{{ t('trip.advancesW') }}</div>
                                    </td>
                                    <td class="e"><b class="num" :class="{ rd: e.over }">{{ money(e.amount) }}</b></td>
                                    <td class="e num mu">{{ e.standard ? money(e.standard) : '—' }}</td>
                                    <td class="e" style="white-space:nowrap">
                                        <a v-if="e.has_receipt" :href="route('office.trips.expenses.receipt', [trip.id, e.id])" target="_blank" class="ib" :title="t('trip.viewReceipt')"><AppIcon name="camera" /></a>
                                        <button v-if="open && can('trip_expenses.edit')" class="ib" :title="t('common.edit')" @click="openExpense(e)"><AppIcon name="edit" /></button>
                                        <button v-if="open && can('trip_expenses.delete')" class="ib" :title="t('common.delete')" @click="target = e; openModal('deleteExpense')"><AppIcon name="trash" /></button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot><tr><td colspan="2"><b>{{ t('trip.costTotal') }}</b></td><td class="e"><b class="num">{{ money(figures.cost) }}</b></td><td class="e num mu">{{ money(figures.budget.standard) }}</td><td></td></tr></tfoot>
                        </table>
                    </div>
                </div>

                <!-- Budget vs actual -->
                <div v-if="figures.budget.rows.length" class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="chart" />{{ t('trip.budget') }}</h3><div class="s">{{ t('trip.budgetSub', { n: num(figures.budget.percent, 0) }) }}</div></div>
                        <span v-if="figures.budget.over" class="bd rd">{{ t('trip.over') }}</span></div>
                    <div class="hb">
                        <div v-for="b in figures.budget.rows" :key="b.category_id" class="r">
                            <span>{{ b.name }}</span>
                            <div class="trk2"><i :style="{ width: Math.min(100, b.standard > 0 ? b.actual / b.standard * 100 : (b.actual > 0 ? 100 : 0)) + '%', background: b.over ? 'var(--rd)' : 'var(--bl)' }" /></div>
                            <span class="v" :class="{ rd: b.over }">{{ money(b.actual) }} / {{ money(b.standard) }}</span>
                        </div>
                    </div>
                    <div class="kv" style="margin-top:10px">
                        <div><span>{{ t('trip.stdCol') }}</span><b class="num">{{ money(figures.budget.standard) }}</b></div>
                        <div><span>{{ t('trip.actualCol') }}</span><b class="num">{{ money(figures.budget.actual) }}</b></div>
                        <div><span>{{ t('trip.variance') }}</span><b class="num" :class="figures.budget.variance > 0 ? 'rd' : 'gn'">{{ figures.budget.variance > 0 ? '+' : '' }}{{ money(figures.budget.variance) }}</b></div>
                    </div>
                </div>

                <!-- Transfers & approvals -->
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="swap" />{{ t('trip.transfers') }} <span v-if="pendingCount" class="bd am">{{ pendingCount }}</span></h3></div></div>
                    <div v-if="!transfers.length" class="empty">{{ t('trip.noTransfers') }}</div>
                    <div v-for="x in transfers" :key="x.id" class="xfer">
                        <div class="arr"><span style="color:var(--vi)">{{ t('trip.collectionsW') }}</span><AppIcon name="arrow" /><span style="color:var(--bl)">{{ t('trip.custodyW') }}</span></div>
                        <div style="min-width:0">
                            <b class="num">{{ money(x.amount) }}</b> <span class="bd nodot" :class="tColour[x.status]">{{ t('trip.tStatus.' + x.status) }}</span>
                            <span v-if="x.status === 'auto' && !x.reviewed_at" class="bd am nodot">{{ t('trip.toReview') }}</span>
                            <div class="xs mu">{{ x.reason }}</div>
                            <div class="xs mu">{{ x.by_driver ? t('trip.byDriver') : t('trip.byOffice') }} · {{ x.requested_by }} · <span class="num">{{ when(x.requested_at) }}</span>
                                <template v-if="x.decided_by"> · {{ x.decided_by }} <span class="num">{{ when(x.decided_at) }}</span></template>
                                <template v-if="x.note"> · «{{ x.note }}»</template>
                                <template v-if="x.reviewed_by"> · {{ t('trip.reviewed', { name: x.reviewed_by }) }}</template>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                            <template v-if="x.status === 'pending' && can('wallet_transfers.approve')">
                                <template v-if="x.can_approve">
                                    <button class="btn btn-gn btn-sm" @click="approve(x)"><AppIcon name="check" /> {{ t('trip.approve') }}</button>
                                    <button class="btn btn-rd btn-sm" @click="openReject(x)">{{ t('trip.reject') }}</button>
                                </template>
                                <span v-else class="xs am" style="max-width:180px">{{ t('trip.aboveLimit') }}</span>
                            </template>
                            <button v-if="x.status === 'auto' && !x.reviewed_at && can('wallet_transfers.approve')" class="btn btn-ln btn-sm" @click="review(x)"><AppIcon name="eye" /> {{ t('trip.review') }}</button>
                        </div>
                    </div>
                </div>

                <!-- Cash from the client -->
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="hand" />{{ t('trip.cash') }}</h3><div class="s">{{ t('trip.cashSub') }}</div></div>
                        <button v-if="started && trip.driver && can('wallet_transfers.create')" class="btn btn-ln btn-sm" @click="cashForm.reset(); cashForm.clearErrors(); cashForm.received_at = nowLocal(); openModal('cash')"><AppIcon name="plus" /> {{ t('trip.recordCash') }}</button></div>
                    <div v-if="!collections.length" class="empty">{{ t('trip.noCash') }}</div>
                    <div v-else class="rowlist">
                        <div v-for="c in collections" :key="c.id" class="r">
                            <div>
                                <b class="num" :style="c.counts ? '' : 'text-decoration:line-through;opacity:.7'">{{ money(c.amount) }}</b>
                                <span class="bd nodot" :class="cColour[c.state]" style="margin-inline-start:6px">{{ t('trip.cState.' + c.state) }}</span>
                                <div class="xs mu"><span class="num">{{ when(c.received_at) }}</span> · {{ t('trip.recordedBy.' + c.recorded_by) }}<template v-if="c.note"> · {{ c.note }}</template><template v-if="c.dispute_note"> · «{{ c.dispute_note }}»</template></div>
                            </div>
                            <div class="two-side">
                                <span class="bd nodot" :class="['confirmed', 'awaiting_client'].includes(c.state) || c.recorded_by !== 'client' ? 'gn' : 'pl'">{{ t('trip.driverOk') }}</span>
                                <span class="bd nodot" :class="['confirmed', 'awaiting_driver'].includes(c.state) ? 'gn' : 'pl'">{{ t('trip.clientOk') }}</span>
                                <button v-if="c.state === 'disputed' && open && can('wallet_transfers.approve')" class="btn btn-rd btn-sm" @click="openResolve(c)">{{ t('trip.resolve') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═════════ Right column ═════════ -->
            <div style="display:grid;gap:14px;min-width:0">
                <!-- What the client said (Step 5) -->
                <div v-if="feedback && (feedback.request || feedback.rating || feedback.complaints.length)" class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="flag" />{{ t('trip.clientSide') }}</h3></div></div>
                    <div v-if="feedback.request" class="kv"><span>{{ t('trip.fromRequest') }}</span>
                        <Link v-if="can('client_requests.view')" :href="route('office.client-requests.index', { tab: 'answered' })" class="num b" style="color:var(--cu)">{{ feedback.request.number }}</Link>
                        <b v-else class="num">{{ feedback.request.number }}</b></div>
                    <div v-if="feedback.rating" style="margin-top:6px"><Stars :value="feedback.rating.stars" /><div class="xs mu">{{ feedback.rating.by }}<template v-if="feedback.rating.comment"> · {{ feedback.rating.comment }}</template></div></div>
                    <div v-for="c in feedback.complaints" :key="c.id" class="xs" style="margin-top:6px"><span class="bd nodot" :class="c.status === 'open' ? 'am' : 'gn'">{{ t('cp.cpStatus.' + c.status) }}</span> {{ c.subject }}</div>
                </div>

                <!-- Wallets -->
                <div v-if="trip.driver" class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="wallet" />{{ t('trip.wallets') }}</h3></div></div>
                    <div style="display:grid;gap:10px">
                        <div v-if="!trip.is_hired" class="wal w-cu">
                            <div class="wh"><span class="ic"><AppIcon name="wallet" /></span><div><b>{{ t('trip.custodyW') }}</b><span>{{ t('trip.custodySub') }}</span></div>
                                <button v-if="started && !['delivered'].includes(trip.status) && can('wallet_transfers.create')" class="btn btn-ln btn-sm" style="margin-inline-start:auto" @click="openCustody"><AppIcon name="plus" /> {{ t('trip.issueCustody') }}</button></div>
                            <div class="bal">{{ money(wallets.custody.balance) }}</div>
                            <div class="flow">
                                <div><span>{{ t('trip.issued') }}</span><b>{{ money(wallets.custody.issued) }}</b></div>
                                <div v-if="wallets.custody.transfers_in"><span>{{ t('trip.transfersIn') }}</span><b>+{{ money(wallets.custody.transfers_in) }}</b></div>
                                <div><span>{{ t('trip.spent') }}</span><b>−{{ money(wallets.custody.spent) }}</b></div>
                                <div v-if="wallets.custody.to_advances"><span>{{ t('trip.toAdvances') }}</span><b>−{{ money(wallets.custody.to_advances) }}</b></div>
                                <div v-if="wallets.custody.returned"><span>{{ t('trip.returned') }}</span><b>{{ money(-wallets.custody.returned) }}</b></div>
                            </div>
                        </div>
                        <div class="wal w-co">
                            <div class="wh"><span class="ic"><AppIcon name="hand" /></span><div><b>{{ t('trip.collectionsW') }}</b><span>{{ t('trip.collectionsSub') }}</span></div>
                                <button v-if="started && can('wallet_transfers.create') && available > 0" class="btn btn-ln btn-sm" style="margin-inline-start:auto" @click="transferForm.reset(); transferForm.clearErrors(); openModal('transfer')"><AppIcon name="swap" /> {{ t('trip.transfer') }}</button></div>
                            <div class="bal">{{ money(wallets.collections.balance) }}</div>
                            <div class="flow">
                                <div><span>{{ t('trip.received') }}</span><b>{{ money(wallets.collections.received) }}</b></div>
                                <div><span>{{ t('trip.transferredOut') }}</span><b>−{{ money(wallets.collections.transferred_out) }}</b></div>
                                <div v-if="wallets.collections.handed_in"><span>{{ t('trip.handedIn') }}</span><b>−{{ money(wallets.collections.handed_in) }}</b></div>
                            </div>
                            <div v-if="open && available > 0" class="xs mu" style="margin-top:6px">{{ t('trip.available', { amount: money(available) }) }}</div>
                        </div>
                        <div v-if="wallets.pocket.spent || wallets.advances.created" class="wal w-ad">
                            <div class="wh"><span class="ic"><AppIcon name="person" /></span><div><b>{{ t('trip.pocketW') }}</b><span>{{ t('trip.pocketSub') }}</span></div></div>
                            <div class="bal">{{ money(wallets.pocket.balance) }}</div>
                            <div class="flow">
                                <div><span>{{ t('trip.pocketSpent') }}</span><b>{{ money(wallets.pocket.spent) }}</b></div>
                                <div v-if="wallets.pocket.refunded"><span>{{ t('trip.refunded') }}</span><b>−{{ money(wallets.pocket.refunded) }}</b></div>
                                <div v-if="wallets.advances.created"><span>{{ t('trip.advancesW') }}</span><b>{{ money(wallets.advances.created) }}</b></div>
                            </div>
                        </div>
                    </div>
                    <div class="doc-row" style="margin-top:10px;grid-template-columns:minmax(0,1fr) auto">
                        <div><b class="sm">{{ t('trip.policyLabel') }}</b><span>{{ t('trip.policies.' + trip.transfer_policy, { limit: money(trip.auto_transfer_limit) }) }}</span></div>
                        <button v-if="open && can('trips.edit_policy')" class="btn btn-ln btn-sm" @click="openPolicy">{{ t('trip.changePolicy') }}</button>
                    </div>
                </div>

                <!-- Settlement -->
                <div v-if="settlement || trip.settlement" class="pn" style="border-color:var(--vi-bd)">
                    <div class="pn-h"><div><h3><AppIcon name="lock" />{{ t('trip.settlement') }}</h3><div class="s">{{ t('trip.settleSub') }}</div></div></div>
                    <template v-for="s in [trip.settlement ?? settlement]" :key="'s'">
                        <div class="rowlist">
                            <div class="r"><span>{{ s.custody >= 0 ? t('trip.custodyBack') : t('trip.custodyRefund') }}</span><b class="num">{{ money(Math.abs(s.custody)) }}</b></div>
                            <div class="r"><span>{{ t('trip.collectionsIn') }}</span><b class="num">{{ money(s.collections) }}</b></div>
                            <div v-if="s.pocket" class="r"><span>{{ t('trip.pocketRefund') }}</span><b class="num">−{{ money(s.pocket) }}</b></div>
                            <div class="r"><b>{{ s.net > 0 ? t('trip.netDriver') : s.net < 0 ? t('trip.netCompany') : t('trip.netZero') }}</b>
                                <b class="num" style="font-size:22px" :class="s.net >= 0 ? 'gn' : 'am'">{{ money(Math.abs(s.net)) }}</b></div>
                        </div>
                    </template>
                    <template v-if="settlement && !trip.settlement">
                        <div v-for="b in settlement.blockers" :key="b.code" class="note rd" style="margin-top:8px"><AppIcon name="lock" /><div>{{ t('trip.blocked.' + b.code, { n: b.n, amount: money(b.amount) }) }}</div></div>
                        <div v-for="w in settlement.warnings" :key="w.code" class="note am" style="margin-top:8px"><AppIcon name="alert" /><div>{{ t('trip.warn.' + w.code, { n: w.n, amount: money(w.amount) }) }}</div></div>
                        <button v-if="can('trip_settlement.approve')" class="btn btn-cu" style="width:100%;margin-top:12px" :disabled="!settlement.can_settle" @click="settleForm.reset(); openModal('settle')"><AppIcon name="lock" /> {{ t('trip.settleBtn') }}</button>
                        <p v-else class="xs mu" style="margin:10px 0 0">{{ t('trip.needsSettlePerm') }}</p>
                    </template>
                    <p v-if="trip.settlement" class="xs mu" style="margin:10px 0 0">{{ t('trip.settledOn', { date: when(trip.settlement.at), name: trip.settlement.by ?? '—' }) }}<template v-if="trip.settlement.received_by"> · {{ t('trip.receivedByLabel', { name: trip.settlement.received_by }) }}</template><template v-if="trip.settlement.note"> · {{ trip.settlement.note }}</template></p>
                </div>

                <!-- Profitability -->
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="pie" />{{ t('trip.profit') }}</h3></div></div>
                    <div class="kv">
                        <template v-if="can('trips.see_profit')">
                        <div><span>{{ t('trip.directProfit') }}</span><b class="num" :class="figures.profit >= 0 ? 'gn' : 'rd'">{{ money(figures.profit) }}</b>
                            <div v-if="figures.margin != null" class="xs mu">{{ t('trip.marginShort') }} <span class="num">{{ num(figures.margin, 1) }}%</span></div></div>
                        </template>
                        <div><span>{{ t('trip.perKm') }}</span><b class="num">{{ num(figures.revenue_per_km, 2) }}</b></div>
                        <div><span>{{ t('trip.costPerKm') }}</span><b class="num">{{ num(figures.cost_per_km, 2) }}</b></div>
                        <template v-if="can('trips.see_profit')">
                        <div><span>{{ t('trip.profitPerKm') }}</span><b class="num">{{ num(figures.profit_per_km, 2) }}</b></div>
                        </template>
                        <template v-if="can('trips.see_profit')">
                        <div><span>{{ t('trip.gaShare') }}</span><b class="num">{{ trip.is_hired && !figures.ga_parts.length && figures.ga_rate === 0 ? '0' : !figures.ga_known ? '—' : money(figures.ga_share) }}</b>
                            <div class="xs mu">{{ trip.is_hired && figures.ga_rate === 0 ? t('trip.hiredNoGa') : !figures.ga_known ? t('trip.noGa') : figures.ga_parts.length ? '' : t('trip.gaCalc', { km: num(trip.km), rate: num(figures.ga_rate, 2) }) }}</div>
                            <div v-for="p in figures.ga_parts" :key="p.month" class="xs mu">
                                {{ p.month }} · {{ t('trip.gaPart', { km: num(p.km), rate: p.rate == null ? '—' : num(p.rate, 2) }) }}
                                <span class="bd nodot" :class="p.closed ? 'gn' : 'pl'">{{ p.closed ? t('trip.monthClosed') : t('trip.monthOpen') }}</span>
                            </div></div>
                        <div><span>{{ t('trip.trueProfit') }} <small v-if="figures.true_estimate" class="am">({{ t('trip.estimate') }})</small><small v-else class="gn">({{ t('trip.final') }})</small></span>
                            <b class="num" :class="figures.true_profit == null ? '' : figures.true_profit >= 0 ? 'gn' : 'rd'">{{ figures.true_profit == null ? '—' : money(figures.true_profit) }}</b>
                            <div v-if="figures.true_estimate && figures.ga_known" class="xs mu">{{ t('trip.estimateWhy') }}</div></div>
                        </template>
                        <div><span>{{ t('trip.invoice') }}</span>
                            <b v-if="trip.invoice" class="num">{{ trip.invoice.number }}</b><b v-else class="mu">—</b>
                            <div v-if="trip.invoice?.issued_on" class="xs mu">{{ date(trip.invoice.issued_on) }}</div>
                            <div v-else-if="!trip.invoice && trip.status === 'settled'" class="xs am">{{ t('trip.notInvoiced') }}</div></div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="clock" />{{ t('trip.timeline') }}</h3></div></div>
                    <ul class="tl">
                        <li v-for="e in events" :key="e.id" :style="{ '--c': evColour[e.type] ?? 'var(--cu)' }">
                            <div>
                                <b>{{ t('trip.ev.' + e.type) }}
                                    <template v-if="['custody_issued', 'custody_received', 'custody_requested'].includes(e.type)"> · <span class="num">{{ money(e.meta?.amount) }}</span><template v-if="e.meta?.top_up"> ({{ t('trip.topUp') }})</template></template>
                                    <template v-if="e.type === 'price_changed'"> · <span class="num">{{ money(e.meta?.from) }} → {{ money(e.meta?.to) }}</span></template>
                                    <template v-if="e.type === 'policy_changed'"> · {{ t('trip.policies.' + e.meta?.policy, { limit: money(e.meta?.limit) }) }}</template>
                                </b>
                                <span>{{ e.actor }}<template v-if="e.note"> · {{ e.note }}</template></span>
                                <span v-if="e.located" class="loc"><AppIcon name="pin" />{{ t('trip.located') }}</span>
                            </div>
                            <span class="t">{{ date(e.at) }} {{ time(e.at) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Photos -->
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="camera" />{{ t('trip.photos') }}</h3></div></div>
                    <div v-if="!receipts.length" class="empty">{{ t('trip.noPhotos') }}</div>
                    <div v-else class="rcpt">
                        <a v-for="r in receipts" :key="r.key" :href="r.url" target="_blank" style="display:block;text-decoration:none">
                            <div :style="{ backgroundImage: `url(${r.url})`, backgroundSize: 'cover', backgroundPosition: 'center' }">
                                <b v-if="r.amount != null" style="background:var(--card);border-radius:5px;padding:0 4px">{{ money(r.amount) }}</b>
                                <span style="background:var(--card);border-radius:5px;padding:0 4px">{{ r.label }}</span>
                            </div>
                        </a>
                    </div>
                    <p v-if="trip.pod_receiver" class="xs mu" style="margin:8px 0 0">{{ t('trip.receiver') }}: {{ trip.pod_receiver }}</p>
                </div>
            </div>
        </div>

        <!-- ═════════ Windows ═════════ -->
        <TripForm v-if="options.form" :show="modal === 'edit'" :options="options.form" :trip="trip" @close="close" />

        <Modal :show="modal === 'custody'" :title="t('trip.custodyTitle')" @close="close">
            <form id="custody-form" @submit.prevent="custodyForm.post(route('office.trips.custody', trip.id), done)">
                <p class="sm mu" style="margin:10px 0 0">{{ wallets.custody.issued > 0 ? t('trip.custodyTopUp', { amount: money(wallets.custody.issued) }) : t('trip.custodyPlanned', { amount: money(trip.custody_planned) }) }}</p>
                <Field :label="t('trip.amount')" :error="custodyForm.errors.amount"><input v-model="custodyForm.amount" type="number" min="1" step="any" dir="ltr" class="amt" required></Field>
                <Field :label="t('trip.note')" :error="custodyForm.errors.note"><input v-model="custodyForm.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="custody-form" class="btn btn-cu" :disabled="custodyForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'deliver'" :title="t('trip.deliverTitle')" @close="close">
            <form id="deliver-form" @submit.prevent="deliverForm.post(route('office.trips.deliver', trip.id), { ...done, forceFormData: true })">
                <Field :label="t('trip.podPhoto')" :error="deliverForm.errors.pod"><input type="file" accept="image/*" capture="environment" required @change="deliverForm.pod = $event.target.files[0]"></Field>
                <Field :label="t('trip.receiver')" :error="deliverForm.errors.receiver"><input v-model="deliverForm.receiver" maxlength="120"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="deliver-form" class="btn btn-cu" :disabled="deliverForm.processing"><AppIcon name="check" /> {{ t('trip.next.deliver') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'expense'" wide :title="editingExpense ? t('trip.editExpense') : t('trip.addExpense')" @close="close">
            <form id="expense-form" @submit.prevent="saveExpense">
                <div class="fl" style="margin-top:10px">{{ t('trip.category') }}</div>
                <div class="catg" style="grid-template-columns:repeat(auto-fill,minmax(110px,1fr))">
                    <button v-for="c in options.categories" :key="c.id" type="button" :class="{ on: !expenseForm.is_personal && expenseForm.expense_category_id === c.id }" @click="pickCategory(c.id)">
                        <AppIcon :name="c.icon" />{{ c.name }}
                    </button>
                    <button v-if="trip.driver && started" type="button" :class="{ on: expenseForm.is_personal }" @click="pickCategory('personal')"><AppIcon name="person" />{{ t('trip.personal') }}</button>
                </div>
                <span v-if="expenseForm.errors.expense_category_id" class="err">{{ expenseForm.errors.expense_category_id }}</span>
                <div v-if="expenseForm.is_personal" class="note am" style="margin-top:10px"><AppIcon name="info" /><div>{{ t('trip.personalHint') }}</div></div>

                <div class="fgrid" style="margin-top:6px">
                    <Field :label="t('trip.amount')" :error="expenseForm.errors.amount"
                           :hint="expCategory && expCategory.standard ? t('trip.usual', { amount: money(expCategory.standard) }) : ''">
                        <input v-model="expenseForm.amount" type="number" min="0.01" step="0.01" dir="ltr" required>
                        <span v-if="expCategory && expCategory.standard && Number(expenseForm.amount) > expCategory.standard * (1 + figures.budget.percent / 100)" class="xs rd">{{ t('trip.aboveUsual') }}</span>
                    </Field>
                    <Field :label="t('trip.spentAt')" :error="expenseForm.errors.spent_at"><input v-model="expenseForm.spent_at" type="datetime-local" dir="ltr"></Field>
                </div>
                <div class="fl" style="margin-top:6px">{{ t('trip.paidFrom') }}</div>
                <div class="seg" style="margin-top:6px;width:fit-content;flex-wrap:wrap">
                    <button v-for="p in paidOptions" :key="p" type="button" :aria-pressed="expenseForm.paid_from === p" @click="expenseForm.paid_from = p">{{ t('trip.paid.' + p) }}</button>
                </div>
                <p class="xs mu" style="margin:6px 0 0">{{ t('trip.paidHint.' + expenseForm.paid_from) }}
                    <b v-if="expenseForm.paid_from === 'collections' && expenseForm.amount">{{ t('trip.' + policyResult(expenseForm.amount)) }}</b></p>
                <span v-if="expenseForm.errors.paid_from" class="err">{{ expenseForm.errors.paid_from }}</span>

                <div class="fgrid" style="margin-top:6px">
                    <Field :label="t('trip.note')" :error="expenseForm.errors.note"><input v-model="expenseForm.note" maxlength="250"></Field>
                    <Field :label="t('trip.receipt')" :hint="editingExpense?.has_receipt ? t('trip.keepReceipt') : ''" :error="expenseForm.errors.receipt">
                        <input type="file" accept="image/*" @change="expenseForm.receipt = $event.target.files[0]">
                    </Field>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="expense-form" class="btn btn-cu" :disabled="expenseForm.processing || (!expenseForm.is_personal && !expenseForm.expense_category_id)"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'cash'" :title="t('trip.cashTitle')" @close="close">
            <form id="cash-form" @submit.prevent="cashForm.post(route('office.trips.collections.store', trip.id), { ...done, forceFormData: true })">
                <p class="sm mu" style="margin:10px 0 0">{{ t('trip.cashHint') }}</p>
                <Field :label="t('trip.amount')" :error="cashForm.errors.amount"><input v-model="cashForm.amount" type="number" min="1" step="any" dir="ltr" class="amt" required></Field>
                <div class="fgrid">
                    <Field :label="t('trip.receivedAt')" :error="cashForm.errors.received_at"><input v-model="cashForm.received_at" type="datetime-local" dir="ltr"></Field>
                    <Field :label="t('trip.receipt')" :error="cashForm.errors.receipt"><input type="file" accept="image/*" @change="cashForm.receipt = $event.target.files[0]"></Field>
                </div>
                <Field :label="t('trip.note')" :error="cashForm.errors.note"><input v-model="cashForm.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="cash-form" class="btn btn-cu" :disabled="cashForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'transfer'" :title="t('trip.transferTitle')" @close="close">
            <form id="transfer-form" @submit.prevent="transferForm.post(route('office.trips.transfers.store', trip.id), done)">
                <p class="sm mu" style="margin:10px 0 0">{{ t('trip.available', { amount: money(available) }) }} · {{ t('trip.policies.' + trip.transfer_policy, { limit: money(trip.auto_transfer_limit) }) }}</p>
                <Field :label="t('trip.amount')" :error="transferForm.errors.amount"><input v-model="transferForm.amount" type="number" min="1" :max="available" step="any" dir="ltr" class="amt" required></Field>
                <p v-if="transferForm.amount" class="xs" style="margin:0"><b>{{ t('trip.' + policyResult(transferForm.amount)) }}</b></p>
                <Field :label="t('trip.reason')" :error="transferForm.errors.reason"><input v-model="transferForm.reason" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="transfer-form" class="btn btn-cu" :disabled="transferForm.processing"><AppIcon name="swap" /> {{ t('trip.transfer') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'charge'" :title="t('trip.addCharge')" @close="close">
            <form id="charge-form" @submit.prevent="chargeForm.post(route('office.trips.charges.store', trip.id), done)">
                <div class="seg" style="margin-top:12px;width:fit-content">
                    <button v-for="k in ['extra', 'deduction']" :key="k" type="button" :aria-pressed="chargeForm.kind === k" @click="chargeForm.kind = k">{{ t('trip.' + k) }}</button>
                </div>
                <Field :label="t('trip.chargeLabel')" :error="chargeForm.errors.label"><input v-model="chargeForm.label" maxlength="120" required></Field>
                <Field :label="t('trip.amount')" :error="chargeForm.errors.amount"><input v-model="chargeForm.amount" type="number" min="1" step="any" dir="ltr" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="charge-form" class="btn btn-cu" :disabled="chargeForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'policy'" :title="t('trip.policyTitle')" @close="close">
            <form id="policy-form" @submit.prevent="policyForm.put(route('office.trips.policy', trip.id), done)">
                <div class="opts" style="margin-top:12px">
                    <label v-for="p in ['approval', 'limit', 'auto']" :key="p" class="opt">
                        <input v-model="policyForm.transfer_policy" type="radio" :value="p">
                        <div><b>{{ t('trip.policies.' + p, { limit: money(policyForm.auto_transfer_limit) }) }}</b></div>
                    </label>
                </div>
                <Field v-if="policyForm.transfer_policy === 'limit'" :label="t('trip.limit')" :error="policyForm.errors.auto_transfer_limit">
                    <input v-model="policyForm.auto_transfer_limit" type="number" min="0" step="any" dir="ltr">
                </Field>
                <p class="xs mu" style="margin:8px 0 0">{{ t('trip.policyHint') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="policy-form" class="btn btn-cu" :disabled="policyForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'reject'" :title="t('trip.rejectTitle')" @close="close">
            <form id="reject-form" @submit.prevent="decisionForm.post(route('office.transfers.reject', target.id), done)">
                <p v-if="target" class="sm" style="margin:10px 0 0"><b class="num">{{ money(target.amount) }}</b> · {{ target.reason }}</p>
                <Field :label="t('trip.rejectReason')" :error="decisionForm.errors.note"><input v-model="decisionForm.note" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="reject-form" class="btn btn-rd" :disabled="decisionForm.processing">{{ t('trip.reject') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'resolve'" :title="t('trip.resolveTitle')" @close="close">
            <form id="resolve-form" @submit.prevent="resolveForm.post(route('office.trips.collections.resolve', [trip.id, target.id]), done)">
                <p v-if="target" class="sm" style="margin:10px 0 0"><b class="num">{{ money(target.amount) }}</b><template v-if="target.dispute_note"> · «{{ target.dispute_note }}»</template></p>
                <p class="xs mu" style="margin:6px 0 0">{{ t('trip.resolveHint') }}</p>
                <div class="opts" style="margin-top:10px">
                    <label class="opt"><input v-model="resolveForm.resolution" type="radio" value="accepted"><div><b>{{ t('trip.resAccepted') }}</b></div></label>
                    <label class="opt"><input v-model="resolveForm.resolution" type="radio" value="cancelled"><div><b>{{ t('trip.resCancelled') }}</b></div></label>
                </div>
                <Field :label="t('trip.note')" :error="resolveForm.errors.note"><input v-model="resolveForm.note" maxlength="200"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="resolve-form" class="btn btn-cu" :disabled="resolveForm.processing"><AppIcon name="check" /> {{ t('trip.resolve') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'cancel'" :title="t('trip.cancel')" @close="close">
            <form id="cancel-form" @submit.prevent="cancelForm.post(route('office.trips.cancel', trip.id), done)">
                <p class="sm" style="margin:10px 0 0">{{ t('trip.cancelConfirm', { number: trip.number }) }}</p>
                <Field :label="t('trip.cancelReason')" :error="cancelForm.errors.reason"><input v-model="cancelForm.reason" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.close') }}</button>
                <button type="submit" form="cancel-form" class="btn btn-rd" :disabled="cancelForm.processing">{{ t('trip.cancel') }}</button>
            </template>
        </Modal>

        <Modal :show="modal === 'settle'" :title="t('trip.settleBtn')" @close="close">
            <form id="settle-form" @submit.prevent="settleForm.post(route('office.trips.settle', trip.id), done)">
                <p class="sm" style="margin:10px 0 0">{{ t('trip.settleConfirm', { number: trip.number }) }}</p>
                <p v-if="settlement" class="sm" style="margin:8px 0 0"><b>{{ settlement.net > 0 ? t('trip.netDriver') : settlement.net < 0 ? t('trip.netCompany') : t('trip.netZero') }}</b>
                    <b class="num" style="margin-inline-start:6px">{{ money(Math.abs(settlement.net)) }}</b></p>
                <Field v-if="settlement && settlement.net > 0" :label="t('trip.receivedBy')" :hint="t('trip.receivedByHint')" :error="settleForm.errors.received_by"><input v-model="settleForm.received_by" maxlength="120"></Field>
                <Field :label="t('trip.settleNote')" :error="settleForm.errors.note"><input v-model="settleForm.note" maxlength="250"></Field>
                <label v-if="settlement?.needs_ack" class="note am" style="margin-top:10px;display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input v-model="settleForm.accept_unconfirmed" type="checkbox" style="margin-top:3px"><span>{{ t('trip.ackUnconfirmed') }}</span></label>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="close">{{ t('common.cancel') }}</button>
                <button type="submit" form="settle-form" class="btn btn-cu" :disabled="settleForm.processing || !settlement?.can_settle || (settlement?.needs_ack && !settleForm.accept_unconfirmed)"><AppIcon name="lock" /> {{ t('trip.settleBtn') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="modal === 'deleteExpense'" :title="t('common.delete')" :message="t('trip.deleteExpense')" danger :confirm-label="t('common.delete')" @confirm="deleteExpense" @cancel="close" />
        <ConfirmDialog :show="modal === 'removeCharge'" :title="t('common.delete')" :message="t('trip.removeCharge')" danger :confirm-label="t('common.delete')" @confirm="removeCharge" @cancel="close" />
    </PortalLayout>
</template>
