<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Office dashboard ( /office )   Scope §6.1
  Location: resources/js/Pages/Office/Dashboard.vue
  The same layout, order and wording as the demo's dashboard:
  KPI rows → on the road now → trend + cash outside the safe →
  action centre / cost mix / fleet → pipeline → rankings →
  drivers + trips to look at → budget vs actual + month close.
  Every figure comes from App\Services\Dashboard\DashboardService.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import Plate from '@/Components/Plate.vue';
import Spark from '@/Components/Spark.vue';
import Donut from '@/Components/Donut.vue';
import Gauge from '@/Components/Gauge.vue';
import ComboChart from '@/Components/ComboChart.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { avatarColor, compact, date, money, num, timezone } from '@/Utils/format';

const props = defineProps({ dash: Object });
const { t, locale } = useI18n();
const { can } = usePermissions();
const auth = usePage().props.auth;

const d = computed(() => props.dash);
const k = computed(() => d.value.kpis);
const chartMode = ref('both');

const PERIODS = ['m', 'lm', 'q', 'y'];
const setPeriod = (p) => router.get(route('office.home'), { period: p }, { preserveScroll: true, preserveState: true, only: ['dash'] });

const today = computed(() => new Date(d.value.now).toLocaleDateString(locale.value === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { timeZone: timezone(), weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
const monthWord = (key) => new Date(`${key}-01T12:00:00Z`).toLocaleDateString(locale.value === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { timeZone: 'UTC', month: 'long', year: 'numeric' });
const periodLabel = computed(() => t(`dash.period_${d.value.period}`));

// ── helpers ────────────────────────────────────────────────────
const pct = (a, b) => (b > 0 ? Math.max(0, Math.min(100, (a / b) * 100)) : 0);
const delta = (change, inverse = false) => {
    if (change === null || change === undefined) return null;
    const good = inverse ? change < 0 : change > 0;
    return { text: `${change > 0 ? '▲' : '▼'} ${num(Math.abs(change), 1)}%`, cls: good ? 'up' : 'dn' };
};
const initial = (name) => (name || '?').trim().charAt(0);
const catName = (name) => name || '—';
const FLEET_COLOURS = { road: 'var(--cu)', loading: 'var(--am)', next: 'var(--bl)', idle: 'var(--hover)', maint: 'var(--rd)' };
const fleetCount = (key) => d.value.fleet.counts[key] ?? 0;

const maxCustomer = computed(() => Math.max(1, ...d.value.customers.map((c) => c.rev)));
const maxRoute = computed(() => Math.max(1, ...d.value.routes.map((r) => r.ppk)));
const maxBudget = computed(() => Math.max(1, ...d.value.budget.map((b) => Math.max(b.std, b.actual))));
const costTotal = computed(() => d.value.costMix.total);

const cashSplit = computed(() => d.value.cash);

// ── action centre ──────────────────────────────────────────────
const ACTION_ROUTES = {
    requests: () => route('office.client-requests.index'),
    collections: () => route('office.wallets.index'),
    transfers: () => route('office.wallets.index', { tab: 'pending' }),
    review: () => route('office.wallets.index', { tab: 'review' }),
    unsettled: () => route('office.trips.index', { status: 'delivered' }),
    loss: () => route('office.trips.index'),
    over: () => route('office.trips.index'),
    docs: () => route('office.vehicles.index'),
    fuel: () => route('office.fuel.index', { tab: 'trucks' }),
    invoices: () => route('office.invoices.index'),
};
const actions = computed(() => d.value.actions.filter((a) => can(a.permission)));
const actionSub = (a) => (a.key === 'over' || a.key === 'fuel' ? t(`dash.act.${a.key}Sub`, { p: a.sub.p }) : t(`dash.act.${a.key}Sub`, { n: a.sub.n ?? 0 }));

const goTrip = (id) => router.visit(route('office.trips.show', id));

const vehicleLabel = (v) => (v ? `${v.number} ${v.letters ?? ''}` : '—');
const pipeline = computed(() => {
    const p = d.value.pipeline;
    return [
        ['planned', p.planned, 'var(--mu)'], ['accepted', p.accepted, 'var(--bl)'], ['loading', p.loading, 'var(--am)'], ['on_road', p.on_road, 'var(--cu)'],
        ['delivered', p.delivered, 'var(--vi)'], ['no_invoice', p.no_invoice, 'var(--gn)'], ['invoiced', p.invoiced, 'var(--gn)'],
    ];
});
const rate = computed(() => d.value.close.last?.rate ?? k.value.ga_km);
const exportUrl = computed(() => route('office.dashboard.print', { period: d.value.period }));
</script>

<template>
    <PortalLayout :title="t('nav.dashboard')">
        <PageHeader :title="t('dash.hello', { name: auth.user.name })" :sub="`${today} · ${periodLabel}`">
            <div class="chips">
                <button v-for="p in PERIODS" :key="p" class="chip" :class="{ on: d.period === p }" @click="setPeriod(p)">{{ t(`dash.chip_${p}`) }}</button>
            </div>
            <a :href="exportUrl" target="_blank" class="btn btn-ln"><AppIcon name="download" /> {{ t('dash.export') }}</a>
            <Link v-if="can('trips.create')" :href="route('office.trips.index', { new: 1 })" class="btn btn-cu"><AppIcon name="plus" /> {{ t('dash.newTrip') }}</Link>
        </PageHeader>

        <!-- KPI row 1 -->
        <div class="g5">
            <div class="kpi" style="--k:var(--cu)">
                <div class="l"><i />{{ t('dash.revenue') }}</div>
                <div class="v">{{ compact(k.rev.value) }}<small>{{ t('common.egp') }}</small></div>
                <div class="f"><span v-if="delta(k.rev.change)" class="delta" :class="delta(k.rev.change).cls">{{ delta(k.rev.change).text }}</span> <span v-if="k.compare">{{ t('dash.vsPrevious') }}</span></div>
                <Spark :values="k.rev.spark" color="var(--cu)" />
            </div>
            <div class="kpi" style="--k:var(--mu-2)">
                <div class="l"><i />{{ t('dash.directCostsTrips') }}</div>
                <div class="v">{{ compact(k.direct.value) }}<small>{{ t('common.egp') }}</small></div>
                <div class="f"><span v-if="delta(k.direct.change, true)" class="delta" :class="delta(k.direct.change, true).cls">{{ delta(k.direct.change, true).text }}</span> <span v-if="k.direct.share !== null">{{ num(k.direct.share, 1) }}% {{ t('dash.ofRevenue') }}</span></div>
                <Spark :values="k.direct.spark" color="var(--mu)" />
            </div>
            <div class="kpi" style="--k:var(--bl)">
                <div class="l"><i />{{ t('dash.directProfit') }}</div>
                <div class="v">{{ compact(k.dp.value) }}<small>{{ t('common.egp') }}</small></div>
                <div class="f"><span class="delta" :class="k.dp.value >= 0 ? 'up' : 'dn'">{{ k.dp.margin === null ? '—' : num(k.dp.margin, 1) + '%' }}</span> {{ t('dash.margin') }}</div>
                <Spark :values="k.dp.spark" color="var(--bl)" />
            </div>
            <div class="kpi" style="--k:var(--gn)">
                <div class="l"><i />{{ t('dash.trueProfit') }}</div>
                <div class="v">{{ k.tp.value === null ? '—' : compact(k.tp.value) }}<small v-if="k.tp.value !== null">{{ t('common.egp') }}</small></div>
                <div class="f">
                    <template v-if="k.tp.value === null">{{ t('dash.noGaYet') }}</template>
                    <template v-else>
                        <span class="delta" :class="k.tp.value > 0 ? 'up' : 'dn'">{{ k.tp.margin === null ? '—' : num(k.tp.margin, 1) + '%' }}</span>
                        <span v-if="k.tp.estimate" class="bd am nodot">{{ t('dash.estimateUntilClose') }}</span>
                        <template v-else>{{ t('dash.afterGa') }}</template>
                    </template>
                </div>
                <Spark :values="k.tp.spark" color="var(--gn)" />
            </div>
            <div class="kpi" style="--k:var(--vi)">
                <div class="l"><i />{{ t('dash.kmDriven') }}</div>
                <div class="v">{{ compact(k.km.value) }}<small>{{ t('dash.kmUnit') }}</small></div>
                <div class="f"><span v-if="delta(k.km.change)" class="delta" :class="delta(k.km.change).cls">{{ delta(k.km.change).text }}</span> <span>{{ num(k.km.trips) }} {{ t('dash.tripsWord') }}</span></div>
                <Spark :values="k.km.spark" color="var(--vi)" />
            </div>
        </div>

        <!-- KPI row 2 -->
        <div class="g5 mt">
            <div class="kpi" style="--k:var(--cu)"><div class="l"><i />{{ t('dash.revPerKm') }}</div><div class="v">{{ k.rev_km === null ? '—' : num(k.rev_km, 2) }}<small>{{ t('common.egp') }}</small></div><div class="f">{{ t('dash.acrossAll') }}</div></div>
            <div class="kpi" style="--k:var(--mu-2)"><div class="l"><i />{{ t('dash.costPerKm') }}</div><div class="v">{{ k.cost_km === null ? '—' : num(k.cost_km, 2) }}<small>{{ t('common.egp') }}</small></div><div class="f">{{ t('dash.costPerKmHint') }}</div></div>
            <div class="kpi" style="--k:var(--am)"><div class="l"><i />{{ t('dash.gaPerKm') }}</div><div class="v">{{ k.ga_km === null ? '—' : num(k.ga_km, 2) }}<small>{{ t('common.egp') }}</small></div><div class="f">{{ t('dash.gaPerKmHint') }}</div></div>
            <div class="kpi" style="--k:var(--gn)"><div class="l"><i />{{ t('dash.utilisation') }}</div><div class="v">{{ num(k.util) }}<small>%</small></div><div class="f">{{ t('dash.utilisationHint') }}</div></div>
            <div class="kpi" style="--k:var(--bl)"><div class="l"><i />{{ t('dash.avgFuel') }}</div><div class="v">{{ k.kmpl === null ? '—' : num(k.kmpl, 2) }}<small>{{ t('dash.kmplUnit') }}</small></div><div class="f">{{ k.kmpl_std === null ? '' : t('dash.fuelStandard', { n: num(k.kmpl_std, 1) }) }}</div></div>
        </div>

        <!-- On the road right now -->
        <section class="hero mt">
            <div class="hero-top">
                <h2><AppIcon name="truck" /> {{ t('dash.onRoad') }} <span class="live">{{ t('dash.live') }}</span></h2>
                <div class="hero-sum">
                    <div>{{ t('dash.activeTrips') }}<b>{{ d.road.active }}</b></div>
                    <div>{{ t('dash.revenueEnRoute') }}<b>{{ compact(d.road.revenue) }}</b></div>
                    <div>{{ t('dash.cashEnRoute') }}<b>{{ compact(d.road.cash) }}</b></div>
                    <div>{{ t('dash.planned24') }}<b>{{ d.road.planned24 }}</b></div>
                </div>
            </div>
            <div class="lanes">
                <div v-if="!d.road.lanes.length" class="empty" style="padding:18px">{{ t('dash.noTripsOnRoad') }}</div>
                <div v-for="l in d.road.lanes" :key="l.id" class="lane" style="cursor:pointer" @click="goTrip(l.id)">
                    <div class="who2">
                        <span class="av" :style="{ background: avatarColor(l.driver || '?'), width: '34px', height: '34px', borderRadius: '10px' }">{{ initial(l.driver) }}</span>
                        <div style="min-width:0"><b>{{ l.driver || t('dash.noDriver') }}</b><div class="m"><Plate v-if="l.vehicle" :number="l.vehicle.number" :letters="l.vehicle.letters" /> <span v-if="l.hired" class="bd pl nodot">{{ t('dash.hired') }}</span></div></div>
                    </div>
                    <div class="road">
                        <div class="done" :style="{ width: l.progress + '%' }"></div>
                        <span class="end a">{{ l.from }}</span><span class="end z">{{ l.to }}</span>
                        <div class="trk" :class="l.status === 'loading' ? 'load' : l.warn ? 'warn' : ''" :style="{ insetInlineStart: l.progress + '%' }"><AppIcon name="truck" /></div>
                    </div>
                    <div>
                        <div class="cash">
                            <span v-if="l.hired" style="background:var(--hover);color:var(--mu)">{{ t('dash.noCustody') }}</span>
                            <span v-else class="c1" :title="t('dash.custodyBalance')">{{ t('dash.custodyShort') }} {{ money(l.custody) }}</span>
                            <span v-if="l.collections" class="c2" :title="t('dash.collectionsHeld')">{{ t('dash.collShort') }} {{ money(l.collections) }}</span>
                        </div>
                        <div class="stt">{{ l.status === 'loading' ? t('dash.loadingNow') : t('dash.arrivesIn', { n: l.eta_hours ?? '—' }) }} · {{ l.customer }}</div>
                    </div>
                </div>
            </div>
            <div class="hero-legend">
                <span><i style="background:var(--cu)"></i>{{ t('dash.legendOk') }}</span>
                <span><i style="background:var(--rd)"></i>{{ t('dash.legendWarn') }}</span>
                <span><i style="background:var(--am)"></i>{{ t('dash.legendLoading') }}</span>
                <span style="margin-inline-start:auto"><AppIcon name="pin" /> {{ t('dash.noLiveTracking') }}</span>
            </div>
        </section>

        <!-- Trend + cash outside the safe -->
        <div class="g-73 mt">
            <div class="pn">
                <div class="pn-h">
                    <div><h3><AppIcon name="chart" />{{ t('dash.trendTitle') }}</h3><div class="s">{{ t('dash.trendSub') }}</div></div>
                    <div class="seg">
                        <button :aria-pressed="chartMode === 'both'" @click="chartMode = 'both'">{{ t('dash.modeAll') }}</button>
                        <button :aria-pressed="chartMode === 'bars'" @click="chartMode = 'bars'">{{ t('dash.modeBars') }}</button>
                        <button :aria-pressed="chartMode === 'profit'" @click="chartMode = 'profit'">{{ t('dash.modeProfit') }}</button>
                    </div>
                </div>
                <ComboChart :history="d.history" :mode="chartMode" />
                <div class="legend">
                    <span><i style="background:var(--cu)"></i>{{ t('dash.revenue') }}</span>
                    <span><i style="background:var(--mu-2)"></i>{{ t('dash.directCosts') }}</span>
                    <span><i style="background:var(--bl)"></i>{{ t('dash.directProfit') }}</span>
                    <span><i style="background:var(--gn)"></i>{{ t('dash.trueProfitLegend') }}</span>
                </div>
            </div>
            <div class="pn">
                <div class="pn-h">
                    <div><h3><AppIcon name="wallet" />{{ t('dash.cashTitle') }}</h3><div class="s">{{ t('dash.cashSub') }}</div></div>
                    <Link v-if="can('wallet_transfers.view')" :href="route('office.wallets.index')" class="btn btn-ln sm">{{ t('dash.settle') }}</Link>
                </div>
                <div style="font-size:32px;font-weight:800;font-family:var(--f-en);line-height:1">{{ money(cashSplit.total) }} <small style="font-size:13px;color:var(--mu);font-family:var(--f-ar)">{{ t('common.egp') }}</small></div>
                <div class="stack">
                    <i :style="{ width: pct(cashSplit.custody, cashSplit.total) + '%', background: 'var(--bl)' }"></i>
                    <i :style="{ width: pct(cashSplit.collections, cashSplit.total) + '%', background: 'var(--vi)' }"></i>
                    <i :style="{ width: pct(cashSplit.advances, cashSplit.total) + '%', background: 'var(--am)' }"></i>
                </div>
                <div class="expo">
                    <div style="--x:var(--bl);--x-dim:var(--bl-dim);--x-bd:var(--bl-bd)"><span>{{ t('dash.tripCustody') }}</span><b>{{ money(cashSplit.custody) }}</b><small>{{ t('dash.forRoadExpenses') }}</small></div>
                    <div style="--x:var(--vi);--x-dim:var(--vi-dim);--x-bd:var(--vi-bd)"><span>{{ t('dash.clientCollections') }}</span><b>{{ money(cashSplit.collections) }}</b><small>{{ t('dash.mustBeHandedIn') }}</small></div>
                    <div style="--x:var(--am);--x-dim:var(--am-dim);--x-bd:var(--am-bd)"><span>{{ t('dash.personalAdvances') }}</span><b>{{ money(cashSplit.advances) }}</b><small>{{ t('dash.deductedFromSalary') }}</small></div>
                </div>
                <div class="rowlist" style="margin-top:10px">
                    <div v-for="r in cashSplit.drivers" :key="r.id" class="r">
                        <div class="nc"><span class="av" :style="{ background: avatarColor(r.name), width: '26px', height: '26px' }">{{ initial(r.name) }}</span><div><b>{{ r.name }}</b><div class="m xs mu">{{ r.trips ? t('dash.openTrips', { n: r.trips }) : t('dash.noOpenTrips') }}</div></div></div>
                        <b class="num">{{ money(r.amount) }}</b>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action centre / cost mix / fleet -->
        <div class="g3 mt">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="bell" />{{ t('dash.actionCentre') }}</h3><div class="s">{{ t('dash.actionSub') }}</div></div></div>
                <Link v-for="a in actions" :key="a.key" :href="ACTION_ROUTES[a.key]()" class="alert" :style="`--a:var(--${a.colour});--a-dim:var(--${a.colour}-dim);--a-bd:var(--${a.colour}-bd)`">
                    <span class="ai"><AppIcon :name="a.icon" /></span>
                    <div><b>{{ t(`dash.act.${a.key}`) }}</b><p>{{ actionSub(a) }}</p></div>
                    <span class="n">{{ a.count }}</span>
                </Link>
            </div>

            <div style="display:grid;gap:14px;align-content:start">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="pie" />{{ t('dash.costMixTitle') }}</h3><div class="s">{{ periodLabel }}</div></div></div>
                    <div v-if="!d.costMix.parts.length" class="empty">{{ t('dash.noData') }}</div>
                    <div v-else class="donut-wrap">
                        <Donut :parts="d.costMix.parts" :size="150" :label="compact(costTotal)" :sub="t('common.egp')" />
                        <div class="lg2">
                            <div v-for="p in d.costMix.parts.slice(0, 7)" :key="p.code || p.name">
                                <i :style="{ background: p.colour }"></i><span>{{ catName(p.name) }}</span>
                                <span class="p">{{ num(pct(p.value, costTotal), 1) }}%</span><span class="a">{{ compact(p.value) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="truck" />{{ t('dash.fleetTitle') }}</h3><div class="s">{{ d.fleet.total }} {{ t('dash.vehiclesWord') }} · {{ d.fleet.hired }} {{ t('dash.hired') }}</div></div></div>
                <div class="gauge">
                    <Gauge :percent="d.fleet.util" :label="t('dash.monthUtil')" />
                    <div class="rowlist" style="flex:1">
                        <div v-for="s in ['road', 'loading', 'next', 'idle', 'maint']" :key="s" class="r" style="padding:5px 0">
                            <span class="sm"><i :style="{ display: 'inline-block', width: '9px', height: '9px', borderRadius: '3px', background: FLEET_COLOURS[s], marginInlineEnd: '7px', border: s === 'idle' ? '1px solid var(--mu-2)' : 'none' }"></i>{{ t(`dash.fleet_${s}`) }}</span>
                            <b class="num">{{ fleetCount(s) }}</b>
                        </div>
                    </div>
                </div>
                <div class="fleet-grid">
                    <i v-for="v in d.fleet.grid" :key="v.id" :title="v.plate" :style="{ background: FLEET_COLOURS[v.state], border: v.state === 'idle' ? '1px solid var(--mu-2)' : 'none' }"></i>
                </div>
            </div>
        </div>

        <!-- Pipeline -->
        <div class="pn mt">
            <div class="pn-h"><div><h3><AppIcon name="route" />{{ t('dash.pipelineTitle') }}</h3><div class="s">{{ t('dash.pipelineSub') }}</div></div></div>
            <div class="pipe">
                <div v-for="[key, n, colour] in pipeline" :key="key" :style="{ borderTop: `3px solid ${colour}` }"><b :style="{ color: colour }">{{ n }}</b><span>{{ t(`dash.pipe_${key}`) }}</span></div>
            </div>
        </div>

        <!-- Customers / routes / vehicles -->
        <div class="g3 mt">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="building" />{{ t('dash.customersTitle') }}</h3><div class="s">{{ t('dash.customersSub') }}</div></div></div>
                <div v-if="!d.customers.length" class="empty">{{ t('dash.noData') }}</div>
                <div v-else class="hb">
                    <div v-for="c in d.customers" :key="c.name" class="r">
                        <span>{{ c.name }}</span>
                        <div class="trk2"><i :style="{ width: pct(c.profit, maxCustomer) + '%', background: 'var(--gn)' }"></i><i :style="{ width: pct(c.cost, maxCustomer) + '%', background: 'var(--mu-2)', opacity: .5 }"></i></div>
                        <span class="v">{{ compact(c.profit) }}</span>
                    </div>
                </div>
                <div class="legend"><span><i style="background:var(--gn)"></i>{{ t('dash.profit') }}</span><span><i style="background:var(--mu-2);opacity:.5"></i>{{ t('dash.cost') }}</span><span>{{ t('dash.fullBarRevenue') }}</span></div>
            </div>

            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="route" />{{ t('dash.routesTitle') }}</h3><div class="s">{{ t('dash.routesSub') }}</div></div></div>
                <div v-if="!d.routes.length" class="empty">{{ t('dash.noData') }}</div>
                <div v-for="r in d.routes" :key="r.name" class="rank">
                    <span class="k">{{ r.trips }}</span>
                    <div style="min-width:0"><b class="route" style="font-size:12.5px">{{ r.name }}</b><div class="bar-in" style="margin-top:4px"><i :style="{ width: pct(r.ppk, maxRoute) + '%' }"></i></div></div>
                    <div class="v">{{ num(r.ppk, 1) }}<small :class="r.var > 3 ? 'rd' : 'gn'">{{ r.var > 0 ? '+' : '' }}{{ num(r.var, 1) }}%</small></div>
                </div>
            </div>

            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="truck" />{{ t('dash.vehiclesTitle') }}</h3><div class="s">{{ d.vehicles.known ? t('dash.vehiclesSub') : t('dash.vehiclesSubNoGa') }}</div></div></div>
                <div v-if="!d.vehicles.best.length" class="empty">{{ t('dash.noData') }}</div>
                <template v-else>
                    <div class="xs mu b" style="margin-bottom:2px">{{ t('dash.best') }}</div>
                    <div v-for="(g, i) in d.vehicles.best" :key="g.id" class="rank" :class="{ t1: i === 0 }">
                        <span class="k">{{ i + 1 }}</span>
                        <div><b><Plate :number="g.number" :letters="g.letters" /></b><div class="m">{{ g.driver || '—' }} · {{ g.trips }} {{ t('dash.tripsWord') }}</div></div>
                        <div class="v" :class="{ rd: g.tppk < 2 }">{{ num(g.tppk, 2) }}<small>{{ t('dash.trueProfitPerKm') }}</small></div>
                    </div>
                    <template v-if="d.vehicles.worst.length">
                        <div class="xs mu b" style="margin:10px 0 2px">{{ t('dash.weakest') }}</div>
                        <div v-for="g in d.vehicles.worst" :key="g.id" class="rank">
                            <span class="k">!</span>
                            <div><b><Plate :number="g.number" :letters="g.letters" /></b><div class="m">{{ g.driver || '—' }} · {{ g.trips }} {{ t('dash.tripsWord') }}</div></div>
                            <div class="v" :class="{ rd: g.tppk < 2 }">{{ num(g.tppk, 2) }}<small>{{ t('dash.trueProfitPerKm') }}</small></div>
                        </div>
                    </template>
                </template>
            </div>
        </div>

        <!-- Drivers + trips that need a look -->
        <div class="g2 mt">
            <div class="pn">
                <div class="pn-h">
                    <div><h3><AppIcon name="idcard" />{{ t('dash.driversTitle') }}</h3><div class="s">{{ t('dash.driversSub') }}</div></div>
                    <Link v-if="can('drivers.view')" :href="route('office.drivers.index')" class="btn btn-ln sm">{{ t('dash.all') }}</Link>
                </div>
                <div v-if="!d.drivers.length" class="empty">{{ t('dash.noData') }}</div>
                <div v-else class="tw flat">
                    <table>
                        <thead><tr><th>{{ t('dash.driver') }}</th><th class="e">{{ t('dash.trips') }}</th><th class="e">{{ t('dash.kmUnit') }}</th><th class="e">{{ t('dash.directProfit') }}</th><th class="e">{{ t('dash.budgetVar') }}</th><th class="e">{{ t('dash.holdingNow') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="g in d.drivers" :key="g.id" class="ck" @click="can('drivers.view') && router.visit(route('office.drivers.show', g.id))">
                                <td><div class="nc"><span class="av" :style="{ background: avatarColor(g.name || '?'), width: '26px', height: '26px' }">{{ initial(g.name) }}</span><b>{{ g.name }}</b></div></td>
                                <td class="e num">{{ g.trips }}</td><td class="e num">{{ num(g.km) }}</td><td class="e num">{{ money(g.profit) }}</td>
                                <td class="e"><span class="num b" :class="g.var > 3 ? 'rd' : g.var < -1 ? 'gn' : ''">{{ g.var > 0 ? '+' : '' }}{{ num(g.var, 1) }}%</span></td>
                                <td class="e num">{{ g.holding ? money(g.holding) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pn">
                <div class="pn-h">
                    <div><h3><AppIcon name="alert" />{{ t('dash.attentionTitle') }}</h3><div class="s">{{ t('dash.attentionSub') }}</div></div>
                    <Link v-if="can('trips.view')" :href="route('office.trips.index')" class="btn btn-ln sm">{{ t('dash.tripsBtn') }}</Link>
                </div>
                <div v-if="!d.attention.length" class="empty">{{ t('dash.noneNeedLook') }}</div>
                <div v-else class="tw flat">
                    <table>
                        <thead><tr><th>{{ t('dash.trip') }}</th><th>{{ t('dash.route') }}</th><th class="e">{{ t('dash.profit') }}</th><th>{{ t('dash.reason') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="x in d.attention" :key="x.id" class="ck" @click="goTrip(x.id)">
                                <td><b class="num">{{ x.number }}</b><div class="xs mu">{{ x.driver || '—' }}</div></td>
                                <td class="sm">{{ x.route }}</td>
                                <td class="e"><b class="num" :class="{ rd: x.profit < 0 }">{{ money(x.profit) }}</b></td>
                                <td><span v-if="x.kind === 'loss'" class="bd rd">{{ t('dash.pricedBelowCost') }}</span><span v-else class="bd cu">{{ t('dash.over') }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Budget vs actual + month close -->
        <div class="g2 mt">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="scale" />{{ t('dash.budgetTitle') }}</h3><div class="s">{{ t('dash.budgetSub') }}</div></div></div>
                <div v-if="!d.budget.length" class="empty">{{ t('dash.noData') }}</div>
                <div v-else class="hb">
                    <div v-for="b in d.budget" :key="b.name" class="r">
                        <span>{{ b.name }}</span>
                        <div style="display:grid;gap:3px">
                            <div class="trk2" style="height:7px"><i :style="{ width: pct(b.std, maxBudget) + '%', background: 'var(--mu-2)', opacity: .55 }"></i></div>
                            <div class="trk2" style="height:7px"><i :style="{ width: pct(b.actual, maxBudget) + '%', background: b.var !== null && b.var > 3 ? 'var(--rd)' : 'var(--cu)' }"></i></div>
                        </div>
                        <span class="v" :class="b.var === null ? 'mu' : b.var > 3 ? 'rd' : 'gn'">{{ b.var === null ? t('dash.unplanned') : (b.var > 0 ? '+' : '') + num(b.var, 1) + '%' }}</span>
                    </div>
                </div>
                <div class="legend"><span><i style="background:var(--mu-2);opacity:.55"></i>{{ t('dash.standard') }}</span><span><i style="background:var(--cu)"></i>{{ t('dash.actual') }}</span></div>
            </div>

            <div class="pn">
                <div class="pn-h">
                    <div><h3><AppIcon name="lock" />{{ t('dash.closeTitle') }}</h3><div class="s">{{ t('dash.closeSub') }}</div></div>
                    <Link v-if="can('month_close.view')" :href="route('office.close.index')" class="btn btn-ln sm">{{ t('dash.open') }}</Link>
                </div>
                <div class="rowlist">
                    <div v-for="m in d.close.months" :key="m.key" class="r">
                        <div>
                            <b>{{ monthWord(m.key) }}</b>
                            <div class="xs mu">{{ m.closed ? t('dash.closedBy', { date: date(m.closed_at), name: m.closed_by || '—' }) : t('dash.gaLines', { n: m.lines, total: d.close.standard_lines }) }}</div>
                        </div>
                        <span class="bd" :class="m.closed ? 'gn' : 'am'">{{ m.closed ? t('dash.closed') : t('dash.openMonth') }}</span>
                    </div>
                </div>
                <div v-if="d.close.last" class="alloc-flow" style="margin-top:10px">
                    <div><span>{{ t('dash.gaOf', { month: monthWord(d.close.last.month) }) }}</span><b>{{ compact(d.close.last.ga) }}</b></div>
                    <div><span class="op">÷</span><span>{{ t('dash.ownKm') }}</span><b>{{ compact(d.close.last.km) }}</b></div>
                    <div><span class="op">=</span><span>{{ t('dash.perKm') }}</span><b class="cu">{{ num(d.close.last.rate, 2) }}</b></div>
                </div>
                <div v-if="d.close.last" class="sm mu" style="margin-top:10px">{{ t('dash.trueProfitOf', { month: monthWord(d.close.last.month) }) }}: <b class="num gn">{{ money(d.close.last.tp) }}</b> ({{ d.close.last.revenue > 0 ? num((d.close.last.tp / d.close.last.revenue) * 100, 1) : '0.0' }}%)</div>
                <div v-else class="sm mu" style="margin-top:10px">{{ t('dash.noClosedYet') }}</div>
            </div>
        </div>
    </PortalLayout>
</template>
