<!--
  El Tara — Donut (the cost-mix ring)
  Location: resources/js/Components/Donut.vue
  <Donut :parts="[{ value: 10, colour: '#f00' }]" label="1.2M" sub="EGP" />
-->
<script setup>
import { computed } from 'vue';

const props = defineProps({ parts: { type: Array, default: () => [] }, size: { type: Number, default: 150 }, label: { type: String, default: '' }, sub: { type: String, default: '' } });

const r0 = computed(() => props.size / 2 - 10);
const circ = computed(() => 2 * Math.PI * r0.value);
const segments = computed(() => {
    const total = props.parts.reduce((a, p) => a + p.value, 0);
    let offset = 0;
    return total > 0 ? props.parts.map((p) => {
        const len = (p.value / total) * circ.value;
        const dash = Math.max(0.01, len - 1.5);
        const seg = { colour: p.colour, dash, gap: circ.value - dash, offset: -offset };
        offset += len;
        return seg;
    }) : [];
});
</script>

<template>
    <div class="chart-wrap" :style="{ width: size + 'px', flexShrink: 0 }">
        <svg :viewBox="`0 0 ${size} ${size}`" :width="size" :height="size">
            <circle :r="r0" :cx="size / 2" :cy="size / 2" fill="none" stroke="var(--hover)" stroke-width="18" />
            <circle v-for="(s, i) in segments" :key="i" :r="r0" :cx="size / 2" :cy="size / 2" fill="none" :stroke="s.colour" stroke-width="18"
                :stroke-dasharray="`${s.dash} ${s.gap}`" :stroke-dashoffset="s.offset" :transform="`rotate(-90 ${size / 2} ${size / 2})`" />
            <text :x="size / 2" :y="size / 2 - 2" text-anchor="middle" style="font-size:19px;font-weight:800;fill:var(--tx)">{{ label }}</text>
            <text :x="size / 2" :y="size / 2 + 16" text-anchor="middle" style="font-size:10.5px">{{ sub }}</text>
        </svg>
    </div>
</template>
