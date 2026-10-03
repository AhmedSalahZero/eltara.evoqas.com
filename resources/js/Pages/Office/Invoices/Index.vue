<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Invoice numbers ( /office/invoices )
  Location: resources/js/Pages/Office/Invoices/Index.vue

  Scope §6.11. The invoice itself is made in the company's ERP; here
  we only record its number against the settled trips it covers.
    tiles  linked invoices · trips without an invoice (count, value,
           oldest wait)
    tabs   Linked invoices · Closed trips without an invoice
  Link: pick a customer, type the number, tick the trips.
  An existing number adds trips to the same invoice.
  Server: App\Http\Controllers\Office\InvoiceController.
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
import Pagination from '@/Components/Pagination.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num } from '@/Utils/format';

const props = defineProps({ tab: String, filters: Object, stats: Object, invoices: Object, open: Array, customers: Array });
const { t } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const customer = ref(props.filters.customer ?? '');
let timer;
const go = (extra = {}) => router.get(route('office.invoices.index'), { tab: props.tab, search: search.value || undefined, customer: customer.value || undefined, ...extra }, { preserveScroll: true, preserveState: true, replace: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => go(), 300); });
watch(customer, () => go());

const tabs = computed(() => [['invoices', props.invoices.total], ['open', props.stats.open_count || null]]);
const expanded = ref(null);

// ── Link trips to an invoice ────────────────────────────────────
const linkOpen = ref(false);
const link = useForm({ customer_id: '', number: '', issued_on: '', note: '', trip_ids: [] });
const candidates = computed(() => props.open.filter((x) => x.customer.id === Number(link.customer_id)));
const chosenValue = computed(() => candidates.value.filter((x) => link.trip_ids.includes(x.id)).reduce((s, x) => s + x.value, 0));

function openLink(trip = null) {
    link.reset(); link.clearErrors();
    if (trip) { link.customer_id = trip.customer.id; link.trip_ids = [trip.id]; }
    linkOpen.value = true;
}
watch(() => link.customer_id, () => { if (linkOpen.value && !link.processing) link.trip_ids = link.trip_ids.filter((id) => candidates.value.some((c) => c.id === id)); });
function saveLink() {
    link.transform((d) => ({ ...d, issued_on: d.issued_on || null, note: d.note || null }))
        .post(route('office.invoices.store'), { preserveScroll: true, onSuccess: () => (linkOpen.value = false) });
}
const toggle = (list, id) => { const i = list.indexOf(id); i < 0 ? list.push(id) : list.splice(i, 1); };

