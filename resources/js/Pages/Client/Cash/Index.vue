<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Cash handed to drivers ( /client/cash )
  Location: resources/js/Pages/Client/Cash/Index.vue

  Scope §7 + §9 two-sided confirmation: totals (handed, confirmed by
  both, waiting, disputed), the full log with confirm / dispute, and
  "record an amount handed" (only for clients allowed to pay drivers
  in cash). A disputed amount does not count until management decides.
  Server: App\Http\Controllers\Client\CashController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, money, time } from '@/Utils/format';

const props = defineProps({ totals: Object, rows: Array, trips: Array, canRecord: Boolean, filter: String });
const { t } = useI18n();
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');
const cState = { confirmed: 'gn', awaiting_client: 'am', awaiting_driver: 'am', disputed: 'rd', cancelled: 'pl' };

const go = (filter) => router.get(route('client.cash.index'), { filter }, { preserveScroll: true });
const confirm = (c) => router.post(route('client.cash.confirm', c.id), {}, { preserveScroll: true });

const disputing = ref(null);
const disputeForm = useForm({ note: '' });
const dispute = () => disputeForm.post(route('client.cash.dispute', disputing.value.id), { preserveScroll: true, onSuccess: () => (disputing.value = null) });

const recording = ref(false);
const form = useForm({ trip_id: '', amount: '', note: '' });
const record = () => form.post(route('client.cash.record'), { preserveScroll: true, onSuccess: () => { recording.value = false; form.reset(); } });
</script>

<template>
    <PortalLayout :title="t('nav.clientCash')">
        <PageHeader :title="t('nav.clientCash')" :sub="t('cp.cashSub')">
            <button v-if="canRecord" class="btn btn-cu" :disabled="!trips.length" @click="recording = true"><AppIcon name="plus" /> {{ t('cp.recordCash') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('cp.cash.handed')" :value="money(totals.handed)" unit="EGP" color="var(--bl)" />
            <Kpi :label="t('cp.cash.confirmed')" :value="money(totals.confirmed)" unit="EGP" color="var(--gn)" />
            <Kpi :label="t('cp.cash.waiting')" :value="money(totals.waiting)" unit="EGP" color="var(--am)" />
            <Kpi :label="t('cp.cash.disputed')" :value="money(totals.disputed)" unit="EGP" color="var(--rd)" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="k in ['all', 'waiting', 'disputed', 'confirmed']" :key="k" class="tab" :class="{ on: filter === k }" @click="go(k)">{{ t('cp.cf.' + k) }}</button>
        </div>

        <div class="tw">
            <table>
                <thead><tr><th>{{ t('cp.col.trip') }}</th><th class="e">{{ t('cp.amount') }}</th><th>{{ t('cp.col.when') }}</th><th>{{ t('cp.col.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="5"><div class="empty" style="border:0">{{ t('cp.noCash') }}</div></td></tr>
                    <tr v-for="c in rows" :key="c.id">
                        <td><Link v-if="c.trip" :href="route('client.shipments.show', c.trip.id)" class="num b" style="color:var(--cu)">{{ c.trip.number }}</Link></td>
                        <td class="e"><b class="num" :style="c.state === 'cancelled' ? 'text-decoration:line-through;opacity:.6' : ''">{{ money(c.amount) }}</b></td>
                        <td class="xs mu"><span class="num">{{ when(c.received_at) }}</span><div>{{ t('cp.by.' + c.recorded_by) }}<template v-if="c.note"> · {{ c.note }}</template></div></td>
                        <td><span class="bd nodot" :class="cState[c.state]">{{ t('cp.cState.' + c.state) }}</span>
                            <div v-if="c.dispute_note" class="xs mu">«{{ c.dispute_note }}»</div></td>
                        <td class="e" style="white-space:nowrap">
                            <template v-if="c.state === 'awaiting_client'">
                                <button class="btn btn-gn btn-sm" @click="confirm(c)"><AppIcon name="check" /> {{ t('cp.confirm') }}</button>
                                <button class="btn btn-rd btn-sm" style="margin-inline-start:6px" @click="disputing = c; disputeForm.reset()">{{ t('cp.dispute') }}</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="!!disputing" :title="t('cp.disputeTitle')" @close="disputing = null">
            <form id="cash-dispute" @submit.prevent="dispute">
                <p v-if="disputing" class="sm" style="margin:10px 0 0"><b class="num">{{ money(disputing.amount) }}</b></p>
                <Field :label="t('cp.disputeWhy')" :error="disputeForm.errors.note"><input v-model="disputeForm.note" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="disputing = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="cash-dispute" class="btn btn-rd" :disabled="disputeForm.processing">{{ t('cp.dispute') }}</button>
            </template>
        </Modal>

        <Modal :show="recording" :title="t('cp.recordCash')" @close="recording = false">
            <form id="cash-record" @submit.prevent="record">
                <p class="xs mu" style="margin:10px 0 0">{{ t('cp.recordCashHint') }}</p>
                <Field :label="t('cp.col.trip')" :error="form.errors.trip_id">
                    <select v-model="form.trip_id" required><option value="" disabled>{{ t('common.choose') }}</option><option v-for="x in trips" :key="x.id" :value="x.id">{{ x.number }}</option></select>
                </Field>
                <Field :label="t('cp.amount')" :error="form.errors.amount"><input v-model="form.amount" type="number" min="1" step="0.01" dir="ltr" required></Field>
                <Field :label="t('cp.noteOpt')" :error="form.errors.note"><input v-model="form.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="recording = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="cash-record" class="btn btn-cu" :disabled="form.processing">{{ t('common.save') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
