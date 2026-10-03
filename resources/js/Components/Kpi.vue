<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Kpi (a figure tile, as on the demo dashboards)
  Location: resources/js/Components/Kpi.vue
  <Kpi :label="…" :value="12" unit="EGP" :foot="…" color="var(--vi)" />
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    unit: { type: String, default: '' },
    foot: { type: String, default: '' },
    color: { type: String, default: 'var(--cu)' },
});
</script>

<template>
    <div class="kpi" :style="{ '--k': color }">
        <div class="l"><i />{{ label }}</div>
        <div class="v">{{ value }}<small v-if="unit">{{ unit }}</small></div>
        <div class="f"><slot>{{ foot }}</slot></div>
    </div>
</template>