// ── Change an invoice ───────────────────────────────────────────
const editing = ref(null);
const edit = useForm({ number: '', issued_on: '', note: '', add: [], remove: [] });
const addable = computed(() => (editing.value ? props.open.filter((x) => x.customer.id === editing.value.customer.id) : []));
function openEdit(inv) {
    editing.value = inv; edit.clearErrors();
    edit.number = inv.number; edit.issued_on = inv.issued_on ?? ''; edit.note = inv.note ?? ''; edit.add = []; edit.remove = [];
}
function saveEdit() {
    edit.transform((d) => ({ ...d, issued_on: d.issued_on || null, note: d.note || null }))
        .patch(route('office.invoices.update', editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
}

const removing = ref(null);
const doRemove = () => router.delete(route('office.invoices.destroy', removing.value.id), { preserveScroll: true, onFinish: () => (removing.value = null) });

const waitClass = (d) => (d == null ? '' : d > 30 ? 'rd' : d > 14 ? 'am' : '');
</script>

<template>
    <PortalLayout :title="t('inv.title')">
        <PageHeader :title="t('inv.title')" :sub="t('inv.sub')">
            <button v-if="can('invoice_links.create')" class="btn btn-cu" @click="openLink()"><AppIcon name="link" /> {{ t('inv.link') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('inv.invoices')" :value="num(stats.invoices)" color="var(--bl)" :foot="t('inv.linkedFoot', { n: num(stats.linked_trips) })" />
            <Kpi :label="t('inv.linkedValue')" :value="money(stats.linked_value)" unit="EGP" color="var(--gn)" />
            <Kpi :label="t('inv.notInvoiced')" :value="num(stats.open_count)" :color="stats.open_count ? 'var(--am)' : 'var(--gn)'" :foot="t('inv.notInvoicedFoot', { n: money(stats.open_value) })" />
            <Kpi :label="t('inv.oldest')" :value="stats.oldest_days == null ? '—' : num(stats.oldest_days)" :unit="stats.oldest_days == null ? '' : t('inv.daysUnit')" :color="stats.oldest_days > 30 ? 'var(--rd)' : 'var(--cu)'" :foot="stats.oldest_trip?.number ?? ''" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="[key, count] in tabs" :key="key" class="tab" :class="{ on: tab === key }" @click="go({ tab: key })">
                {{ t('inv.tabs.' + key) }}<span v-if="count" class="c">{{ count }}</span>
            </button>
        </div>

        <div class="fbar">
            <div class="srch"><AppIcon name="search" :size="16" /><input v-model="search" :placeholder="t(tab === 'open' ? 'inv.searchTrip' : 'inv.searchInvoice')"></div>
            <select v-model="customer" class="sel"><option value="">{{ t('inv.allCustomers') }}</option><option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>

        <!-- Linked invoices -->
        <template v-if="tab === 'invoices'">
            <div class="tw">
                <table>
                    <thead><tr><th>{{ t('inv.cols.number') }}</th><th>{{ t('inv.cols.customer') }}</th><th>{{ t('inv.cols.date') }}</th><th class="e">{{ t('inv.cols.trips') }}</th><th class="e">{{ t('inv.cols.value') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-if="!invoices.data.length"><td colspan="6"><div class="empty" style="border:0">{{ t('inv.none') }}</div></td></tr>
                        <template v-for="i in invoices.data" :key="i.id">
                            <tr class="ck" @click="expanded = expanded === i.id ? null : i.id">
                                <td><b class="num">{{ i.number }}</b><div v-if="i.note" class="xs mu">{{ i.note }}</div></td>
                                <td>{{ i.customer.name }}</td>
                                <td class="num sm">{{ i.issued_on ? date(i.issued_on) : '—' }}</td>
                                <td class="e num">{{ i.trips.length }}</td>
                                <td class="e"><b class="num">{{ money(i.value) }}</b></td>
                                <td class="e" style="white-space:nowrap" @click.stop>
                                    <button v-if="can('invoice_links.edit')" class="ib" :title="t('common.edit')" @click="openEdit(i)"><AppIcon name="edit" /></button>
                                    <button v-if="can('invoice_links.delete')" class="ib" :title="t('inv.unlink')" @click="removing = i"><AppIcon name="trash" /></button>
                                </td>
                            </tr>
                            <tr v-if="expanded === i.id"><td colspan="6" style="background:var(--bg2)">
                                <div v-for="x in i.trips" :key="x.id" class="rowlist" style="display:flex;justify-content:space-between;gap:12px;padding:4px 0">
                                    <span><Link v-if="can('trips.view')" :href="route('office.trips.show', x.id)" class="num b" style="color:var(--cu)">{{ x.number }}</Link><b v-else class="num">{{ x.number }}</b> <span class="mu sm">{{ x.route }}</span></span>
                                    <span class="num">{{ money(x.value) }}</span>
                                </div>
                            </td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <Pagination :links="invoices.links" />
        </template>

        <!-- Without an invoice -->
        <div v-else class="tw">
            <table>
                <thead><tr><th>{{ t('inv.cols.trip') }}</th><th>{{ t('inv.cols.customer') }}</th><th>{{ t('inv.cols.route') }}</th><th>{{ t('inv.cols.settled') }}</th><th class="e">{{ t('inv.cols.waiting') }}</th><th class="e">{{ t('inv.cols.value') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-if="!open.length"><td colspan="7"><div class="empty" style="border:0">{{ t('inv.noneOpen') }}</div></td></tr>
                    <tr v-for="x in open" :key="x.id">
                        <td><Link v-if="can('trips.view')" :href="route('office.trips.show', x.id)" class="num b" style="color:var(--cu)">{{ x.number }}</Link><b v-else class="num">{{ x.number }}</b>
                            <div v-if="x.vehicle"><Plate :number="x.vehicle.number" :letters="x.vehicle.letters" /></div></td>
                        <td>{{ x.customer.name }}</td>
                        <td class="sm">{{ x.route }}</td>
                        <td class="num sm">{{ x.settled_at ? date(x.settled_at) : '—' }}</td>
                        <td class="e num" :class="waitClass(x.days)">{{ x.days == null ? '—' : t('common.days', { n: x.days }) }}</td>
                        <td class="e"><b class="num">{{ money(x.value) }}</b></td>
                        <td class="e"><button v-if="can('invoice_links.create')" class="btn btn-ln btn-sm" @click="openLink(x)"><AppIcon name="link" /> {{ t('inv.linkOne') }}</button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Link -->
        <Modal :show="linkOpen" :title="t('inv.linkTitle')" wide @close="linkOpen = false">
            <form id="link-form" @submit.prevent="saveLink">
                <div class="fgrid">
                    <Field :label="t('inv.f.customer')" :error="link.errors.customer_id">
                        <select v-model="link.customer_id" required><option value="" disabled>{{ t('common.choose') }}</option><option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </Field>
                    <Field :label="t('inv.f.number')" :hint="t('inv.f.numberHint')" :error="link.errors.number"><input v-model="link.number" maxlength="60" dir="ltr" required></Field>
                    <Field :label="t('inv.f.date')" :hint="t('common.optional')" :error="link.errors.issued_on"><input v-model="link.issued_on" type="date" dir="ltr"></Field>
                    <Field :label="t('inv.f.note')" :hint="t('common.optional')" :error="link.errors.note"><input v-model="link.note" maxlength="250"></Field>
                </div>
                <div class="fld" style="margin-top:12px">
                    <span class="fl">{{ t('inv.f.trips') }}</span>
                    <div v-if="!link.customer_id" class="xs mu">{{ t('inv.f.pickCustomer') }}</div>
                    <div v-else-if="!candidates.length" class="note am"><AppIcon name="info" /><div>{{ t('inv.f.noCandidates') }}</div></div>
                    <div v-else class="rowlist" style="max-height:230px;overflow:auto">
                        <label v-for="x in candidates" :key="x.id" style="display:flex;gap:10px;align-items:center;padding:6px 0;cursor:pointer">
                            <input type="checkbox" :checked="link.trip_ids.includes(x.id)" @change="toggle(link.trip_ids, x.id)">
                            <b class="num">{{ x.number }}</b><span class="mu sm" style="flex:1">{{ x.route }}</span><span class="num">{{ money(x.value) }}</span>
                        </label>
                    </div>
                    <span v-if="link.errors.trip_ids" class="err">{{ link.errors.trip_ids }}</span>
                    <div v-if="link.trip_ids.length" class="xs mu" style="margin-top:6px">{{ t('inv.f.chosen', { n: link.trip_ids.length, v: money(chosenValue) }) }}</div>
                </div>
                <p class="xs mu" style="margin:8px 0 0">{{ t('inv.f.sameNumber') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="linkOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="link-form" class="btn btn-cu" :disabled="link.processing || !link.trip_ids.length"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <!-- Edit -->
        <Modal :show="!!editing" :title="t('inv.editTitle')" wide @close="editing = null">
            <form v-if="editing" id="edit-inv" @submit.prevent="saveEdit">
                <div class="fgrid">
                    <Field :label="t('inv.f.number')" :error="edit.errors.number"><input v-model="edit.number" maxlength="60" dir="ltr" required></Field>
                    <Field :label="t('inv.f.date')" :hint="t('common.optional')" :error="edit.errors.issued_on"><input v-model="edit.issued_on" type="date" dir="ltr"></Field>
                </div>
                <Field :label="t('inv.f.note')" :hint="t('common.optional')" :error="edit.errors.note"><input v-model="edit.note" maxlength="250"></Field>
                <div class="fld" style="margin-top:12px">
                    <span class="fl">{{ t('inv.f.tripsOn') }}</span>
                    <div class="rowlist">
                        <label v-for="x in editing.trips" :key="x.id" style="display:flex;gap:10px;align-items:center;padding:6px 0;cursor:pointer">
                            <input type="checkbox" :checked="!edit.remove.includes(x.id)" @change="toggle(edit.remove, x.id)">
                            <b class="num">{{ x.number }}</b><span class="mu sm" style="flex:1">{{ x.route }}</span><span class="num">{{ money(x.value) }}</span>
                        </label>
                    </div>
                    <span class="xs mu">{{ t('inv.f.untickHint') }}</span>
                </div>
                <div v-if="addable.length" class="fld" style="margin-top:12px">
                    <span class="fl">{{ t('inv.f.addMore') }}</span>
                    <div class="rowlist" style="max-height:180px;overflow:auto">
                        <label v-for="x in addable" :key="x.id" style="display:flex;gap:10px;align-items:center;padding:6px 0;cursor:pointer">
                            <input type="checkbox" :checked="edit.add.includes(x.id)" @change="toggle(edit.add, x.id)">
                            <b class="num">{{ x.number }}</b><span class="mu sm" style="flex:1">{{ x.route }}</span><span class="num">{{ money(x.value) }}</span>
                        </label>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="editing = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="edit-inv" class="btn btn-cu" :disabled="edit.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!removing" :title="t('inv.unlink')" :message="t('inv.unlinkConfirm', { n: removing?.number ?? '' })" danger @confirm="doRemove" @cancel="removing = null" />
    </PortalLayout>
</template>
