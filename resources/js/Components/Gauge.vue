<!--
  El Tara — Gauge (the half-circle utilisation dial)
  Location: resources/js/Components/Gauge.vue
-->
<script setup>
import { computed } from 'vue';

const props = defineProps({ percent: { type: Number, default: 0 }, label: { type: String, default: '' } });
const end = computed(() => {
    const p = Math.max(0, Math.min(100, props.percent));
    const a = Math.PI * (1 - p / 100);
    return { x: (70 + 58 * Math.cos(a)).toFixed(1), y: (70 - 58 * Math.sin(a)).toFixed(1) };
});
</script>

<template>
    <div class="chart-wrap" style="width:140px;flex-shrink:0">
        <svg viewBox="0 0 140 84">
            <path d="M12 70 A58 58 0 0 1 128 70" fill="none" stroke="var(--hover)" stroke-width="13" stroke-linecap="round" />
            <path v-if="percent > 0" :d="`M12 70 A58 58 0 0 1 ${end.x} ${end.y}`" fill="none" stroke="var(--cu)" stroke-width="13" stroke-linecap="round" />
            <text x="70" y="64" text-anchor="middle" style="font-size:24px;font-weight:800;fill:var(--tx)">{{ Math.round(percent) }}%</text>
            <text x="70" y="80" text-anchor="middle" style="font-size:10px">{{ label }}</text>
        </svg>
    </div>
</template>
