<!--
  El Tara — ComboChart (12 months: revenue and cost bars, direct and true profit lines)
  Location: resources/js/Components/ComboChart.vue
  The open month is hatched — its true profit is an estimate. Hover shows the month's figures.
-->
<script setup>
import { computed, ref } from 'vue';
import { useI18n } from '@/lang/i18n';
import { compact, money } from '@/Utils/format';

const props = defineProps({ history: { type: Array, required: true }, mode: { type: String, default: 'both' } });
const { t, locale } = useI18n();

const W = 760, H = 270, l = 52, r = 14, tp = 14, b = 30;
const iw = W - l - r, ih = H - tp - b;

const data = computed(() => props.history.map((h) => ({ ...h, tpv: h.tp ?? h.dp })));
const mx = computed(() => {
    const top = Math.max(1, ...data.value.map((d) => Math.max(d.rev, d.direct, d.dp, d.tpv)));
    const step = top > 2_000_000 ? 500_000 : top > 200_000 ? 50_000 : top > 20_000 ? 5_000 : 500;
    return Math.ceil(top / step) * step || step;
});
const y = (v) => tp + ih - (Math.max(0, v) / mx.value) * ih;
const bw = computed(() => iw / data.value.length);
const grid = computed(() => Array.from({ length: 6 }, (_, i) => ({ i, v: (mx.value / 5) * i })));
const bars = computed(() => props.mode !== 'profit');
const lines = computed(() => props.mode !== 'bars');
const w2 = computed(() => Math.min(16, bw.value * 0.3));
const cx = (i) => l + i * bw.value + bw.value / 2;
const path = (k) => data.value.map((p, i) => `${i ? 'L' : 'M'}${cx(i).toFixed(1)} ${y(p[k]).toFixed(1)}`).join(' ');
const monthName = (m) => new Date(Date.UTC(2026, m - 1, 1)).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { timeZone: 'UTC', month: 'short' });

const hover = ref(null);
const tip = computed(() => (hover.value === null ? null : data.value[hover.value]));
const tipStyle = computed(() => ({ left: `${Math.max(14, Math.min(86, ((l + hover.value * bw.value + bw.value / 2) / W) * 100))}%`, top: '38px' }));
</script>

<template>
    <div class="chart-wrap" data-chart="combo" style="direction:ltr">
        <svg :viewBox="`0 0 ${W} ${H}`" @mouseleave="hover = null">
            <defs>
                <pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="6" height="6" fill="var(--cu-dim)" /><line x1="0" y1="0" x2="0" y2="6" stroke="var(--cu)" stroke-width="2" />
                </pattern>
            </defs>
            <g v-for="g in grid" :key="g.i">
                <line :x1="l" :x2="W - r" :y1="y(g.v)" :y2="y(g.v)" stroke="var(--line)" :stroke-dasharray="g.i ? '3 4' : ''" />
                <text :x="l - 8" :y="y(g.v) + 4" text-anchor="end">{{ compact(g.v) }}</text>
            </g>
            <g v-for="(d, i) in data" :key="d.key">
                <template v-if="bars">
                    <rect :x="l + i * bw + bw / 2 - w2 - 1.5" :y="y(d.rev)" :width="w2" :height="ih + tp - y(d.rev)" rx="3" :fill="d.closed ? 'var(--cu)' : 'url(#hatch)'" :stroke="d.closed ? 'none' : 'var(--cu)'" stroke-width="1.2" />
                    <rect :x="l + i * bw + bw / 2 + 1.5" :y="y(d.direct)" :width="w2" :height="ih + tp - y(d.direct)" rx="3" fill="var(--mu-2)" opacity=".75" />
                </template>
                <text :x="cx(i)" :y="H - 10" text-anchor="middle" :style="d.closed ? '' : 'fill:var(--cu);font-weight:800'">{{ monthName(d.month) }}</text>
                <rect :x="l + i * bw" :y="tp" :width="bw" :height="ih" fill="transparent" @mouseenter="hover = i" @click="hover = i" />
            </g>
            <template v-if="lines">
                <path :d="path('dp')" fill="none" stroke="var(--bl)" stroke-width="2.6" stroke-dasharray="6 5" stroke-linejoin="round" />
                <path :d="path('tpv')" fill="none" stroke="var(--gn)" stroke-width="2.6" stroke-linejoin="round" />
                <circle v-for="(p, i) in data" :key="`a${i}`" :cx="cx(i)" :cy="y(p.dp)" :r="i === data.length - 1 ? 5 : 3.2" fill="var(--card)" stroke="var(--bl)" stroke-width="2.2" />
                <circle v-for="(p, i) in data" :key="`b${i}`" :cx="cx(i)" :cy="y(p.tpv)" :r="i === data.length - 1 ? 5 : 3.2" fill="var(--card)" stroke="var(--gn)" stroke-width="2.2" />
            </template>
        </svg>
        <div v-if="tip" class="tt show" :style="{ ...tipStyle, direction: locale === 'ar' ? 'rtl' : 'ltr' }">
            <b>{{ monthName(tip.month) }} {{ tip.key.slice(0, 4) }}</b>
            <div>{{ t('dash.revenue') }}: <b class="num">{{ money(tip.rev) }}</b></div>
            <div>{{ t('dash.directCosts') }}: <b class="num">{{ money(tip.direct) }}</b></div>
            <div>{{ t('dash.directProfit') }}: <b class="num">{{ money(tip.dp) }}</b></div>
            <div>{{ t('dash.trueProfit') }}: <b class="num">{{ tip.tp === null ? '—' : money(tip.tp) }}</b><span v-if="!tip.closed && tip.tp !== null"> ({{ t('dash.estimateShort') }})</span></div>
        </div>
    </div>
</template>
