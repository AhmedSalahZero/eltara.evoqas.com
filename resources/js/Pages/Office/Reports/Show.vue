<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — One report ( /office/reports/{report} )   Scope §6.14
  Location: resources/js/Pages/Office/Reports/Show.vue
  Filters (period, customer, truck, driver) → the table → Excel / PDF.
  The columns, rows and totals are built by the server
  (App\Services\Reports\ReportService), so the screen, the Excel file
  and the printed page always show the same numbers.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, isoDate, num, today } from '@/Utils/format';

const props = defineProps({ report: Object, total_rows: Number, screen_limit: Number, options: Object });
const { t } = useI18n();

const f = reactive({ ...props.report.filters });
const query = computed(() => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')));
const apply = () => router.get(route('office.reports.show', props.report.key), query.value, { preserveScroll: true, preserveState: true });
const exportUrl = computed(() => route('office.reports.export', { report: props.report.key, ...query.value }));
const printUrl = computed(() => route('office.reports.print', { report: props.report.key, ...query.value }));

// Quick periods.
const quick = (kind) => {
    const t = today();
    const now = isoDate(t.y, t.m, t.d);
    if (kind === 'm') { f.from = isoDate(t.y, t.m, 1); f.to = now; }
    if (kind === 'lm') { f.from = isoDate(t.y, t.m - 1, 1); f.to = isoDate(t.y, t.m, 0); }
    if (kind === 'q') { f.from = isoDate(t.y, t.m - 2, 1); f.to = now; }
    if (kind === 'y') { f.from = isoDate(t.y - 1, t.m + 1, 1); f.to = now; }
    apply();
};

const fmt = (col, v) => {
    if (v === null || v === undefined || v === '') return '—';
    if (col.type === 'money' || col.type === 'num') return num(v);
    if (col.type === 'dec') return num(v, 2);
    if (col.type === 'pct') return `${num(v, 1)}%`;
    if (col.type === 'date') return date(v);
    return v;
};
const numeric = (col) => ['money', 'num', 'dec', 'pct'].includes(col.type);
const negative = (col, v) => ['profit', 'true_profit', 'true_profit_km', 'profit_km', 'direct_profit'].includes(col.key) && Number(v) < 0;
const hasTotals = computed(() => props.report.totals && Object.keys(props.report.totals).length);
</script>

<template>
    <PortalLayout :title="report.title">
        <PageHeader :title="report.title" :sub="t('rep.exportsSub')">
            <Link :href="route('office.reports.index')" class="btn btn-ln"><AppIcon name="arrowback" /> {{ t('rep.back') }}</Link>
            <a :href="exportUrl" class="btn btn-ln"><AppIcon name="download" /> {{ t('rep.excel') }}</a>
            <a :href="printUrl" target="_blank" class="btn btn-cu"><AppIcon name="download" /> {{ t('rep.pdf') }}</a>
        </PageHeader>

        <div class="pn" style="margin-bottom:14px">
            <div class="chips" style="margin-bottom:10px">
                <button class="chip" @click="quick('m')">{{ t('dash.chip_m') }}</button>
                <button class="chip" @click="quick('lm')">{{ t('dash.chip_lm') }}</button>
                <button class="chip" @click="quick('q')">{{ t('dash.chip_q') }}</button>
                <button class="chip" @click="quick('y')">{{ t('dash.chip_y') }}</button>
            </div>
            <div class="fgrid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                <div class="fld"><label class="fl">{{ t('rep.from') }}</label><input v-model="f.from" type="date" class="sel" dir="ltr" @change="apply" /></div>
                <div class="fld"><label class="fl">{{ t('rep.to') }}</label><input v-model="f.to" type="date" class="sel" dir="ltr" @change="apply" /></div>
                <div class="fld"><label class="fl">{{ t('rep.customer') }}</label>
                    <select v-model="f.customer_id" class="sel" @change="apply"><option :value="null">{{ t('common.all') }}</option><option v-for="c in options.customers" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                <div class="fld"><label class="fl">{{ t('rep.vehicle') }}</label>
                    <select v-model="f.vehicle_id" class="sel" @change="apply"><option :value="null">{{ t('common.all') }}</option><option v-for="v in options.vehicles" :key="v.id" :value="v.id">{{ v.name }}</option></select></div>
                <div class="fld"><label class="fl">{{ t('rep.driver') }}</label>
                    <select v-model="f.driver_id" class="sel" @change="apply"><option :value="null">{{ t('common.all') }}</option><option v-for="d in options.drivers" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
            </div>
        </div>

        <div v-if="report.note" class="note cu" style="margin-bottom:14px"><AppIcon name="info" /><div>{{ report.note }}</div></div>

        <div v-if="!report.rows.length" class="empty">{{ t('rep.empty') }}</div>
        <div v-else class="tw">
            <table>
                <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ e: numeric(c) }">{{ c.label }}</th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in report.rows" :key="i">
                        <td v-for="c in report.columns" :key="c.key" :class="[{ e: numeric(c), num: numeric(c) || c.type === 'date' }, negative(c, r[c.key]) ? 'rd' : '']">{{ fmt(c, r[c.key]) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="hasTotals">
                    <tr class="b">
                        <td v-for="(c, i) in report.columns" :key="c.key" :class="{ e: numeric(c), num: numeric(c) }">
                            <template v-if="i === 0 && !(c.key in report.totals)">{{ t('rep.total') }}</template>
                            <template v-else-if="c.key in report.totals">{{ fmt(c, report.totals[c.key]) }}</template>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div v-if="total_rows > screen_limit" class="sm mu" style="margin-top:10px">{{ t('rep.screenLimit', { shown: screen_limit, total: total_rows }) }}</div>
        <div v-if="report.truncated" class="sm mu" style="margin-top:6px">{{ t('rep.truncated') }}</div>
    </PortalLayout>
</template>
