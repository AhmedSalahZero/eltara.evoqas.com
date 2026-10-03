<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — My agreed prices ( /client/prices )
  Location: resources/js/Pages/Client/Prices.vue
  Scope §7: his rate card per route, with a "request on this route"
  shortcut. Server: App\Http\Controllers\Client\PriceController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { money, num } from '@/Utils/format';

defineProps({ prices: Array });
const { t } = useI18n();
</script>

<template>
    <PortalLayout :title="t('nav.clientPrices')">
        <PageHeader :title="t('nav.clientPrices')" :sub="t('cp.priceSub')" />
        <div class="tw">
            <table>
                <thead><tr><th>{{ t('cp.route') }}</th><th class="e">{{ t('cp.km') }}</th><th class="e">{{ t('cp.unitPrice') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-if="!prices.length"><td colspan="4"><div class="empty" style="border:0">{{ t('cp.noPrices') }}</div></td></tr>
                    <tr v-for="p in prices" :key="p.route_id">
                        <td><b class="sm">{{ p.name }}</b></td>
                        <td class="e num">{{ num(p.km) }}</td>
                        <td class="e"><b class="num">{{ money(p.price) }}</b> <small class="mu">EGP</small></td>
                        <td class="e"><Link v-if="p.active" :href="route('client.requests.create', { route: p.route_id })" class="btn btn-ln btn-sm"><AppIcon name="plus" /> {{ t('cp.requestOnRoute') }}</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PortalLayout>
</template>
