<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Companies ( /admin/companies )
  Location: resources/js/Pages/Admin/Companies/Index.vue
  Search by name, filter by status, 25 per page, usage meters,
  "New company" and "Edit limits" (Scope §4).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import CompanyTable from '@/Components/CompanyTable.vue';
import CompanyForm from '@/Components/CompanyForm.vue';
import Pagination from '@/Components/Pagination.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ companies: Object, filters: Object, defaults: Object });
const { t } = useI18n();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

const reload = () => router.get(route('admin.companies.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
watch(search, useDebounceFn(reload, 350));
watch(status, reload);

const formOpen = ref(false);
const editing = ref(null);
const open = (company = null) => { editing.value = company; formOpen.value = true; };
</script>

<template>
    <PortalLayout :title="t('nav.companies')">
        <PageHeader :title="t('nav.companies')" :sub="t('admin.companiesSub')">
            <button class="btn btn-cu" @click="open()"><AppIcon name="plus" /> {{ t('admin.newCompany') }}</button>
        </PageHeader>

        <div class="fbar">
            <label class="srch"><AppIcon name="search" /><input v-model="search" :placeholder="t('admin.searchPh')"></label>
            <div class="chips">
                <button v-for="s in ['', 'active', 'trial', 'suspended']" :key="s" type="button" class="chip" :class="{ on: status === s }" @click="status = s">
                    {{ s ? t('status.' + s) : t('common.all') }}
                </button>
            </div>
            <span class="xs mu num" style="margin-inline-start:auto">{{ companies.total }}</span>
        </div>

        <CompanyTable :companies="companies.data" @edit="open" />
        <Pagination :links="companies.links" />

        <CompanyForm :show="formOpen" :company="editing" :defaults="defaults" @close="formOpen = false" />
    </PortalLayout>
</template>
