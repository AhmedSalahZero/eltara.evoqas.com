<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — My shipments ( /client/shipments )
  Location: resources/js/Pages/Client/Shipments/Index.vue
  Scope §7 "My shipments": all his trips with filters (all / active /
  delivered): truck, driver, status, price, rating. (The invoice
  number column arrives with Step 6.)
  Server: App\Http\Controllers\Client\ShipmentController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import Plate from '@/Components/Plate.vue';
import Stars from '@/Components/Stars.vue';
import TripStatus from '@/Components/TripStatus.vue';
import { useI18n } from '@/lang/i18n';
import { date, money, time } from '@/Utils/format';

const props = defineProps({ trips: Object, counts: Object, filters: Object });
const { t } = useI18n();
const search = ref(props.filters.search);

const go = (filter = props.filters.filter) => router.get(route('client.shipments.index'), { filter, search: search.value || undefined }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <PortalLayout :title="t('nav.clientShipments')">
        <PageHeader :title="t('nav.clientShipments')" :sub="t('cp.shipSub')" />

        <div class="tabs">
            <button v-for="k in ['all', 'active', 'delivered']" :key="k" class="tab" :class="{ on: filters.filter === k }" @click="go(k)">{{ t('cp.f.' + k) }}<span class="c">{{ counts[k] }}</span></button>
            <input v-model="search" class="srch" style="margin-inline-start:auto;max-width:220px" :placeholder="t('cp.searchTrip')" @keyup.enter="go()">
        </div>

        <div class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('cp.col.trip') }}</th><th>{{ t('cp.col.loading') }}</th><th>{{ t('cp.col.truck') }}</th><th>{{ t('cp.col.status') }}</th><th class="e">{{ t('cp.col.price') }}</th><th>{{ t('cp.col.rating') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-if="!trips.data.length"><td colspan="6"><div class="empty" style="border:0">{{ t('cp.noShipments') }}</div></td></tr>
                    <tr v-for="s in trips.data" :key="s.id" class="ck" @click="router.visit(route('client.shipments.show', s.id))">
                        <td><b class="num">{{ s.number }}</b><div class="xs mu">{{ s.route }}</div><div v-if="s.invoice" class="xs mu num">{{ t('cp.invoiceNo', { n: s.invoice }) }}</div></td>
                        <td class="xs mu num">{{ date(s.loading_at) }} {{ time(s.loading_at) }}</td>
                        <td class="sm"><Plate v-if="s.vehicle" :number="s.vehicle.number" :letters="s.vehicle.letters" /><div class="xs mu">{{ s.driver ?? '—' }}</div></td>
                        <td><TripStatus :status="s.status" /></td>
                        <td class="e"><b class="num">{{ money(s.price) }}</b></td>
                        <td><Stars v-if="s.stars" :value="s.stars" /><span v-else class="mu">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="trips.links" />
    </PortalLayout>
</template>
