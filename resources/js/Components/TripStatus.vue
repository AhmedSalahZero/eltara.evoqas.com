<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — TripStatus (a trip's status as a coloured badge)
  Location: resources/js/Components/TripStatus.vue
  <TripStatus status="on_road" />
  planned grey · accepted blue · loading amber · on the road teal ·
  delivered violet (waiting for settlement) · settled green · cancelled grey
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { useI18n } from '@/lang/i18n';

defineProps({ status: { type: String, required: true } });
const { t } = useI18n();
const colours = { planned: 'pl', accepted: 'cu', loading: 'am', on_road: 'bl', delivered: 'vi', settled: 'gn', cancelled: 'pl' };
</script>

<template>
    <span class="bd" :class="colours[status] ?? 'pl'">{{ t('trip.status.' + status) }}</span>
</template>
