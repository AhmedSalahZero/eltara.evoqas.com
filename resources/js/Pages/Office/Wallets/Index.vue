<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Wallets & settlement ( /office/wallets )
  Location: resources/js/Pages/Office/Wallets/Index.vue

  Scope §6.4 "Wallets & settlement screen": the company's cash
  outside the safe (custody, collections, advances, own-pocket owed),
  then four tabs:
    Waiting for approval  approve / reject — within your approval
                          limit; above it only the company admin
    Automatic — to review transfers that went through on their own;
                          looked at the next morning and marked reviewed
    Trips to settle       delivered trips and what each driver hands over
    Driver balances       every driver's wallets, biggest holders first
  Server: App\Http\Controllers\Office\WalletController.
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
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num, time } from '@/Utils/format';

const props = defineProps({ tab: String, tiles: Object, pending: Array, review: Array, settle: Array, balances: Array });
const { t } = useI18n();
const { can } = usePermissions();

const go = (tab) => router.get(route('office.wallets.index'), { tab }, { preserveScroll: true, preserveState: false });
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');

const approve = (x) => router.post(route('office.transfers.approve', x.id), {}, { preserveScroll: true });
const review = (x) => router.post(route('office.transfers.review', x.id), {}, { preserveScroll: true });
const rejecting = ref(null);
const rejectForm = useForm({ note: '' });
const reject = () => rejectForm.post(route('office.transfers.reject', rejecting.value.id), { preserveScroll: true, onSuccess: () => (rejecting.value = null) });

const tabs = [['pending', 'pending'], ['review', 'review'], ['settle', 'settle'], ['balances', null]];
</script>

