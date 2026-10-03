<!--
  El Tara — Spark (the tiny trend line under a KPI tile, as in the demo)
  Location: resources/js/Components/Spark.vue
  <Spark :values="[1,2,3]" color="var(--gn)" />
-->
<script setup>
import { computed } from 'vue';

const props = defineProps({ values: { type: Array, default: () => [] }, color: { type: String, default: 'var(--cu)' }, w: { type: Number, default: 84 }, h: { type: Number, default: 28 } });

const points = computed(() => {
    const v = props.values.map(Number);
    if (v.length < 2) return [];
    const mx = Math.max(...v), mn = Math.min(...v), r = mx - mn || 1;
    return v.map((x, i) => [i * (props.w / (v.length - 1)), props.h - 3 - ((x - mn) / r) * (props.h - 6)]);
});
const line = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' '));
const last = computed(() => points.value[points.value.length - 1]);
</script>

<template>
    <svg v-if="points.length" class="spark" :width="w" :height="h" :viewBox="`0 0 ${w} ${h}`" style="direction:ltr">
        <path :d="`${line} L${w} ${h} L0 ${h}Z`" :fill="color" opacity=".12" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" />
        <circle :cx="last[0]" :cy="last[1]" r="2.8" :fill="color" />
    </svg>
</template>
