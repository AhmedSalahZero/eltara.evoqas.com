<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: trip row / card
  Location: resources/js/driver/components/TripCard.vue

  One trip as a tappable row: number, route, customer, the five-step
  progress bar and its status. A small clock shows entries about this
  trip that have not uploaded yet.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import Icon from '@/Components/AppIcon.vue';
import { pick, routeLabel, stepIndex, STEPS } from '../labels';
import { useI18n } from '@/lang/i18n';

defineProps({ trip: { type: Object, required: true } });
const { t, isAr } = useI18n();
</script>

<template>
    <router-link :to="{ name: 'trip', params: { id: trip.id } }" class="p-card" style="display:block;color:inherit;text-decoration:none">
        <div style="display:flex;gap:8px;align-items:center">
            <b class="num" style="flex:1">{{ trip.number }}</b>
            <span v-if="trip.waiting" class="xs am" style="display:inline-flex;gap:4px;align-items:center"><Icon name="clock" :size="13" />{{ trip.waiting }}</span>
            <span class="xs b" :class="trip.status === 'on_road' ? 'gn' : 'cu'">{{ t('driver.st.' + trip.status) }}</span>
        </div>
        <div class="sm" style="margin-top:4px">{{ routeLabel(trip, isAr) }}</div>
        <div class="xs mu">{{ pick(trip.customer, isAr) }}<template v-if="trip.truck"> · <span class="num">{{ trip.truck }}</span></template></div>
        <div class="p-steps">
            <i v-for="(s, i) in STEPS" :key="s" :class="{ ok: i < stepIndex(trip.status) || trip.status === 'delivered', now: i === stepIndex(trip.status) && trip.status !== 'delivered' }" />
        </div>
    </router-link>
</template>
