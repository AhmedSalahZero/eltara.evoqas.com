<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Client Portal home ( /client )
  Location: resources/js/Pages/Client/Home.vue

  Scope §7 "Home": a banner for cash waiting for his confirmation;
  KPIs (active shipments, trips this month, transport cost this month
  at the agreed prices, his average rating); the live shipments board;
  latest requests; recently delivered trips with a rating shortcut.
  Server: App\Http\Controllers\Client\HomeController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Plate from '@/Components/Plate.vue';
import Stars from '@/Components/Stars.vue';
import TripStatus from '@/Components/TripStatus.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, money, time } from '@/Utils/format';

defineProps({ kpis: Object, awaiting: Object, active: Array, delivered: Array, requests: Array });
const { t } = useI18n();
const auth = usePage().props.auth;
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');
const open = (id) => router.visit(route('client.shipments.show', id));
</script>

<template>
    <PortalLayout :title="t('nav.clientHome')">
        <div class="cl-hero">
            <h1>{{ t('cp.welcome', { name: auth.user.name }) }}</h1>
            <p>{{ t('cp.welcomeSub', { company: auth.company.name }) }}</p>
            <div style="margin-top:12px;position:relative"><Link :href="route('client.requests.create')" class="btn btn-cu"><AppIcon name="plus" /> {{ t('nav.clientNew') }}</Link></div>
        </div>

        <div v-if="awaiting.count" class="cash-banner" style="margin-top:14px">
            <AppIcon name="alert" />
            <div><b>{{ t('cp.awaitingTitle', { n: awaiting.count }) }}</b><div class="xs mu">{{ t('cp.awaitingSub', { amount: money(awaiting.total) }) }}</div></div>
            <Link :href="route('client.cash.index', { filter: 'waiting' })" class="btn btn-cu btn-sm">{{ t('cp.reviewNow') }}</Link>
        </div>

        <div class="g4" style="margin-top:14px">
            <Kpi :label="t('cp.kActive')" :value="kpis.active" color="var(--bl)" />
            <Kpi :label="t('cp.kMonth')" :value="kpis.month" color="var(--vi)" />
            <Kpi :label="t('cp.kCost')" :value="money(kpis.cost)" unit="EGP" color="var(--am)" :foot="t('cp.kCostSub')" />
            <Kpi :label="t('cp.kRating')" :value="kpis.rating ?? '—'" color="var(--gn)" :foot="t('cp.kRatingSub')" />
        </div>

        <div class="g-73" style="margin-top:14px;align-items:start">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="truck" />{{ t('cp.board') }}</h3></div><span class="live">{{ t('cp.live') }}</span></div>
                <div v-if="!active.length" class="empty" style="border:0">{{ t('cp.noActive') }}</div>
                <div v-for="s in active" :key="s.id" class="ship-card" @click="open(s.id)">
                    <div style="min-width:0">
                        <b class="num">{{ s.number }}</b> <TripStatus :status="s.status" />
                        <div class="xs mu">{{ s.route }} · {{ when(s.loading_at) }}</div>
                        <div class="xs" style="margin-top:4px"><template v-if="s.vehicle"><Plate :number="s.vehicle.number" :letters="s.vehicle.letters" /> · </template>{{ s.driver ?? t('cp.truckSoon') }}</div>
                        <div class="mini-steps"><i v-for="n in 5" :key="n" :class="{ on: n - 1 < s.step, now: n - 1 === s.step }" /></div>
                    </div>
                    <div class="xs mu" style="text-align:end"><template v-if="s.arrival">{{ t('cp.expected') }}<div class="num"><b>{{ time(s.arrival) }}</b></div></template></div>
                </div>
            </div>

            <div style="display:grid;gap:14px;min-width:0">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="file" />{{ t('cp.latestRequests') }}</h3></div><Link :href="route('client.requests.index')" class="xs" style="color:var(--cu)">{{ t('cp.all') }}</Link></div>
                    <div v-if="!requests.length" class="empty" style="border:0">{{ t('cp.noRequests') }}</div>
                    <div class="rowlist">
                        <div v-for="r in requests" :key="r.id" class="r">
                            <div><b class="num">{{ r.number }}</b><div class="xs mu">{{ r.lines.map((l) => `${l.trucks} × ${l.route}`).join(' + ') }} · {{ date(r.loading_at) }}</div></div>
                            <span class="bd" :class="{ new: 'am', approved: 'cu', assigned: 'gn', declined: 'rd', cancelled: 'pl' }[r.status]">{{ t('cp.rStatus.' + r.status) }}</span>
                        </div>
                    </div>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="check" />{{ t('cp.recent') }}</h3></div></div>
                    <div v-if="!delivered.length" class="empty" style="border:0">{{ t('cp.noDelivered') }}</div>
                    <div class="rowlist">
                        <div v-for="s in delivered" :key="s.id" class="r">
                            <div><Link :href="route('client.shipments.show', s.id)" class="num b" style="color:var(--cu)">{{ s.number }}</Link><div class="xs mu">{{ s.route }} · {{ date(s.delivered_at) }}</div></div>
                            <div style="text-align:end">
                                <Stars v-if="s.stars" :value="s.stars" />
                                <Link v-else :href="route('client.shipments.show', s.id)" class="btn btn-ln btn-sm">{{ t('cp.rateIt') }}</Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
