<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: Trips screen
  Location: resources/js/driver/screens/Trips.vue

  All of the driver's open trips (Scope §8.2): the one on the road
  first, then the next planned. Pull the refresh button to fetch the
  latest from the office when there is signal.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import Icon from '@/Components/AppIcon.vue';
import { pick, routeLabel } from '../labels';
import { date } from '@/Utils/format';
import TripCard from '../components/TripCard.vue';
import { refreshSnapshot, state, view } from '../store';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();
const busy = ref(false);
const tab = ref('current');

// Scope §8.2: current / upcoming / finished.
const current = computed(() => view.value.trips.filter((x) => x.status !== 'planned'));
const upcoming = computed(() => view.value.trips.filter((x) => x.status === 'planned'));
const finished = computed(() => state.snapshot?.history ?? []);
const list = computed(() => ({ current: current.value, upcoming: upcoming.value, finished: finished.value })[tab.value]);

async function refresh() {
    busy.value = true;
    await refreshSnapshot();
    busy.value = false;
}
</script>

<template>
    <div class="p-h">
        <b>{{ t('driver.trips') }}</b>
        <button type="button" class="ib" :disabled="!state.online || busy" :aria-label="t('driver.refresh')" @click="refresh"><Icon name="sync" /></button>
    </div>

    <div class="p-seg" style="margin:0 0 6px">
        <button v-for="k in ['current', 'upcoming', 'finished']" :key="k" type="button" :class="{ on: tab === k }" @click="tab = k">
            {{ t('driver.tab.' + k) }} <span class="num">{{ { current: current, upcoming: upcoming, finished: finished }[k].length }}</span>
        </button>
    </div>

    <div v-if="!view.ready" class="empty"><p class="sm" style="margin:0">{{ t('driver.needSignal') }}</p></div>
    <div v-else-if="!list.length" class="empty" style="margin-top:10px">
        <Icon name="route" :size="26" />
        <p class="sm" style="margin:8px 0 0">{{ tab === 'finished' ? t('driver.noFinished') : t('driver.noTripSub') }}</p>
    </div>
    <template v-else-if="tab !== 'finished'"><TripCard v-for="trip in list" :key="trip.id" :trip="trip" /></template>
    <template v-else>
        <div v-for="h in list" :key="h.id" class="p-card">
            <div style="display:flex;gap:8px"><b class="num" style="flex:1">{{ h.number }}</b><span class="xs gn b">{{ t('driver.st.settled') }}</span></div>
            <div class="sm">{{ routeLabel(h, isAr) }}</div>
            <div class="xs mu">{{ pick(h.customer, isAr) }} · {{ date(h.delivered_at) }}</div>
        </div>
    </template>
</template>