<template>
    <PortalLayout :title="t('wal.title')">
        <PageHeader :title="t('wal.title')" :sub="t('wal.sub')" />

        <div class="g4">
            <Kpi :label="t('wal.custody')" :value="money(tiles.custody)" unit="EGP" color="var(--bl)" />
            <Kpi :label="t('wal.collections')" :value="money(tiles.collections)" unit="EGP" color="var(--vi)"
                 :foot="tiles.unconfirmed ? t('wal.unconfirmed', { amount: money(tiles.unconfirmed) }) : ''" />
            <Kpi :label="t('wal.advances')" :value="money(tiles.advances)" unit="EGP" color="var(--am)" :foot="t('drv.advancesSub')" />
            <Kpi :label="t('wal.pocket')" :value="money(tiles.pocket)" unit="EGP" color="var(--gn)" :foot="t('wal.pocketSub')" />
        </div>

        <div class="tabs" style="margin-top:18px">
            <button v-for="[key, count] in tabs" :key="key" class="tab" :class="{ on: tab === key }" @click="go(key)">
                {{ t('wal.tabs.' + key) }}<span v-if="count" class="c">{{ tiles[count] }}</span>
            </button>
        </div>

        <!-- Waiting for approval / to review -->
        <template v-if="tab === 'pending' || tab === 'review'">
            <p class="xs mu" style="margin:-6px 0 12px">
                <template v-if="tab === 'review'">{{ t('wal.reviewHint') }}</template>
                <template v-else-if="tiles.limit === null">{{ t('wal.adminAll') }}</template>
                <template v-else>{{ t('wal.yourLimit', { amount: money(tiles.limit) }) }}</template>
            </p>
            <div class="tw">
                <table>
                    <thead><tr>
                        <th>{{ t('wal.cols.trip') }}</th><th>{{ t('wal.cols.driver') }}</th><th class="e">{{ t('wal.cols.amount') }}</th>
                        <th>{{ t('wal.cols.reason') }}</th><th>{{ t('wal.cols.requested') }}</th><th>{{ t('wal.cols.policy') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-if="!(tab === 'pending' ? pending : review).length"><td colspan="7"><div class="empty" style="border:0">{{ t('wal.none.' + tab) }}</div></td></tr>
                        <tr v-for="x in (tab === 'pending' ? pending : review)" :key="x.id">
                            <td><Link :href="route('office.trips.show', x.trip.id)" class="num b" style="color:var(--cu)">{{ x.trip.number }}</Link><div class="xs mu">{{ x.trip.route }}</div></td>
                            <td class="sm">{{ x.driver }}</td>
                            <td class="e"><b class="num">{{ money(x.amount) }}</b></td>
                            <td class="sm">{{ x.reason }}<div v-if="x.is_expense" class="xs mu">{{ t('trip.forExpense') }}</div></td>
                            <td class="xs mu">{{ x.by_driver ? t('trip.byDriver') : t('trip.byOffice') }} · {{ x.requested_by }}<div class="num">{{ when(x.requested_at) }}</div></td>
                            <td class="xs">{{ t('trip.policies.' + x.policy, { limit: money(x.policy_limit) }) }}</td>
                            <td class="e" style="white-space:nowrap">
                                <template v-if="tab === 'pending' && can('wallet_transfers.approve')">
                                    <template v-if="x.can_approve">
                                        <button class="btn btn-gn btn-sm" @click="approve(x)"><AppIcon name="check" /> {{ t('trip.approve') }}</button>
                                        <button class="btn btn-rd btn-sm" style="margin-inline-start:6px" @click="rejecting = x; rejectForm.reset()">{{ t('trip.reject') }}</button>
                                    </template>
                                    <span v-else class="xs am">{{ t('trip.aboveLimit') }}</span>
                                </template>
                                <button v-if="tab === 'review' && can('wallet_transfers.approve')" class="btn btn-ln btn-sm" @click="review(x)"><AppIcon name="eye" /> {{ t('trip.review') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Trips to settle -->
        <div v-if="tab === 'settle'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('wal.cols.trip') }}</th><th>{{ t('wal.cols.driver') }}</th><th>{{ t('wal.cols.delivered') }}</th>
                    <th class="e">{{ t('wal.cols.custody') }}</th><th class="e">{{ t('wal.cols.collections') }}</th><th class="e">{{ t('wal.cols.pocket') }}</th>
                    <th class="e">{{ t('wal.cols.net') }}</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-if="!settle.length"><td colspan="8"><div class="empty" style="border:0">{{ t('wal.none.settle') }}</div></td></tr>
                    <tr v-for="s in settle" :key="s.id" class="ck" @click="router.visit(route('office.trips.show', s.id))">
                        <td><b class="num">{{ s.number }}</b><div class="xs mu">{{ s.customer }} · {{ s.route }}</div></td>
                        <td class="sm">{{ s.driver ?? '—' }}<div v-if="s.vehicle"><Plate :number="s.vehicle.number" :letters="s.vehicle.letters" /></div></td>
                        <td class="xs mu num">{{ when(s.delivered_at) }}</td>
                        <td class="e num" style="color:var(--bl)">{{ money(s.custody) }}</td>
                        <td class="e num" style="color:var(--vi)">{{ money(s.collections) }}</td>
                        <td class="e num">{{ s.pocket ? '−' + money(s.pocket) : '—' }}</td>
                        <td class="e"><b class="num" :class="s.net >= 0 ? 'gn' : 'am'">{{ money(s.net) }}</b></td>
                        <td class="e"><span class="bd nodot" :class="s.blocked ? 'am' : 'gn'">{{ s.blocked ? t('wal.blocked') : t('wal.ready') }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Driver balances -->
        <div v-if="tab === 'balances'" class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('wal.cols.driver') }}</th><th>{{ t('wal.cols.vehicle') }}</th><th class="e">{{ t('wal.cols.openTrips') }}</th>
                    <th class="e">{{ t('wal.cols.custody') }}</th><th class="e">{{ t('wal.cols.collections') }}</th><th class="e">{{ t('wal.cols.held') }}</th>
                    <th class="e">{{ t('wal.cols.advances') }}</th><th class="e">{{ t('wal.cols.pocket') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-if="!balances.length"><td colspan="8"><div class="empty" style="border:0">{{ t('wal.none.balances') }}</div></td></tr>
                    <tr v-for="d in balances" :key="d.id" :class="{ ck: can('drivers.view') }" @click="can('drivers.view') && router.visit(route('office.drivers.show', d.id))">
                        <td><b class="sm">{{ d.name }}</b></td>
                        <td><Plate v-if="d.vehicle" :number="d.vehicle.number" :letters="d.vehicle.letters" /><span v-else class="mu">—</span></td>
                        <td class="e num">{{ num(d.open_trips) }}</td>
                        <td class="e num" style="color:var(--bl)">{{ money(d.custody) }}</td>
                        <td class="e num" style="color:var(--vi)">{{ money(d.collections) }}</td>
                        <td class="e"><b class="num">{{ money(d.held) }}</b></td>
                        <td class="e num" style="color:var(--am)">{{ money(d.advances) }}</td>
                        <td class="e num">{{ money(d.pocket) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="!!rejecting" :title="t('trip.rejectTitle')" @close="rejecting = null">
            <form id="wal-reject" @submit.prevent="reject">
                <p v-if="rejecting" class="sm" style="margin:10px 0 0"><b class="num">{{ money(rejecting.amount) }}</b> · {{ rejecting.reason }}</p>
                <Field :label="t('trip.rejectReason')" :error="rejectForm.errors.note"><input v-model="rejectForm.note" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="rejecting = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="wal-reject" class="btn btn-rd" :disabled="rejectForm.processing">{{ t('trip.reject') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
