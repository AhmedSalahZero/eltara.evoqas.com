<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Stars (1–5 star rating, shown or chosen)
  Location: resources/js/Components/Stars.vue
  <Stars :value="4" />                       → shows ★★★★☆
  <Stars v-model="form.stars" editable />    → the client picks
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
const props = defineProps({ modelValue: { type: Number, default: 0 }, value: { type: Number, default: null }, editable: Boolean });
const emit = defineEmits(['update:modelValue']);
const current = () => props.value ?? props.modelValue ?? 0;
</script>

<template>
    <span v-if="editable" class="stars" role="radiogroup">
        <button v-for="n in 5" :key="n" type="button" :class="{ on: n <= current() }" :aria-label="n" @click="emit('update:modelValue', n)">★</button>
    </span>
    <span v-else class="star-row" :aria-label="current()"><span v-for="n in 5" :key="n" :class="{ on: n <= current() }">★</span></span>
</template>
