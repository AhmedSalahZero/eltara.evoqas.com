<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Trips ( /office/trips )
  Location: resources/js/Pages/Office/Trips/Index.vue

  Scope §6.3 "Trips list": search (trip number, driver, customer,
  plate), status filters with counts, loading-date range, the totals
  of everything filtered (trips, km, revenue, direct cost, direct
  profit), export to Excel, and "New trip". Trips on the way are
  listed first, then those waiting for settlement.
  For trips on the way, the last column shows the cash the driver
  holds (custody + collections).
  Server: App\Http\Controllers\Office\TripController@index.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import TripStatus from '@/Components/TripStatus.vue';
import TripForm from '@/Components/TripForm.vue';
import Pagination from '@/Components/Pagination.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num, time } from '@/Utils/format';

const props = defineProps({ trips: Object, totals: Object, counts: Object, filters: Object, options: Object });
const { t } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const query = () => ({ search: search.value || undefined, status: status.value || undefined, from: from.value || undefined, to: to.value || undefined });
const reload = () => router.get(route('office.trips.index'), query(), { preserveState: true, replace: true, preserveScroll: true });
watch(search, useDebounceFn(reload, 350));
watch([status, from, to], reload);

const exportUrl = computed(() => route('office.trips.export', query()));
const formOpen = ref(typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('new'));
const chips = ['', 'running', 'delivered', 'planned', 'settled', 'cancelled'];
</script>

<template>
    <PortalLayout :title="t('trip.title')">
        <PageHeader :title="t('trip.title')" :sub="t('trip.sub')">
            <a :href="exportUrl" class="btn btn-ln"><AppIcon name="download" /> {{ t('trip.export') }}</a>
            <button v-if="can('trips.create')" class="btn btn-cu" @click="formOpen = true"><AppIcon name="plus" /> {{ t('trip.add') }}</button>
        </PageHeader>

        <div class="fbar">
            <label class="srch"><AppIcon name="search" /><input v-model="search" :placeholder="t('trip.searchPh')"></label>
            <label class="xs mu" style="display:flex;gap:6px;align-items:center">{{ t('trip.from') }} <input v-model="from" type="date" class="sel" dir="ltr" style="width:auto"></label>
            <label class="xs mu" style="display:flex;gap:6px;align-items:center">{{ t('trip.to') }} <input v-model="to" type="date" class="sel" dir="ltr" style="width:auto"></label>
        </div>
        <div class="chips" style="margin-bottom:14px">
            <button v-for="key in chips" :key="key" type="button" class="chip" :class="{ on: status === key }" @click="status = key">
                {{ key ? t('trip.filters.' + key) : t('common.all') }} <span class="c">{{ counts[key || 'all'] }}</span>
            </button>
        </div>

        <div class="g4" style="margin-bottom:14px">
            <Kpi :label="t('trip.totTrips')" :value="num(totals.count)" :foot="t('trip.totKm', { n: num(totals.km) })" />
            <Kpi :label="t('trip.cols.revenue')" :value="money(totals.revenue)" unit="EGP" color="var(--bl)" />
            <Kpi :label="t('trip.cols.cost')" :value="money(totals.cost)" unit="EGP" color="var(--am)" />
            <Kpi v-if="can('trips.see_profit')" :label="t('trip.cols.profit')" :value="money(totals.profit)" unit="EGP" :color="totals.profit >= 0 ? 'var(--gn)' : 'var(--rd)'"
                 :foot="totals.revenue > 0 ? t('trip.margin', { n: num(totals.profit / totals.revenue * 100, 1) }) : ''" />
        </div>

        <div class="tw">
            <table>
                <thead>
                    <tr>
                        <th>{{ t('trip.cols.trip') }}</th><th>{{ t('trip.cols.loading') }}</th><th>{{ t('trip.cols.customer') }} · {{ t('trip.cols.route') }}</th>
                        <th>{{ t('trip.cols.truck') }} · {{ t('trip.cols.driver') }}</th>
                        <th class="e">{{ t('trip.cols.revenue') }}</th><th class="e">{{ t('trip.cols.cost') }}</th><th v-if="can('trips.see_profit')" class="e">{{ t('trip.cols.profit') }}</th>
                        <th class="e">{{ t('trip.cols.cash') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!trips.data.length"><td colspan="8"><div class="empty" style="border:0">{{ filters.search || filters.status ? t('common.noResults') : t('trip.none') }}</div></td></tr>
                    <tr v-for="tr in trips.data" :key="tr.id" class="ck" :style="tr.status === 'cancelled' ? 'opacity:.55' : ''" @click="router.visit(route('office.trips.show', tr.id))">
                        <td><b class="num">{{ tr.number }}</b><div><TripStatus :status="tr.status" /></div><div v-if="tr.invoice" class="xs mu num">{{ t('trip.invoiceShort', { n: tr.invoice }) }}</div></td>
                        <td class="num sm">{{ date(tr.loading_at) }}<div class="xs mu">{{ time(tr.loading_at) }}</div></td>
                        <td><b class="sm">{{ tr.customer }}</b><div class="route xs mu"><AppIcon name="route" />{{ tr.route }}</div></td>
                        <td>
                            <Plate v-if="tr.vehicle" :number="tr.vehicle.number" :letters="tr.vehicle.letters" />
                            <span v-if="tr.is_hired" class="bd am nodot" style="margin-inline-start:4px">{{ t('trip.hired') }}</span>
                            <div class="xs mu">{{ tr.driver || '—' }}</div>
                        </td>
                        <td class="e num">{{ money(tr.revenue) }}</td>
                        <td class="e num">{{ money(tr.cost) }}</td>
                        <td v-if="can('trips.see_profit')" class="e"><b class="num" :class="tr.profit >= 0 ? 'gn' : 'rd'">{{ money(tr.profit) }}</b><div v-if="tr.margin != null" class="xs mu num">{{ num(tr.margin, 1) }}%</div></td>
                        <td class="e">
                            <template v-if="tr.cash && ['accepted', 'loading', 'on_road', 'delivered'].includes(tr.status)">
                                <div class="xs num" style="color:var(--bl)">{{ money(tr.cash.custody) }}</div>
                                <div class="xs num" style="color:var(--vi)">{{ money(tr.cash.collections) }}</div>
                            </template>
                            <span v-else class="mu">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="trips.links" />

        <TripForm v-if="can('trips.create')" :show="formOpen" :options="options" @close="formOpen = false" />
    </PortalLayout>
</template>
