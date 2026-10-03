<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — StatusBadge (company status as a coloured badge)
  Location: resources/js/Components/StatusBadge.vue
  active → green · trial → blue · suspended → grey · read-only → red
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ status: { type: String, required: true }, readOnly: Boolean });
const { t } = useI18n();

const badge = computed(() => {
    if (props.status === 'suspended') return ['pl', t('status.suspended')];
    if (props.readOnly) return ['rd', t('status.readOnly')];
    return props.status === 'trial' ? ['bl', t('status.trial')] : ['gn', t('status.active')];
});
</script>

<template>
    <span class="bd" :class="badge[0]">{{ badge[1] }}</span>
</template>
