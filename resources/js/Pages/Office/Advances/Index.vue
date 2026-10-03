<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver advances ( /office/advances )
  Location: resources/js/Pages/Office/Advances/Index.vue

  Scope §6.12.
    tiles  owed by drivers · drivers owing · deduction due this month
    tabs   Open advances · Payroll deductions (one month, apply once,
           Excel sheet for the accountant) · Closed (repaid / cancelled)
  Give an advance (with an optional monthly instalment), change the
  instalment, take a cash repayment, cancel an untouched advance.
  Server: App\Http\Controllers\Office\AdvanceController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num } from '@/Utils/format';

const props = defineProps({ tab: String, month: String, months: Array, tiles: Object, advances: Array, payroll: Array, options: Object });
const { t } = useI18n();
const { can } = usePermissions();

const go = (extra = {}) => router.get(route('office.advances.index'), { tab: props.tab, month: props.month, ...extra }, { preserveScroll: true });

const tabs = computed(() => [['open', props.tiles.open], ['payroll', null], ['closed', null]]);
const payrollTotals = computed(() => ({
    due: props.payroll.reduce((s, r) => s + r.due, 0),
    applied: props.payroll.reduce((s, r) => s + r.applied, 0),
}));
const nothingToApply = computed(() => props.payroll.every((r) => r.due <= 0 || r.advances.every((a) => a.applied != null || a.due <= 0)));

// ── Give an advance ─────────────────────────────────────────────
const giveOpen = ref(false);
const give = useForm({ driver_id: '', amount: '', monthly_instalment: '', reason: '' });
const driverSalary = computed(() => props.options?.drivers.find((d) => d.id === Number(give.driver_id))?.salary);
function openGive() { give.reset(); give.clearErrors(); giveOpen.value = true; }
function saveGive() {
    give.transform((d) => ({ ...d, monthly_instalment: d.monthly_instalment === '' ? null : d.monthly_instalment }))
        .post(route('office.advances.store'), { preserveScroll: true, onSuccess: () => (giveOpen.value = false) });
}

