<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Vehicle file ( /office/vehicles/{id} )
  Location: resources/js/Pages/Office/Vehicles/Show.vue
  The demo's vehicle file: figure tiles for this month (trips,
  revenue, direct profit per km — from Step 3; fuel economy vs
  standard arrives with Step 6), the documents panel with expiry
  badges, the vehicle's details and its latest trips.
  Edit and delete need vehicles.edit / vehicles.delete.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import DocBadge from '@/Components/DocBadge.vue';
import TripStatus from '@/Components/TripStatus.vue';
import VehicleForm from '@/Components/VehicleForm.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, num } from '@/Utils/format';

const props = defineProps({ vehicle: Object, figures: Object, fuel: { type: Object, default: null }, trips: Array, options: Object });
const { t } = useI18n();
const { can } = usePermissions();
const editOpen = ref(false);
const confirmDelete = ref(false);
const plateText = `${props.vehicle.plate_number} ${props.vehicle.plate_letters}`;
const remove = () => router.delete(route('office.vehicles.destroy', props.vehicle.id));
</script>

<template>
    <PortalLayout :title="plateText">
        <Link :href="route('office.vehicles.index')" class="back"><AppIcon name="back" :size="15" /> {{ t('veh.back') }}</Link>
        <div class="ph">
            <div>
                <h1 style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><Plate :number="vehicle.plate_number" :letters="vehicle.plate_letters" /> {{ vehicle.type_name }}
                    <span v-if="vehicle.status === 'maintenance'" class="bd rd">{{ t('veh.maintenance') }}</span>
                </h1>
                <p>{{ [vehicle.model, vehicle.year].filter(Boolean).join(' · ') }} · {{ vehicle.ownership === 'own' ? t('veh.companyOwned') : t('veh.hiredFrom', { owner: vehicle.owner_name }) }}</p>
            </div>
            <div class="acts">
                <button v-if="can('vehicles.edit')" class="btn btn-ln" @click="editOpen = true"><AppIcon name="edit" /> {{ t('common.edit') }}</button>
                <button v-if="can('vehicles.delete')" class="btn btn-rd" @click="confirmDelete = true"><AppIcon name="trash" /></button>
            </div>
        </div>

        <div class="g4">
            <Kpi :label="t('veh.monthTrips')" :value="num(figures.month_trips)" :foot="t('veh.monthKm', { n: num(figures.km) })" />
            <Kpi :label="t('veh.revenue')" :value="money(figures.revenue)" unit="EGP" />
            <Kpi :label="t('veh.profitPerKm')" :value="figures.profit_per_km == null ? '—' : num(figures.profit_per_km, 2)" color="var(--bl)"
                 :foot="`${t('veh.directProfit')} ${money(figures.profit)}`" />
            <Kpi :label="t('veh.fuelEconomy')" :value="fuel?.kmpl == null ? '—' : num(fuel.kmpl, 2)" unit="km/L" :color="fuel?.flagged ? 'var(--rd)' : 'var(--gn)'"
                 :foot="fuel?.variance != null ? `${t('veh.vsStandard', { n: (fuel.variance > 0 ? '+' : '') + num(fuel.variance, 1) })}` : vehicle.std_km_per_litre ? `${t('veh.kmpl')} ${num(vehicle.std_km_per_litre, 2)}` : ''" />
        </div>
        <p v-if="fuel" class="xs mu" style="margin:8px 0 0">
            <span v-if="fuel.flagged" class="rd b">{{ t('veh.overdraw') }} · </span>{{ fuel.kmpl == null ? t('veh.fuelNoData') : t('veh.fuelMonth', { n: fuel.fills, l: num(fuel.litres, 1) }) }}
            <Link v-if="can('fuel.view')" :href="route('office.fuel.index', { vehicle: vehicle.id })" style="color:var(--cu)"> {{ t('veh.fuelLog') }}</Link>
        </p>

        <div class="g2 mt" style="margin-top:14px">
            <div v-if="vehicle.ownership === 'own'" class="pn">
                <div class="pn-h"><div><h3><AppIcon name="file" />{{ t('veh.documents') }}</h3><div class="s">{{ t('doc.alertHint') }}</div></div></div>
                <div v-for="[key, extra] in [['licence', vehicle.licence_number], ['insurance', [vehicle.insurance_company, vehicle.insurance_policy_number].filter(Boolean).join(' · ')], ['inspection', '']]" :key="key" class="doc-row">
                    <span class="di"><AppIcon name="file" /></span>
                    <div><b>{{ t('doc.' + key) }}</b><span>{{ vehicle.documents[key].date ? t('doc.expires', { date: date(vehicle.documents[key].date) }) : '—' }}<template v-if="extra"> · <span class="num">{{ extra }}</span></template></span></div>
                    <DocBadge :doc="vehicle.documents[key]" />
                </div>
            </div>
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="truck" />{{ t('veh.details') }}</h3></div></div>
                <div class="kv">
                    <div><span>{{ t('veh.driver') }}</span><b>{{ vehicle.driver || '—' }}</b><div v-if="vehicle.driver_mobile" class="xs mu num">{{ vehicle.driver_mobile }}</div></div>
                    <div><span>{{ t('veh.capacity') }}</span><b class="num">{{ vehicle.capacity_tons ? num(vehicle.capacity_tons, 1) : '—' }}</b></div>
                    <div v-if="vehicle.ownership === 'own'"><span>{{ t('veh.odometerKm') }}</span><b class="num">{{ num(vehicle.odometer_km) }}</b></div>
                    <div v-if="vehicle.ownership === 'own'"><span>{{ t('veh.stdKmpl') }}</span><b class="num">{{ vehicle.std_km_per_litre ? num(vehicle.std_km_per_litre, 2) : '—' }}</b></div>
                    <div v-if="vehicle.ownership === 'hired'"><span>{{ t('veh.ownerName') }}</span><b>{{ vehicle.owner_name }}</b></div>
                    <div v-if="vehicle.ownership === 'hired'"><span>{{ t('veh.ownerPhone') }}</span><b class="num">{{ vehicle.owner_phone || '—' }}</b></div>
                </div>
                <p v-if="vehicle.notes" class="sm" style="margin:12px 0 0;white-space:pre-line">{{ vehicle.notes }}</p>
                <div v-if="vehicle.ownership === 'hired'" class="note am" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('veh.hiredNote') }}</div></div>
            </div>
        </div>

        <div v-if="can('trips.view')" class="pn" style="margin-top:14px">
            <div class="pn-h"><div><h3><AppIcon name="route" />{{ t('veh.latestTrips') }}</h3></div></div>
            <div v-if="!trips.length" class="empty">{{ t('veh.noTrips') }}</div>
            <div v-else class="tw" style="border:0">
                <table>
                    <thead><tr><th>{{ t('trip.cols.trip') }}</th><th>{{ t('trip.cols.route') }}</th><th>{{ t('trip.cols.driver') }}</th><th class="e">{{ t('trip.cols.revenue') }}</th><th class="e">{{ t('trip.cols.profit') }}</th><th class="e">{{ t('veh.profitPerKm') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="tr in trips" :key="tr.id" class="ck" @click="router.visit(route('office.trips.show', tr.id))">
                            <td><b class="num">{{ tr.number }}</b> <TripStatus :status="tr.status" /><div class="xs mu num">{{ date(tr.loading_at) }}</div></td>
                            <td class="sm">{{ tr.route }}<div class="xs mu">{{ tr.customer }}</div></td>
                            <td class="sm">{{ tr.driver || '—' }}</td>
                            <td class="e num">{{ money(tr.revenue) }}</td>
                            <td class="e num" :class="tr.profit >= 0 ? 'gn' : 'rd'">{{ money(tr.profit) }}</td>
                            <td class="e num">{{ tr.per_km == null ? '—' : num(tr.per_km, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <VehicleForm :show="editOpen" :vehicle="vehicle" :drivers="options.drivers" :types="options.types" @close="editOpen = false" />
        <ConfirmDialog :show="confirmDelete" :title="t('common.delete')" :message="t('veh.deleteConfirm', { plate: plateText })" :confirm-label="t('common.delete')" danger
                       @confirm="remove" @cancel="confirmDelete = false" />
    </PortalLayout>
</template>
