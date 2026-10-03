<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — UsageMeter ("5 of 8" with a filling bar)
  Location: resources/js/Components/UsageMeter.vue
  Turns amber from 80% and red at the limit, so a full company is
  easy to spot in a long list.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import { num, percent } from '@/Utils/format';

const props = defineProps({
    used: { type: Number, required: true },
    limit: { type: Number, required: true },
    compact: Boolean,
});

const pct = computed(() => percent(props.used, props.limit));
const color = computed(() => (pct.value >= 100 ? 'var(--rd)' : pct.value >= 80 ? 'var(--am)' : 'var(--cu)'));
</script>

<template>
    <div :style="compact ? 'min-width:90px' : ''">
        <div class="xs" style="display:flex;justify-content:space-between;gap:6px">
            <span class="num"><b>{{ num(used) }}</b> / {{ num(limit) }}</span>
            <span class="num mu">{{ pct }}%</span>
        </div>
        <div class="meter" style="margin-top:4px;height:6px"><i :style="{ width: pct + '%', background: color }" /></div>
    </div>
</template>
