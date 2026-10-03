<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — My requests ( /client/requests )
  Location: resources/js/Pages/Client/Requests/Index.vue

  Scope §7 "My requests": status (in review / approved / declined with
  the reason). Once trucks are assigned: plate, driver and status of each truck.
  A request still in review can be withdrawn.
  Server: App\Http\Controllers\Client\RequestController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Plate from '@/Components/Plate.vue';
import TripStatus from '@/Components/TripStatus.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, money, time } from '@/Utils/format';

defineProps({ requests: Object });
const { t } = useI18n();
const colour = { new: 'am', approved: 'cu', assigned: 'gn', declined: 'rd', cancelled: 'pl' };
const withdrawing = ref(null);
const withdraw = () => router.post(route('client.requests.cancel', withdrawing.value.id), {}, { preserveScroll: true, onFinish: () => (withdrawing.value = null) });
</script>

<template>
    <PortalLayout :title="t('nav.clientRequests')">
        <PageHeader :title="t('nav.clientRequests')" :sub="t('cp.reqSub')">
            <Link :href="route('client.requests.create')" class="btn btn-cu"><AppIcon name="plus" /> {{ t('nav.clientNew') }}</Link>
        </PageHeader>

        <div v-if="!requests.data.length" class="empty">{{ t('cp.noRequests') }}</div>

        <div v-for="r in requests.data" :key="r.id" class="req" style="grid-template-columns:minmax(0,1fr)">
            <div>
                <h4>
                    <span class="num">{{ r.number }}</span>
                    <span class="bd" :class="colour[r.status]">{{ t('cp.rStatus.' + r.status) }}</span>
                </h4>
                <div v-for="l in r.lines" :key="l.id" class="sm" style="margin:2px 0"><b class="num">{{ l.trucks }} ×</b> {{ l.route }} <span class="xs mu num">({{ money(l.subtotal) }} EGP)</span></div>
                <div class="meta">
                    <span>{{ t('cp.loadingAt') }}: <b class="num">{{ date(r.loading_at) }} {{ time(r.loading_at) }}</b></span>
                    <span>{{ t('cp.trucksTotal') }}: <b class="num">{{ r.trucks }}</b></span>
                    <span v-if="r.cargo">{{ t('cp.cargo') }}: <b>{{ r.cargo }}</b></span>
                    <span v-if="r.total !== null">{{ t('cp.expectedTotal') }}: <b class="num">{{ money(r.total) }} EGP</b></span>
                    <span>{{ t('cp.sentAt') }}: <b class="num">{{ date(r.created_at) }}</b></span>
                </div>
                <p v-if="r.notes" class="xs mu" style="margin:6px 0 0">{{ r.notes }}</p>
                <div v-if="r.status === 'declined'" class="note rd" style="margin-top:8px"><AppIcon name="x" /><div><b>{{ t('cp.declinedReason') }}</b> {{ r.decline_reason }}</div></div>
                <div v-if="r.status === 'approved'" class="note cu" style="margin-top:8px"><AppIcon name="info" /><div>{{ t('cp.approvedNote') }}</div></div>
                <div v-if="r.trips.length" class="rowlist" style="margin-top:8px">
                    <div v-for="tr in r.trips" :key="tr.id" class="r">
                        <div><Link :href="route('client.shipments.show', tr.id)" class="num b" style="color:var(--cu)">{{ tr.number }}</Link>
                            <div class="xs"><template v-if="tr.vehicle"><Plate :number="tr.vehicle.number" :letters="tr.vehicle.letters" /> · </template>{{ tr.driver ?? t('cp.truckSoon') }}</div></div>
                        <TripStatus :status="tr.status" />
                    </div>
                </div>
                <div v-if="['new', 'approved'].includes(r.status)" style="margin-top:10px"><button class="btn btn-ln btn-sm" @click="withdrawing = r">{{ t('cp.withdraw') }}</button></div>
            </div>
        </div>

        <Pagination :links="requests.links" />

        <ConfirmDialog :show="!!withdrawing" :title="t('cp.withdrawTitle')" :message="t('cp.withdrawText', { number: withdrawing?.number ?? '' })" :confirm-label="t('cp.withdraw')" danger @confirm="withdraw" @cancel="withdrawing = null" />
    </PortalLayout>
</template>
