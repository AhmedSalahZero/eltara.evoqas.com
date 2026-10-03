<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — DocBadge (a document's expiry at a glance)
  Location: resources/js/Components/DocBadge.vue
  <DocBadge :doc="vehicle.documents.insurance" />
    expired → red "Expired" · within 30 days → amber "12 days" ·
    later   → the date in grey · no date → "—"
  The state comes from the server (App\Support\DocumentExpiry).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { useI18n } from '@/lang/i18n';
import { date } from '@/Utils/format';

defineProps({ doc: { type: Object, default: null } });
const { t } = useI18n();
</script>

<template>
    <span v-if="!doc || doc.state === 'missing'" class="xs mu">—</span>
    <span v-else-if="doc.state === 'expired'" class="bd rd">{{ t('doc.expired') }}</span>
    <span v-else-if="doc.state === 'soon'" class="bd am"><span class="num">{{ t('doc.days', { n: doc.days }) }}</span></span>
    <span v-else class="xs mu num">{{ date(doc.date) }}</span>
</template>
