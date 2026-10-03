<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: home
  Location: resources/js/driver/screens/Home.vue

  Scope §8.2 Home: the current trip with its ONE big next-step button,
  quick actions (expense, cash, use client money) and the wallet
  balances. Works with no signal: it all comes from the phone.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import Icon from '@/Components/AppIcon.vue';
import TripCard from '../components/TripCard.vue';
import { currentTrip, cashToConfirm } from '../projection';
import { state, view } from '../store';
import { money } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';
import { time, date } from '@/Utils/format';

const { t, locale } = useI18n();

const trip = computed(() => currentTrip(view.value.trips));
const running = computed(() => trip.value && trip.value.status !== 'planned');
const toConfirm = computed(() => cashToConfirm(view.value.trips).length);
/** New trips waiting for the driver to accept (Scope §8.2 urgent banner). */
const toAccept = computed(() => view.value.trips.filter((x) => x.status === 'planned').length);
/** The rest of the trips, after the one in front. */
const nextTrips = computed(() => view.value.trips.filter((x) => x.id !== trip.value?.id));

/** Hours since the phone last heard from the office. */
const stale = computed(() => {
    const at = state.snapshot?.server_time;
    const limit = state.snapshot?.settings?.max_hours_without_sync;
    if (!at || !limit) return false;

    return (Date.now() - new Date(at).getTime()) / 3600000 > limit;
});

// Scope §8.2 Home quick actions: add expense, cash from client, custody ran out, trip details.
const actions = [
    { to: 'trip-expense', icon: 'receipt', label: 'driver.addExpense', colour: 'cu' },
    { to: 'trip-cash', icon: 'cash', label: 'driver.cashFromClient', colour: 'gn' },
    { to: 'trip-custody-request', icon: 'wallet', label: 'driver.custodyRanOut', colour: 'rd' },
    { to: 'trip', icon: 'route', label: 'driver.tripDetails', colour: 'am' },
];
</script>

<template>
    <div class="p-h"><b>{{ t('driver.hello', { name: state.profile.driver.name }) }}</b></div>
    <p class="sm mu" style="margin:-6px 0 0">{{ locale === 'ar' ? state.profile.company.name_ar : state.profile.company.name_en }}</p>

    <div v-if="stale" class="p-off" style="margin:10px 0 0">
        <Icon name="alert" /> <span style="flex:1">{{ t('driver.stale', { at: `${date(state.snapshot.server_time)} ${time(state.snapshot.server_time)}` }) }}</span>
    </div>

    <router-link v-if="toAccept" :to="{ name: 'trips' }" class="p-banner gn" style="margin-top:10px;display:flex;text-decoration:none">
        <Icon name="truck" /> <span style="flex:1">{{ t('driver.tripsToAccept', { n: toAccept }) }}</span>
    </router-link>

    <router-link v-if="toConfirm" :to="{ name: 'alerts' }" class="p-banner" style="margin-top:10px;display:flex;text-decoration:none">
        <Icon name="cash" /> <span style="flex:1">{{ t('driver.cashWaiting', { n: toConfirm }) }}</span>
    </router-link>

    <template v-if="trip">
        <TripCard :trip="trip" />

        <router-link v-if="trip.next" :to="{ name: 'trip', params: { id: trip.id } }" class="bigbtn" style="display:block;text-align:center;text-decoration:none">
            {{ t('driver.nextStep.' + trip.next.replace('.', '_')) }}
        </router-link>

        <div v-if="running" class="p-acts">
            <router-link v-for="a in actions" :key="a.to" :to="{ name: a.to, params: { id: trip.id } }" class="p-act" style="text-decoration:none;color:inherit">
                <span class="ic" :style="`--a:var(--${a.colour});--a-dim:var(--${a.colour}-dim)`"><Icon :name="a.icon" /></span>
                <b style="font-size:12px">{{ t(a.label) }}</b>
            </router-link>
        </div>
    </template>
    <div v-else class="p-card" style="text-align:center;padding:26px 16px">
        <div style="width:52px;height:52px;border-radius:15px;background:var(--cu-dim);color:var(--cu);display:grid;place-items:center;margin:0 auto 10px">
            <Icon name="truck" :size="26" />
        </div>
        <b style="font-size:15px">{{ t('driver.noTrip') }}</b>
        <p class="sm mu" style="margin:6px 0 0">{{ view.ready ? t('driver.noTripSub') : t('driver.needSignal') }}</p>
    </div>

    <div v-if="trip && trip.status === 'delivered'" class="p-card" style="border-color:var(--gn-bd)">
        <b class="sm">{{ t('driver.afterDelivery') }}</b>
        <router-link :to="{ name: 'trip', params: { id: trip.id } }" class="xs cu b" style="display:block;margin-top:4px">{{ t('driver.seeSettlement') }}</router-link>
    </div>

    <div class="p-walls">
        <div style="--w:var(--cu);--w-dim:var(--cu-dim);--w-bd:var(--cu-bd)"><span>{{ t('driver.custody') }}</span><b class="num">{{ money(view.wallets.custody, 2) }}</b></div>
        <div style="--w:var(--gn);--w-dim:var(--gn-dim);--w-bd:var(--gn-bd)"><span>{{ t('driver.collections') }}</span><b class="num">{{ money(view.wallets.collections, 2) }}</b></div>
    </div>

    <template v-if="nextTrips.length">
        <div class="p-h" style="margin-top:14px"><b style="font-size:14px">{{ t('driver.nextTrips') }}</b></div>
        <TripCard v-for="x in nextTrips" :key="x.id" :trip="x" />
    </template>
</template>
