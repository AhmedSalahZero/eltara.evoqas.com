<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Vehicles list ( /office/vehicles )
  Location: resources/js/Pages/Office/Vehicles/Index.vue
  The demo's screen (Scope §6.7): plate, type & model, ownership,
  driver, status, odometer, standard km/L, and the licence /
  insurance / inspection badges; an amber alert at the top for
  documents expired or ending within 30 days; filters and search.
  Server: App\Http\Controllers\Office\VehicleController.
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
import VehicleForm from '@/Components/VehicleForm.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, num } from '@/Utils/format';

const props = defineProps({ vehicles: Object, counts: Object, expiring: Array, filters: Object, options: Object });
const { t } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const filter = ref(props.filters.filter ?? '');
const reload = () => router.get(route('office.vehicles.index'), { search: search.value || undefined, filter: filter.value || undefined }, { preserveState: true, replace: true });
watch(search, useDebounceFn(reload, 350));
watch(filter, reload);

const formOpen = ref(false);
</script>

<template>
    <PortalLayout :title="t('veh.title')">
        <PageHeader :title="t('veh.title')" :sub="t('veh.sub')">
            <button v-if="can('vehicles.create')" class="btn btn-cu" @click="formOpen = true"><AppIcon name="plus" /> {{ t('veh.add') }}</button>
        </PageHeader>

        <div v-if="expiring.length" class="note am" style="margin-bottom:14px">
            <AppIcon name="alert" />
            <div>
                <b>{{ t('doc.alert', { n: expiring.length }) }}</b>
                <span v-for="(d, i) in expiring" :key="i"><span class="num">{{ d.who }}</span>: {{ t('doc.' + d.document) }} ({{ d.state === 'expired' ? t('doc.expired') : date(d.date) }}){{ i < expiring.length - 1 ? ' · ' : '' }}</span>
            </div>
        </div>

        <div class="fbar">
            <label class="srch"><AppIcon name="search" /><input v-model="search" :placeholder="t('veh.searchPh')"></label>
            <div class="chips">
                <button v-for="[key, label] in [['', t('common.all')], ['own', t('veh.own')], ['hired', t('veh.hired')], ['maintenance', t('veh.maintenance')]]" :key="key"
                        type="button" class="chip" :class="{ on: filter === key }" @click="filter = key">
                    {{ label }} <span class="c">{{ counts[key || 'all'] }}</span>
                </button>
            </div>
        </div>

        <div class="tw">
            <table>
                <thead>
                    <tr>
                        <th>{{ t('veh.plate') }}</th><th>{{ t('veh.typeModel') }}</th><th>{{ t('veh.ownership') }}</th><th>{{ t('veh.driver') }}</th>
                        <th>{{ t('veh.status') }}</th><th class="e">{{ t('veh.odometer') }}</th><th class="e">{{ t('veh.kmpl') }}</th>
                        <th>{{ t('doc.licence') }}</th><th>{{ t('doc.insurance') }}</th><th>{{ t('doc.inspection') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!vehicles.data.length"><td colspan="10"><div class="empty" style="border:0">{{ filters.search ? t('common.noResults') : t('veh.none') }}</div></td></tr>
                    <tr v-for="v in vehicles.data" :key="v.id" class="ck" @click="router.visit(route('office.vehicles.show', v.id))">
                        <td><Plate :number="v.plate_number" :letters="v.plate_letters" /></td>
                        <td><b style="font-size:12.5px">{{ v.type_name ?? '—' }}</b><div class="xs mu">{{ [v.model, v.year].filter(Boolean).join(' · ') }}</div></td>
                        <td>
                            <span v-if="v.ownership === 'own'" class="bd gn nodot">{{ t('veh.own') }}</span>
                            <template v-else><span class="bd pl nodot">{{ t('veh.hired') }}</span><div class="xs mu">{{ v.owner_name }}</div></template>
                        </td>
                        <td class="sm">{{ v.driver || '—' }}</td>
                        <td><span v-if="v.status === 'maintenance'" class="bd rd">{{ t('veh.maintenance') }}</span><span v-else-if="v.on_trip" class="bd bl">{{ t('veh.onTrip') }} <span class="num">{{ v.on_trip }}</span></span><span v-else class="bd pl">{{ t('veh.available') }}</span></td>
                        <td class="e num">{{ v.ownership === 'own' ? num(v.odometer_km) : '—' }}</td>
                        <td class="e num">{{ v.ownership === 'own' && v.std_km_per_litre ? num(v.std_km_per_litre, 2) : '—' }}</td>
                        <template v-if="v.ownership === 'own'">
                            <td><DocBadge :doc="v.documents.licence" /></td><td><DocBadge :doc="v.documents.insurance" /></td><td><DocBadge :doc="v.documents.inspection" /></td>
                        </template>
                        <template v-else><td>—</td><td>—</td><td>—</td></template>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="vehicles.links" />

        <VehicleForm :show="formOpen" :drivers="options.drivers" :types="options.types" @close="formOpen = false" />
    </PortalLayout>
</template>
