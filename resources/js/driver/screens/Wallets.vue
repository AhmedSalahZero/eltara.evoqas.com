<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: Wallets screen
  Location: resources/js/driver/screens/Wallets.vue

  Scope §8.2: the driver's wallets — custody (company money for the
  road), collections (client cash he holds), his own pocket (owed back
  to him) and advances — overall and per trip. Figures include what
  is recorded on the phone but not uploaded yet.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import Icon from '@/Components/AppIcon.vue';
import { routeLabel } from '../labels';
import { state, view } from '../store';
import { money } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();

const wallets = [
    { key: 'custody', colour: 'cu', label: 'driver.custody', sub: 'driver.custodySub' },
    { key: 'collections', colour: 'gn', label: 'driver.collections', sub: 'driver.collectionsSub' },
    { key: 'pocket', colour: 'am', label: 'driver.pocket', sub: 'driver.pocketSub' },
    { key: 'advances', colour: 'bl', label: 'driver.advances', sub: 'driver.advancesSub' },
];
</script>

<template>
    <div class="p-h"><b>{{ t('driver.wallets') }}</b></div>

    <div v-if="!view.ready" class="empty"><p class="sm" style="margin:0">{{ t('driver.needSignal') }}</p></div>
    <template v-else>
        <div class="p-walls">
            <div v-for="w in wallets" :key="w.key" :style="`--w:var(--${w.colour});--w-dim:var(--${w.colour}-dim);--w-bd:var(--${w.colour}-bd)`">
                <span>{{ t(w.label) }}</span><b class="num">{{ money(view.wallets[w.key], 2) }}</b>
            </div>
        </div>
        <p class="xs mu" style="margin:8px 2px 0">{{ t('driver.walletsNote') }}</p>

        <div v-if="state.snapshot?.advances?.length" class="p-card">
            <b class="sm">{{ t('driver.myAdvances') }}</b>
            <div v-for="a in state.snapshot.advances" :key="a.id" class="q-item">
                <Icon name="wallet" />
                <span style="flex:1">{{ a.reason || t('driver.advance') }}
                    <span class="xs mu" style="display:block">{{ t('driver.repaid', { amount: money(a.repaid, 2) }) }}<template v-if="a.monthly_instalment"> · {{ t('driver.instalment', { amount: money(a.monthly_instalment, 2) }) }}</template></span>
                </span>
                <b class="num">{{ money(a.remaining, 2) }}</b>
            </div>
        </div>

        <div v-for="trip in view.trips" :key="trip.id" class="p-card">
            <router-link :to="{ name: 'trip', params: { id: trip.id } }" style="color:inherit;text-decoration:none;display:flex;gap:8px;align-items:center">
                <div style="flex:1;min-width:0"><b class="num">{{ trip.number }}</b><div class="xs mu">{{ routeLabel(trip, isAr) }}</div></div>
                <Icon name="arrow" :size="15" />
            </router-link>
            <div class="p-kv" style="grid-template-columns:repeat(3,1fr)">
                <div><span>{{ t('driver.custody') }}</span><b class="num">{{ money(trip.wallets.custody, 2) }}</b></div>
                <div><span>{{ t('driver.collections') }}</span><b class="num">{{ money(trip.wallets.collections, 2) }}</b></div>
                <div><span>{{ t('driver.pocket') }}</span><b class="num">{{ money(trip.wallets.pocket, 2) }}</b></div>
            </div>
        </div>
    </template>
</template>
