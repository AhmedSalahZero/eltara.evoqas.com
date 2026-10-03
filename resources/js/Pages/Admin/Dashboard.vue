<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Platform overview ( /admin )
  Location: resources/js/Pages/Admin/Dashboard.vue
  Scope §4.2: active companies, office users and driver accounts
  against their limits, companies at a limit, subscriptions ending,
  and the latest companies. (App\Http\Controllers\Admin\DashboardController)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import CompanyTable from '@/Components/CompanyTable.vue';
import CompanyForm from '@/Components/CompanyForm.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { num } from '@/Utils/format';

defineProps({ stats: Object, companies: Array, defaults: Object });
const { t } = useI18n();

const formOpen = ref(false);
const editing = ref(null);
const open = (company = null) => { editing.value = company; formOpen.value = true; };
</script>

<template>
    <PortalLayout :title="t('nav.overview')">
        <PageHeader :title="t('nav.overview')" :sub="t('admin.overviewSub')">
            <button class="btn btn-cu" @click="open()"><AppIcon name="plus" /> {{ t('admin.newCompany') }}</button>
        </PageHeader>

        <div class="g4">
            <Kpi :label="t('admin.activeCompanies')" :value="num(stats.active)" :foot="t('admin.onTrial', { n: stats.trial })" color="var(--vi)" />
            <Kpi :label="t('admin.officeUsers')" :value="num(stats.office_used)" :foot="t('admin.allowed', { n: num(stats.office_allowed) })" />
            <Kpi :label="t('admin.driverAccounts')" :value="num(stats.drivers_used)" :foot="t('admin.allowed', { n: num(stats.drivers_allowed) })" color="var(--bl)" />
            <Kpi :label="t('admin.atLimit')" :value="num(stats.at_limit)" color="var(--rd)">
                {{ t('admin.upsell') }} · {{ t('admin.endingSoon') }}: <b class="num">{{ stats.ending_soon }}</b>
            </Kpi>
        </div>

        <div class="sec" style="display:flex;justify-content:space-between;align-items:center">
            <span>{{ t('admin.latest') }}</span>
            <Link :href="route('admin.companies.index')" class="btn btn-gh sm">{{ t('admin.seeAll') }} <AppIcon name="arrow" /></Link>
        </div>
        <CompanyTable :companies="companies" @edit="open" />

        <CompanyForm :show="formOpen" :company="editing" :defaults="defaults" @close="formOpen = false" />
    </PortalLayout>
</template>
