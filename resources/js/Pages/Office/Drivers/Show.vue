<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver file ( /office/drivers/{id} )
  Location: resources/js/Pages/Office/Drivers/Show.vue
  The demo's driver file: name, mobile, since, licence; the three
  wallet tiles (custody, collections, advances — live from the wallet
  ledger since Step 3, plus own-pocket money the company owes him);
  his latest trips with budget vs actual; details; and the account
  actions: edit, new PIN, suspend / reactivate, delete.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Plate from '@/Components/Plate.vue';
import DocBadge from '@/Components/DocBadge.vue';
import TripStatus from '@/Components/TripStatus.vue';
import DriverForm from '@/Components/DriverForm.vue';
import PinNotice from '@/Components/PinNotice.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { ago, avatarColor, date, money } from '@/Utils/format';

const props = defineProps({ driver: Object, wallets: Object, trips: Array, options: Object });
const { t, locale } = useI18n();
const { can } = usePermissions();

const editOpen = ref(false);
const confirming = ref(null); // 'pin' | 'toggle' | 'delete'

function confirmAction() {
    const d = props.driver;
    const done = { preserveScroll: true, onFinish: () => (confirming.value = null) };
    if (confirming.value === 'pin') router.post(route('office.drivers.pin', d.id), {}, done);
    if (confirming.value === 'toggle') router.post(route('office.drivers.toggle', d.id), {}, done);
    if (confirming.value === 'delete') router.delete(route('office.drivers.destroy', d.id));
}

const message = () => ({
    pin: t('drv.resetConfirm', { name: props.driver.name }),
    toggle: props.driver.is_active ? t('drv.confirmSuspend', { name: props.driver.name }) : t('users.confirmReactivate', { name: props.driver.name }),
    delete: t('drv.deleteConfirm', { name: props.driver.name }),
}[confirming.value] ?? '');
</script>

