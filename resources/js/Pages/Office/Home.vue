<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Office start page ( /office )
  Location: resources/js/Pages/Office/Home.vue
  The simple start page (for users without the dashboard permission):
  subscription, the two limits in use, and the documents, money and
  client requests needing attention. (App\Http\Controllers\Office\HomeController)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import UsageMeter from '@/Components/UsageMeter.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, num } from '@/Utils/format';

defineProps({ limits: Object, subscription: Object, attention: Object, money: Object });
const { t } = useI18n();
const { can } = usePermissions();
const auth = usePage().props.auth;
</script>

<template>
    <PortalLayout :title="t('nav.dashboard')">
        <PageHeader :title="t('office.welcome', { name: auth.user.name })" :sub="t('office.welcomeSub')">
            <Link v-if="can('users.view')" :href="route('office.users.index')" class="btn btn-ln"><AppIcon name="key" /> {{ t('office.manageUsers') }}</Link>
        </PageHeader>

        <div class="g3">
            <Kpi :label="t('office.subscription')" :value="subscription.days_left === null ? '—' : num(subscription.days_left)" :unit="t('office.daysLeft')" color="var(--vi)">
                <StatusBadge :status="subscription.status" :read-only="auth.company.read_only" />
                <span>{{ t('office.until') }} <b class="num">{{ date(subscription.ends_at) }}</b></span>
            </Kpi>
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="users" />{{ t('office.officeUsers') }}</h3><div class="s">{{ t('office.limitHint') }}</div></div></div>
                <UsageMeter :used="limits.office_used" :limit="limits.office_limit" />
            </div>
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="phone" />{{ t('office.driverAccounts') }}</h3><div class="s">{{ t('office.limitHint') }}</div></div></div>
                <UsageMeter :used="limits.drivers_used" :limit="limits.drivers_limit" />
            </div>
        </div>

        <div v-if="attention.vehicles || attention.drivers" class="note am" style="margin-top:14px">
            <AppIcon name="alert" />
            <div>
                <b>{{ t('office.attention') }}</b>
                <Link v-if="attention.vehicles" :href="route('office.vehicles.index')" style="color:inherit;text-decoration:underline">{{ t('office.attentionVehicles', { n: attention.vehicles }) }}</Link>
                <template v-if="attention.vehicles && attention.drivers"> · </template>
                <Link v-if="attention.drivers" :href="route('office.drivers.index', { filter: 'licence' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionDrivers', { n: attention.drivers }) }}</Link>
            </div>
        </div>

        <div v-if="money.transfers || money.review || money.settle" class="note cu" style="margin-top:14px">
            <AppIcon name="wallet" />
            <div>
                <b>{{ t('office.money') }}</b>
                <Link v-if="money.transfers" :href="route('office.wallets.index', { tab: 'pending' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionTransfers', { n: money.transfers }) }}</Link>
                <template v-if="money.transfers && (money.review || money.settle)"> · </template>
                <Link v-if="money.review" :href="route('office.wallets.index', { tab: 'review' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionReview', { n: money.review }) }}</Link>
                <template v-if="money.review && money.settle"> · </template>
                <Link v-if="money.settle" :href="route('office.trips.index', { status: 'delivered' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionSettle', { n: money.settle }) }}</Link>
            </div>
        </div>

        <div v-if="money.requests || money.to_assign || money.complaints" class="note cu" style="margin-top:14px">
            <AppIcon name="file" />
            <div>
                <b>{{ t('office.clients') }}</b>
                <Link v-if="money.requests" :href="route('office.client-requests.index')" style="color:inherit;text-decoration:underline">{{ t('office.attentionRequests', { n: money.requests }) }}</Link>
                <template v-if="money.requests && (money.to_assign || money.complaints)"> · </template>
                <Link v-if="money.to_assign" :href="route('office.client-requests.index', { tab: 'approved' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionToAssign', { n: money.to_assign }) }}</Link>
                <template v-if="money.to_assign && money.complaints"> · </template>
                <Link v-if="money.complaints" :href="route('office.client-requests.index', { tab: 'feedback' })" style="color:inherit;text-decoration:underline">{{ t('office.attentionComplaints', { n: money.complaints }) }}</Link>
            </div>
        </div>

    </PortalLayout>
</template>
