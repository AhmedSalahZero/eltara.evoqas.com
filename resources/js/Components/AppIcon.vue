<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — AppIcon
  Location: resources/js/Components/AppIcon.vue
  One line icon from the demo's set:  <AppIcon name="truck" :size="17" />
  Direction icons ("arrow", "back") flip automatically in Arabic.
  Names: resources/js/Components/icons.js
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import icons from './icons.js';

defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 17 },
});
</script>

<template>
    <svg
        :class="{ 'i-dir': name === 'arrow' || name === 'back' }"
        :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
        v-html="icons[name] || ''"
    />
</template>
