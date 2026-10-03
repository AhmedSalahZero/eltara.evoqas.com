<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Customers & rate cards ( /office/customers )
  Location: resources/js/Pages/Office/Customers/Index.vue

  The demo's screen (Scope §6.9):
    · customers list (payment terms, pays cash, number of routes);
    · the selected customer's RATE CARD: route, km, agreed price,
      price per km, the route's standard cost, expected direct profit
      and profit after G&A (km × the G&A estimate in settings);
    · its CLIENT-PORTAL USERS: invite (activation email), suspend,
      resend the activation link.
  Server: App\Http\Controllers\Office\CustomerController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CustomerForm from '@/Components/CustomerForm.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { ago, money, num } from '@/Utils/format';

const props = defineProps({ customers: Array, selected: Object, routes: Array, gaRate: Number, filters: Object });
const { t, locale } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
watch(search, useDebounceFn(() => router.get(route('office.customers.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 350));

const pick = (c) => router.get(route('office.customers.index'), { customer: c.id, search: search.value || undefined }, { preserveState: true, preserveScroll: true, only: ['selected'] });

// ── Customer form ────────────────────────────────────────────────
const customerOpen = ref(false);
const editingCustomer = ref(null);
const openCustomer = (c = null) => { editingCustomer.value = c; customerOpen.value = true; };

// ── Rate line form ───────────────────────────────────────────────
const rateOpen = ref(false);
const editingRate = ref(null);
const rateForm = useForm({ trip_route_id: '', price: '', notes: '' });
const unpricedRoutes = computed(() => props.routes.filter((r) => !props.selected?.rates.some((x) => x.route_id === r.id)));
const chosenRoute = computed(() => props.routes.find((r) => r.id === Number(editingRate.value?.route_id ?? rateForm.trip_route_id)));

function openRate(line = null) {
    editingRate.value = line;
    rateForm.defaults({ trip_route_id: line?.route_id ?? '', price: line?.price ?? '', notes: line?.notes ?? '' });
    rateForm.reset();
    rateForm.clearErrors();
    rateOpen.value = true;
}
function saveRate() {
    const c = props.selected;
    const options = { preserveScroll: true, onSuccess: () => (rateOpen.value = false) };
    editingRate.value
        ? rateForm.patch(route('office.customers.rates.update', [c.id, editingRate.value.id]), options)
        : rateForm.post(route('office.customers.rates.store', c.id), options);
}

// ── Client-portal users ──────────────────────────────────────────
const inviteOpen = ref(false);
const inviteForm = useForm({ name: '', email: '', phone: '', job_title: '', language: locale.value });
function openInvite() { inviteForm.reset(); inviteForm.clearErrors(); inviteOpen.value = true; }
const sendInvite = () => inviteForm.post(route('office.customers.clients.store', props.selected.id), { preserveScroll: true, onSuccess: () => (inviteOpen.value = false) });
const toggleClient = (u) => router.post(route('office.customers.clients.toggle', [props.selected.id, u.id]), {}, { preserveScroll: true });
const resendClient = (u) => router.post(route('office.customers.clients.link', [props.selected.id, u.id]), {}, { preserveScroll: true });

// ── Deleting ─────────────────────────────────────────────────────
const confirming = ref(null);
function doConfirm() {
    const c = confirming.value;
    if (c.kind === 'rate') router.delete(route('office.customers.rates.destroy', [props.selected.id, c.line.id]), { preserveScroll: true, onFinish: () => (confirming.value = null) });
    if (c.kind === 'customer') router.delete(route('office.customers.destroy', props.selected.id), { onFinish: () => (confirming.value = null) });
}

const profitClass = (v) => (v == null ? '' : v < 0 ? 'rd' : 'gn');
</script>

<template>
    <PortalLayout :title="t('cust.title')">
        <PageHeader :title="t('cust.title')" :sub="t('cust.sub')">
            <button v-if="can('customers.create')" class="btn btn-cu" @click="openCustomer()"><AppIcon name="plus" /> {{ t('cust.add') }}</button>
        </PageHeader>

        <div class="g-37">
            <div>
                <label class="srch" style="max-width:none;margin-bottom:10px"><AppIcon name="search" /><input v-model="search" :placeholder="t('cust.searchPh')"></label>
                <div class="tw">
                    <table>
                        <thead><tr><th>{{ t('cust.customer') }}</th><th class="e">{{ t('cust.routes') }}</th></tr></thead>
                        <tbody>
                            <tr v-if="!customers.length"><td colspan="2"><div class="empty" style="border:0">{{ filters.search ? t('common.noResults') : t('cust.none') }}</div></td></tr>
                            <tr v-for="c in customers" :key="c.id" class="ck" :style="c.id === selected?.id ? 'background:var(--cu-dim)' : ''" @click="pick(c)">
                                <td>
                                    <b>{{ c.name }}</b> <span v-if="!c.is_active" class="bd pl nodot">{{ t('status.suspended') }}</span>
                                    <div class="xs mu">{{ c.payment_terms_days ? t('cust.terms', { n: c.payment_terms_days }) : t('cust.cashTerms') }}<template v-if="c.may_pay_driver_cash"> · {{ t('cust.paysCash') }}</template></div>
                                </td>
                                <td class="e num">{{ c.routes_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="selected" style="display:grid;gap:14px;align-content:start">
                <div class="pn">
                    <div class="pn-h">
                        <div><h3><AppIcon name="tag" />{{ t('cust.rateCard') }} — {{ selected.name }}</h3><div class="s">{{ t('cust.rateSub') }}</div></div>
                        <div style="display:flex;gap:6px">
                            <button v-if="can('customers.edit')" class="btn btn-ln sm" :disabled="!unpricedRoutes.length" @click="openRate()"><AppIcon name="plus" /> {{ t('cust.addRoute') }}</button>
                            <button v-if="can('customers.edit')" class="btn btn-ln sm" @click="openCustomer(selected)"><AppIcon name="edit" /></button>
                            <button v-if="can('customers.delete')" class="btn btn-ln sm" @click="confirming = { kind: 'customer' }"><AppIcon name="trash" /></button>
                        </div>
                    </div>
                    <div class="tw flat">
                        <table>
                            <thead>
                                <tr><th>{{ t('cust.route') }}</th><th class="e">{{ t('cust.km') }}</th><th class="e">{{ t('cust.price') }}</th><th class="e">{{ t('cust.standard') }}</th><th class="e">{{ t('cust.expected') }}</th><th class="e">{{ t('cust.afterGa') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!selected.rates.length"><td colspan="6"><div class="empty" style="border:0">{{ routes.length ? t('cust.noRates') : t('cust.noRoutes') }}</div></td></tr>
                                <tr v-for="r in selected.rates" :key="r.id" :class="{ ck: can('customers.edit') }" @click="can('customers.edit') && openRate(r)">
                                    <td class="sm"><b class="route">{{ r.route }}</b></td>
                                    <td class="e num">{{ num(r.km) }}</td>
                                    <td class="e"><b class="num">{{ money(r.price) }}</b><div class="xs mu num">{{ num(r.per_km, 2) }} {{ t('cust.perKm') }}</div></td>
                                    <td class="e num">{{ money(r.standard) }}</td>
                                    <td class="e num" :class="profitClass(r.direct)">{{ money(r.direct) }}</td>
                                    <td class="e num" :class="profitClass(r.after_ga)">{{ r.after_ga == null ? '—' : money(r.after_ga) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="gaRate == null && selected.rates.length" class="xs mu" style="margin:10px 0 0">{{ t('cust.noGa') }}</p>
                </div>

                <div class="pn">
                    <div class="pn-h">
                        <div><h3><AppIcon name="users" />{{ t('cust.portalUsers') }}</h3><div class="s">{{ t('cust.portalSub') }}</div></div>
                        <button v-if="can('customers.edit')" class="btn btn-ln sm" @click="openInvite"><AppIcon name="plus" /> {{ t('cust.invite') }}</button>
                    </div>
                    <div v-if="!selected.clients.length" class="empty">{{ t('cust.noPortal') }}</div>
                    <div v-for="u in selected.clients" :key="u.id" class="doc-row">
                        <span class="di"><AppIcon name="person" /></span>
                        <div>
                            <b>{{ u.name }}
                                <span v-if="u.is_admin" class="bd cu nodot">{{ t('cust.accountAdmin') }}</span>
                                <span v-if="!u.is_active" class="bd pl nodot">{{ t('users.suspended') }}</span>
                                <span v-else-if="!u.activated" class="bd am nodot">{{ t('users.pending') }}</span>
                            </b>
                            <span class="num" style="color:var(--cu)">{{ u.email }}</span>
                            <span v-if="u.last_login_at"> · {{ ago(u.last_login_at, locale) }}</span>
                        </div>
                        <div v-if="can('customers.edit')" style="display:flex;gap:4px">
                            <button v-if="!u.activated && u.is_active" class="btn btn-gh sm" :title="t('users.sendActivation')" @click="resendClient(u)"><AppIcon name="sync" /></button>
                            <button class="btn btn-gh sm" :title="u.is_active ? t('users.suspend') : t('users.reactivate')" @click="toggleClient(u)"><AppIcon :name="u.is_active ? 'lock' : 'unlock'" /></button>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="empty">{{ t('cust.none') }}</div>
        </div>

        <CustomerForm :show="customerOpen" :customer="editingCustomer" @close="customerOpen = false" />

        <Modal :show="rateOpen" :title="editingRate ? t('cust.priceFor', { route: editingRate.route }) : t('cust.addRoute')" @close="rateOpen = false">
            <form id="rate-form" @submit.prevent="saveRate">
                <Field v-if="!editingRate" :label="t('cust.route')" :error="rateForm.errors.trip_route_id">
                    <select v-model="rateForm.trip_route_id" required>
                        <option value="" disabled>{{ t('cust.chooseRoute') }}</option>
                        <option v-for="r in unpricedRoutes" :key="r.id" :value="r.id">{{ r.name }} · {{ num(r.km) }} km</option>
                    </select>
                </Field>
                <Field :label="t('cust.price')" :error="rateForm.errors.price"><input v-model="rateForm.price" type="number" min="1" step="any" dir="ltr" required></Field>
                <div v-if="chosenRoute && rateForm.price" class="kv" style="margin-top:12px">
                    <div><span>{{ t('cust.standard') }}</span><b class="num">{{ money(chosenRoute.standard) }}</b></div>
                    <div><span>{{ t('cust.expected') }}</span><b class="num" :class="profitClass(rateForm.price - chosenRoute.standard)">{{ money(rateForm.price - chosenRoute.standard) }}</b></div>
                    <div v-if="gaRate != null"><span>{{ t('cust.afterGa') }}</span><b class="num" :class="profitClass(rateForm.price - chosenRoute.standard - chosenRoute.km * gaRate)">{{ money(rateForm.price - chosenRoute.standard - chosenRoute.km * gaRate) }}</b></div>
                </div>
                <Field :label="t('cust.notes')" :error="rateForm.errors.notes"><input v-model="rateForm.notes"></Field>
            </form>
            <template #footer>
                <button v-if="editingRate" type="button" class="btn btn-rd" style="margin-inline-end:auto" @click="rateOpen = false; confirming = { kind: 'rate', line: editingRate }"><AppIcon name="trash" /></button>
                <button type="button" class="btn btn-ln" @click="rateOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="rate-form" class="btn btn-cu" :disabled="rateForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="inviteOpen" :title="t('cust.inviteTitle')" @close="inviteOpen = false">
            <p class="sm mu" style="margin:12px 0 0">{{ t('cust.inviteHint') }}</p>
            <form id="invite-form" @submit.prevent="sendInvite">
                <div class="fgrid">
                    <Field :label="t('users.name')" :error="inviteForm.errors.name"><input v-model="inviteForm.name" required></Field>
                    <Field :label="t('users.jobTitle')" :error="inviteForm.errors.job_title"><input v-model="inviteForm.job_title"></Field>
                    <Field class="w" :label="t('users.email')" :error="inviteForm.errors.email"><input v-model="inviteForm.email" type="email" dir="ltr" required></Field>
                    <Field :label="t('users.mobile')" :error="inviteForm.errors.phone"><input v-model="inviteForm.phone" dir="ltr" inputmode="tel"></Field>
                    <Field :label="t('users.language')"><select v-model="inviteForm.language"><option value="ar">العربية</option><option value="en">English</option></select></Field>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="inviteOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="invite-form" class="btn btn-cu" :disabled="inviteForm.processing"><AppIcon name="check" /> {{ t('users.inviteBtn') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!confirming" :title="t('common.delete')" danger :confirm-label="t('common.delete')"
                       :message="confirming?.kind === 'rate' ? t('cust.removeRate', { route: confirming.line.route }) : t('cust.deleteConfirm', { name: selected?.name })"
                       @confirm="doConfirm" @cancel="confirming = null" />
    </PortalLayout>
</template>
