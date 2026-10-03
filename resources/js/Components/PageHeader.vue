<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — PageHeader (title, subtitle and buttons of a screen)
  Location: resources/js/Components/PageHeader.vue
  <PageHeader :title="…" :sub="…"> <button class="btn btn-cu">…</button> </PageHeader>
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
defineProps({ title: { type: String, required: true }, sub: { type: String, default: '' } });
</script>

<template>
    <div class="ph">
        <div>
            <h1>{{ title }}</h1>
            <p v-if="sub">{{ sub }}</p>
        </div>
        <div v-if="$slots.default" class="acts"><slot /></div>
    </div>
</template>
