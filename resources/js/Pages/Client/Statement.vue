<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Statement ( /client/statement )
  Location: resources/js/Pages/Client/Statement.vue

  Scope §7 "Statement & invoices": total of delivered trips, every trip
  with its price, the cash he handed; export to Excel and a printable
  page (Save as PDF). Invoice numbers / invoiced vs not yet invoiced
  come with Step 6.
  Server: App\Http\Controllers\Client\StatementController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import TripStatus from '@/Components/TripStatus.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, money } from '@/Utils/format';

const props = defineProps({ trips: Array, totals: Object, filters: Object });
const { t } = useI18n();
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const query = () => ({ from: from.value || undefined, to: to.value || undefined });
const apply = () => router.get(route('client.statement.index'), query(), { preserveScroll: true });
const link = (name) => route(name, query());
</script>

<template>
    <PortalLayout :title="t('nav.clientStatement')">
        <PageHeader :title="t('nav.clientStatement')" :sub="t('cp.stmtSub')">
            <a :href="link('client.statement.export')" class="btn btn-ln"><AppIcon name="download" /> Excel</a>
            <a :href="link('client.statement.print')" target="_blank" rel="noopener" class="btn btn-ln"><AppIcon name="file" /> {{ t('cp.printPdf') }}</a>
        </PageHeader>

        <div class="g4" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
            <Kpi :label="t('cp.stm.delivered')" :value="money(totals.delivered)" unit="EGP" color="var(--gn)" :foot="t('cp.stm.deliveredSub')" />
            <Kpi :label="t('cp.stm.invoiced')" :value="money(totals.invoiced)" unit="EGP" color="var(--gn)" />
            <Kpi :label="t('cp.stm.notInvoiced')" :value="money(totals.not_invoiced)" unit="EGP" :color="totals.not_invoiced > 0 ? 'var(--am)' : 'var(--gn)'" />
            <Kpi :label="t('cp.stm.running')" :value="money(totals.in_progress)" unit="EGP" color="var(--bl)" />
            <Kpi :label="t('cp.stm.cash')" :value="money(totals.cash)" unit="EGP" color="var(--am)" />
        </div>

        <div v-if="totals.truncated" class="note am" style="margin-top:14px">{{ t('cp.stm.truncated') }}</div>

        <div class="tabs" style="margin-top:14px;gap:8px;align-items:center">
            <label class="xs mu">{{ t('cp.from2') }} <input v-model="from" type="date" dir="ltr" @change="apply"></label>
            <label class="xs mu">{{ t('cp.to2') }} <input v-model="to" type="date" dir="ltr" @change="apply"></label>
        </div>

        <div class="tw">
            <table>
                <thead><tr><th>{{ t('cp.col.trip') }}</th><th>{{ t('cp.col.loading') }}</th><th>{{ t('cp.route') }}</th><th>{{ t('cp.col.status') }}</th><th>{{ t('cp.col.invoice') }}</th><th class="e">{{ t('cp.col.price') }}</th></tr></thead>
                <tbody>
                    <tr v-if="!trips.length"><td colspan="6"><div class="empty" style="border:0">{{ t('cp.noShipments') }}</div></td></tr>
                    <tr v-for="s in trips" :key="s.id" class="ck" @click="router.visit(route('client.shipments.show', s.id))">
                        <td><b class="num">{{ s.number }}</b></td>
                        <td class="xs mu num">{{ date(s.loading_at) }}</td>
                        <td class="sm">{{ s.route }}</td>
                        <td><TripStatus :status="s.status" /></td>
                        <td class="sm num">{{ s.invoice ?? '—' }}</td>
                        <td class="e"><b class="num">{{ money(s.price) }}</b></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PortalLayout>
</template>