// ── Change instalment / reason ──────────────────────────────────
const editing = ref(null);
const edit = useForm({ monthly_instalment: '', reason: '' });
function openEdit(a) { editing.value = a; edit.clearErrors(); edit.monthly_instalment = a.instalment ?? ''; edit.reason = a.reason ?? ''; }
function saveEdit() {
    edit.transform((d) => ({ ...d, monthly_instalment: d.monthly_instalment === '' ? null : d.monthly_instalment }))
        .patch(route('office.advances.update', editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
}

// ── Cash repayment ──────────────────────────────────────────────
const repaying = ref(null);
const repay = useForm({ amount: '', note: '' });
function openRepay(a) { repaying.value = a; repay.reset(); repay.clearErrors(); repay.amount = a.remaining; }
function saveRepay() {
    repay.post(route('office.advances.repay', repaying.value.id), { preserveScroll: true, onSuccess: () => (repaying.value = null) });
}

// ── Cancel / apply payroll ──────────────────────────────────────
const cancelling = ref(null);
const doCancel = () => router.post(route('office.advances.cancel', cancelling.value.id), {}, { preserveScroll: true, onFinish: () => (cancelling.value = null) });
const applying = ref(false);
const doApply = () => router.post(route('office.advances.payroll'), { month: props.month }, { preserveScroll: true, onFinish: () => (applying.value = false) });

const srcLabel = (a) => (a.source === 'trip' ? t('adv.fromTrip') : t('adv.manual'));
const pct = (a) => (a.amount > 0 ? Math.min(100, (a.repaid / a.amount) * 100) : 0);
</script>

<template>
    <PortalLayout :title="t('adv.title')">
        <PageHeader :title="t('adv.title')" :sub="t('adv.sub')">
            <button v-if="can('driver_advances.create')" class="btn btn-cu" @click="openGive"><AppIcon name="plus" /> {{ t('adv.give') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('adv.owed')" :value="money(tiles.owed)" unit="EGP" color="var(--am)" :foot="t('adv.owedFoot', { d: tiles.drivers, n: tiles.open })" />
            <Kpi :label="t('adv.dueMonth')" :value="money(tiles.due)" unit="EGP" color="var(--bl)" :foot="month" />
            <Kpi :label="t('adv.appliedMonth')" :value="money(tiles.applied)" unit="EGP" color="var(--gn)" :foot="month" />
            <Kpi :label="t('adv.noInstalment')" :value="num(tiles.no_instalment)" :color="tiles.no_instalment ? 'var(--cu)' : 'var(--gn)'" :foot="t('adv.noInstalmentFoot')" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="[key, count] in tabs" :key="key" class="tab" :class="{ on: tab === key }" @click="go({ tab: key })">
                {{ t('adv.tabs.' + key) }}<span v-if="count" class="c">{{ count }}</span>
            </button>
        </div>

        <!-- Open / closed -->
        <div v-if="tab !== 'payroll'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('adv.cols.driver') }}</th><th>{{ t('adv.cols.reason') }}</th><th class="e">{{ t('adv.cols.amount') }}</th><th class="e">{{ t('adv.cols.instalment') }}</th>
                    <th style="min-width:140px">{{ t('adv.cols.repaid') }}</th><th class="e">{{ t('adv.cols.remaining') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-if="!advances.length"><td colspan="7"><div class="empty" style="border:0">{{ t(tab === 'open' ? 'adv.noneOpen' : 'adv.noneClosed') }}</div></td></tr>
                    <tr v-for="a in advances" :key="a.id">
                        <td>
                            <Link v-if="can('drivers.view')" :href="route('office.drivers.show', a.driver.id)" class="b" style="color:var(--cu)">{{ a.driver.name }}</Link><b v-else>{{ a.driver.name }}</b>
                            <div class="xs mu">{{ date(a.created_at) }}</div>
                        </td>
                        <td class="sm">
                            <span class="bd nodot" :class="a.source === 'trip' ? 'bl' : 'pl'">{{ srcLabel(a) }}</span>
                            <Link v-if="a.trip && can('trips.view')" :href="route('office.trips.show', a.trip.id)" class="xs num" style="color:var(--cu)"> {{ a.trip.number }}</Link>
                            <div v-if="a.reason" class="xs mu">{{ a.reason }}</div>
                        </td>
                        <td class="e"><b class="num">{{ money(a.amount) }}</b></td>
                        <td class="e num">
                            <template v-if="a.instalment">{{ money(a.instalment) }}</template>
                            <span v-else class="mu xs">{{ t('adv.wholeNext') }}</span>
                        </td>
                        <td><div class="meter"><div class="bar-in" :style="{ width: pct(a) + '%', background: 'var(--gn)' }"></div></div><div class="xs mu num">{{ money(a.repaid) }}</div></td>
                        <td class="e">
                            <b class="num" :class="a.status === 'open' ? 'am' : 'mu'">{{ money(a.remaining) }}</b>
                            <div v-if="a.status !== 'open'" class="xs mu">{{ t('adv.status.' + a.status) }}</div>
                            <div v-else-if="a.next" class="xs mu">{{ t('adv.nextDeduct', { n: money(a.next) }) }}</div>
                        </td>
                        <td class="e" style="white-space:nowrap">
                            <template v-if="a.status === 'open'">
                                <button v-if="can('driver_advances.edit')" class="btn btn-ln btn-sm" @click="openRepay(a)">{{ t('adv.repay') }}</button>
                                <button v-if="can('driver_advances.edit')" class="ib" :title="t('common.edit')" @click="openEdit(a)"><AppIcon name="edit" /></button>
                                <button v-if="can('driver_advances.delete') && a.can_cancel" class="ib" :title="t('adv.cancel')" @click="cancelling = a"><AppIcon name="x" /></button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Payroll -->
        <template v-else>
            <div class="fbar">
                <select class="sel" :value="month" @change="go({ month: $event.target.value })">
                    <option v-for="m in months" :key="m.key" :value="m.key">{{ m.key }}</option>
                </select>
                <span class="xs mu" style="flex:1">{{ t('adv.payrollHint') }}</span>
                <a class="btn btn-ln" :href="route('office.advances.export', { month })"><AppIcon name="download" /> {{ t('adv.export') }}</a>
                <button v-if="can('driver_advances.approve')" class="btn btn-cu" :disabled="nothingToApply" @click="applying = true"><AppIcon name="check" /> {{ t('adv.apply') }}</button>
            </div>
            <div class="tw">
                <table>
                    <thead><tr>
                        <th>{{ t('adv.cols.driver') }}</th><th class="e">{{ t('adv.cols.salary') }}</th><th class="e">{{ t('adv.cols.deduct') }}</th><th class="e">{{ t('adv.cols.applied') }}</th><th class="e">{{ t('adv.cols.after') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!payroll.length"><td colspan="5"><div class="empty" style="border:0">{{ t('adv.nonePayroll') }}</div></td></tr>
                        <tr v-for="r in payroll" :key="r.driver_id">
                            <td><b>{{ r.name }}</b>
                                <div v-for="a in r.advances" :key="a.id" class="xs mu">
                                    {{ a.reason || srcLabel(a) }} — {{ a.no_instalment ? t('adv.wholeNext') : money(a.instalment) }}
                                </div>
                            </td>
                            <td class="e num">{{ r.base_salary == null ? '—' : money(r.base_salary) }}</td>
                            <td class="e"><b class="num am">{{ money(r.due) }}</b></td>
                            <td class="e num" :class="r.applied > 0 ? 'gn' : 'mu'">{{ r.applied > 0 ? money(r.applied) : '—' }}</td>
                            <td class="e num">{{ money(r.remaining_after) }}</td>
                        </tr>
                        <tr v-if="payroll.length" style="font-weight:700">
                            <td>{{ t('common.total') }}</td><td></td><td class="e num">{{ money(payrollTotals.due) }}</td><td class="e num">{{ money(payrollTotals.applied) }}</td><td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Give -->
        <Modal :show="giveOpen" :title="t('adv.giveTitle')" @close="giveOpen = false">
            <form id="give-form" @submit.prevent="saveGive">
                <Field :label="t('adv.f.driver')" :error="give.errors.driver_id">
                    <select v-model="give.driver_id" required><option value="" disabled>{{ t('common.choose') }}</option><option v-for="d in options?.drivers" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                </Field>
                <div class="fgrid">
                    <Field :label="t('adv.f.amount')" :error="give.errors.amount"><input v-model="give.amount" type="number" min="0.01" step="any" dir="ltr" required></Field>
                    <Field :label="t('adv.f.instalment')" :hint="driverSalary ? t('adv.f.salaryHint', { n: money(driverSalary) }) : t('adv.f.instalmentHint')" :error="give.errors.monthly_instalment">
                        <input v-model="give.monthly_instalment" type="number" min="0" step="any" dir="ltr">
                    </Field>
                </div>
                <Field :label="t('adv.f.reason')" :hint="t('common.optional')" :error="give.errors.reason"><input v-model="give.reason" maxlength="250"></Field>
                <p class="xs mu" style="margin:6px 0 0">{{ t('adv.f.giveNote') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="giveOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="give-form" class="btn btn-cu" :disabled="give.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <!-- Edit -->
        <Modal :show="!!editing" :title="t('adv.editTitle')" @close="editing = null">
            <form id="edit-form" @submit.prevent="saveEdit">
                <Field :label="t('adv.f.instalment')" :hint="t('adv.f.instalmentHint')" :error="edit.errors.monthly_instalment"><input v-model="edit.monthly_instalment" type="number" min="0" step="any" dir="ltr"></Field>
                <Field :label="t('adv.f.reason')" :hint="t('common.optional')" :error="edit.errors.reason"><input v-model="edit.reason" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="editing = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="edit-form" class="btn btn-cu" :disabled="edit.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <!-- Cash repayment -->
        <Modal :show="!!repaying" :title="t('adv.repayTitle')" @close="repaying = null">
            <p v-if="repaying" class="xs mu" style="margin-top:12px">{{ t('adv.repayNote', { name: repaying.driver.name, n: money(repaying.remaining) }) }}</p>
            <form id="repay-form" @submit.prevent="saveRepay">
                <Field :label="t('adv.f.cashAmount')" :error="repay.errors.amount"><input v-model="repay.amount" type="number" min="0.01" step="any" dir="ltr" required></Field>
                <Field :label="t('adv.f.note')" :hint="t('common.optional')" :error="repay.errors.note"><input v-model="repay.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="repaying = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="repay-form" class="btn btn-cu" :disabled="repay.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!cancelling" :title="t('adv.cancel')" :message="t('adv.cancelConfirm')" danger @confirm="doCancel" @cancel="cancelling = null" />
        <ConfirmDialog :show="applying" :title="t('adv.apply')" :message="t('adv.applyConfirm', { month, n: money(payrollTotals.due - payrollTotals.applied) })" @confirm="doApply" @cancel="applying = false" />
    </PortalLayout>
</template>
