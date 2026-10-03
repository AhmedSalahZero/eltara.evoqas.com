<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Drivers list ( /office/drivers )
  Location: resources/js/Pages/Office/Drivers/Index.vue
  The demo's screen (Scope §6.8): driver and mobile, vehicle, pay
  basis, driving-licence badge, and the Driver App status (last
  sync). Trip figures and wallet balances join in Step 3. The meter
  shows the driver-accounts limit set by El Tara.
  Server: App\Http\Controllers\Office\DriverController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import Plate from '@/Components/Plate.vue';
import DocBadge from '@/Components/DocBadge.vue';
import UsageMeter from '@/Components/UsageMeter.vue';
import DriverForm from '@/Components/DriverForm.vue';
import PinNotice from '@/Components/PinNotice.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { ago, avatarColor, money } from '@/Utils/format';

const props = defineProps({ drivers: Object, counts: Object, limits: Object, filters: Object, options: Object });
const { t, locale } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const filter = ref(props.filters.filter ?? '');
const reload = () => router.get(route('office.drivers.index'), { search: search.value || undefined, filter: filter.value || undefined }, { preserveState: true, replace: true });
watch(search, useDebounceFn(reload, 350));
watch(filter, reload);

const formOpen = ref(false);
const full = () => props.limits.used >= props.limits.limit;
</script>

<template>
    <PortalLayout :title="t('drv.title')">
        <PageHeader :title="t('drv.title')" :sub="t('drv.sub')">
            <div style="min-width:180px"><div class="xs mu" style="margin-bottom:3px">{{ t('drv.limit') }}</div><UsageMeter :used="limits.used" :limit="limits.limit" /></div>
            <button v-if="can('drivers.create')" class="btn btn-cu" :disabled="full()" :title="full() ? t('users.limitReached') : ''" @click="formOpen = true">
                <AppIcon name="plus" /> {{ t('drv.add') }}
            </button>
        </PageHeader>

        <div class="fbar">
            <label class="srch"><AppIcon name="search" /><input v-model="search" :placeholder="t('drv.searchPh')"></label>
            <div class="chips">
                <button v-for="[key, label] in [['', t('common.all')], ['licence', t('drv.licenceSoon')], ['suspended', t('drv.suspended')]]" :key="key"
                        type="button" class="chip" :class="{ on: filter === key }" @click="filter = key">
                    {{ label }} <span class="c">{{ counts[key || 'all'] }}</span>
                </button>
            </div>
        </div>

        <div class="tw">
            <table>
                <thead>
                    <tr><th>{{ t('drv.driver') }}</th><th>{{ t('drv.vehicle') }}</th><th>{{ t('drv.payBasis') }}</th><th class="e">{{ t('drv.monthTrips') }}</th><th class="e">{{ t('drv.held') }}</th><th>{{ t('drv.licence') }}</th><th>{{ t('drv.app') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-if="!drivers.data.length"><td colspan="7"><div class="empty" style="border:0">{{ filters.search ? t('common.noResults') : t('drv.none') }}</div></td></tr>
                    <tr v-for="d in drivers.data" :key="d.id" class="ck" @click="router.visit(route('office.drivers.show', d.id))">
                        <td>
                            <div class="nc">
                                <span class="av" :style="{ background: avatarColor(d.name), width: '30px', height: '30px' }">{{ d.initials }}</span>
                                <div><b>{{ d.name }} <span v-if="!d.is_active" class="bd pl nodot">{{ t('drv.suspended') }}</span></b><div class="m num">{{ d.mobile }}</div></div>
                            </div>
                        </td>
                        <td><Plate v-if="d.vehicle" :number="d.vehicle.number" :letters="d.vehicle.letters" /><span v-else class="mu">—</span></td>
                        <td class="sm">{{ t('drv.pay.' + d.pay_basis) }}</td>
                        <td class="e num">{{ d.month_trips }}</td>
                        <td class="e num">{{ d.held ? money(d.held) : '—' }}</td>
                        <td><DocBadge :doc="d.licence" /></td>
                        <td class="xs mu">{{ d.last_sync_at ? t('drv.lastSync', { when: ago(d.last_sync_at, locale) }) : t('drv.neverSynced') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="drivers.links" />

        <DriverForm :show="formOpen" :vehicles="options.vehicles" @close="formOpen = false" />
        <PinNotice />
    </PortalLayout>
</template>
