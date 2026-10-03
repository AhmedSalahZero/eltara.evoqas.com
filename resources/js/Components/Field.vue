<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Field (a labelled form field with its error)
  Location: resources/js/Components/Field.vue

  <Field :label="t('users.name')" :error="form.errors.name" :hint="…">
      <input v-model="form.name">
  </Field>
  Add class "w" to make a field span both columns of a .fgrid.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
defineProps({
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
});
</script>

<template>
    <label class="fld">
        <span v-if="label || hint" class="fl">{{ label }}<small v-if="hint">{{ hint }}</small></span>
        <slot />
        <span v-if="error" class="err" role="alert">{{ error }}</span>
    </label>
</template>