<template>
    <PortalLayout :title="driver.name">
        <Link :href="route('office.drivers.index')" class="back"><AppIcon name="back" :size="15" /> {{ t('drv.back') }}</Link>
        <div class="ph">
            <div class="nc">
                <span class="av" :style="{ background: avatarColor(driver.name), width: '56px', height: '56px', fontSize: '18px' }">{{ driver.initials }}</span>
                <div>
                    <h1>{{ driver.name }} <span v-if="!driver.is_active" class="bd pl nodot">{{ t('drv.suspended') }}</span></h1>
                    <p>
                        <span class="num">{{ driver.mobile }}</span>
                        <template v-if="driver.joined_at"> · {{ t('drv.since', { date: date(driver.joined_at) }) }}</template>
                        <template v-if="driver.license_expires_at"> · {{ t('drv.licenceUntil', { date: date(driver.license_expires_at) }) }}</template>
                    </p>
                </div>
            </div>
            <div v-if="can('drivers.edit')" class="acts">
                <button class="btn btn-ln" @click="editOpen = true"><AppIcon name="edit" /> {{ t('common.edit') }}</button>
                <button class="btn btn-ln" @click="confirming = 'pin'"><AppIcon name="key" /> {{ t('drv.resetPin') }}</button>
                <button class="btn" :class="driver.is_active ? 'btn-rd' : 'btn-ln'" @click="confirming = 'toggle'">
                    <AppIcon :name="driver.is_active ? 'lock' : 'unlock'" /> {{ driver.is_active ? t('drv.suspend') : t('drv.reactivate') }}
                </button>
                <button v-if="can('drivers.delete')" class="btn btn-rd" @click="confirming = 'delete'"><AppIcon name="trash" /></button>
            </div>
        </div>

        <div class="g3">
            <div class="wal w-cu"><div class="wh"><span class="ic"><AppIcon name="wallet" /></span><div><b>{{ t('drv.custody') }}</b><span>{{ t('drv.openTrips', { n: wallets.open_trips }) }}</span></div></div><div class="bal">{{ money(wallets.custody) }}</div></div>
            <div class="wal w-co"><div class="wh"><span class="ic"><AppIcon name="hand" /></span><div><b>{{ t('drv.collections') }}</b><span>{{ t('drv.allTrips') }}</span></div></div><div class="bal">{{ money(wallets.collections) }}</div></div>
            <div class="wal w-ad"><div class="wh"><span class="ic"><AppIcon name="person" /></span><div><b>{{ t('drv.advances') }}</b><span>{{ t('drv.advancesSub') }}</span></div></div><div class="bal">{{ money(wallets.advances) }}</div></div>
        </div>
        <p v-if="wallets.pocket" class="xs mu" style="margin:8px 0 0">{{ t('drv.pocketOwed', { amount: money(wallets.pocket) }) }}</p>
        <p v-if="can('driver_advances.view')" class="xs" style="margin:6px 0 0"><Link :href="route('office.advances.index')" style="color:var(--cu)">{{ t('drv.openAdvances') }}</Link></p>

        <div v-if="can('trips.view')" class="pn" style="margin-top:14px">
            <div class="pn-h"><div><h3><AppIcon name="route" />{{ t('drv.latestTrips') }}</h3></div></div>
            <div v-if="!trips.length" class="empty">{{ t('drv.noTrips') }}</div>
            <div v-else class="tw" style="border:0">
                <table>
                    <thead><tr><th>{{ t('trip.cols.trip') }}</th><th>{{ t('trip.cols.route') }}</th><th class="e">{{ t('trip.stdCol') }}</th><th class="e">{{ t('trip.actualCol') }}</th><th class="e">{{ t('drv.budgetVar') }}</th><th class="e">{{ t('drv.held') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="tr in trips" :key="tr.id" class="ck" @click="router.visit(route('office.trips.show', tr.id))">
                            <td><b class="num">{{ tr.number }}</b> <TripStatus :status="tr.status" /><div class="xs mu num">{{ date(tr.loading_at) }}</div></td>
                            <td class="sm">{{ tr.route }}<div class="xs mu">{{ tr.customer }}</div></td>
                            <td class="e num">{{ money(tr.standard) }}</td>
                            <td class="e num">{{ money(tr.cost) }}</td>
                            <td class="e num" :class="tr.variance > 0 ? 'rd' : 'gn'">{{ tr.variance > 0 ? '+' : '' }}{{ money(tr.variance) }}</td>
                            <td class="e num">{{ tr.held == null ? '—' : money(tr.held) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pn" style="margin-top:14px">
            <div class="pn-h"><div><h3><AppIcon name="idcard" />{{ t('drv.details') }}</h3></div></div>
            <div class="kv">
                <div><span>{{ t('drv.vehicle') }}</span><b><Plate v-if="driver.vehicle" :number="driver.vehicle.number" :letters="driver.vehicle.letters" /><template v-else>—</template></b></div>
                <div><span>{{ t('drv.payBasis') }}</span><b>{{ t('drv.pay.' + driver.pay_basis) }}</b></div>
                <div><span>{{ t('drv.baseSalary') }}</span><b class="num">{{ driver.base_salary ? money(driver.base_salary) : '—' }}</b></div>
                <div><span>{{ t('drv.licenceNo') }}</span><b class="num">{{ driver.license_number || '—' }}</b></div>
                <div><span>{{ t('drv.licence') }}</span><b><DocBadge :doc="driver.licence" /></b></div>
                <div><span>{{ t('drv.app') }}</span><b class="sm">{{ driver.last_sync_at ? t('drv.lastSync', { when: ago(driver.last_sync_at, locale) }) : t('drv.neverSynced') }}</b></div>
            </div>
            <p v-if="driver.notes" class="sm" style="margin:12px 0 0;white-space:pre-line">{{ driver.notes }}</p>
        </div>

        <DriverForm :show="editOpen" :driver="driver" :vehicles="options.vehicles" @close="editOpen = false" />
        <ConfirmDialog :show="!!confirming" :title="confirming === 'pin' ? t('drv.resetPin') : confirming === 'delete' ? t('common.delete') : (driver.is_active ? t('drv.suspend') : t('drv.reactivate'))"
                       :message="message()" :danger="confirming !== 'pin'" @confirm="confirmAction" @cancel="confirming = null" />
        <PinNotice />
    </PortalLayout>
</template>
