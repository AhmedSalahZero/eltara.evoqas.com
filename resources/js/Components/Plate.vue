<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Plate (an Egyptian number plate, as drawn in the demo)
  Location: resources/js/Components/Plate.vue
  <Plate number="2140" letters="ن ق ل" />   → [EG | 2140 | ن ق ل]
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
defineProps({ number: { type: String, default: '' }, letters: { type: String, default: '' } });
</script>

<template>
    <span class="plate"><span class="pl-c">EG</span><span class="pl-n">{{ number }}</span><span class="pl-a">{{ letters }}</span></span>
</template>
