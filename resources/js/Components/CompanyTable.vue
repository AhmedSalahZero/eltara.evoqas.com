<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — CompanyTable (Super Admin companies list)
  Location: resources/js/Components/CompanyTable.vue
  The companies with status, admin (and whether they activated),
  office-user and driver meters, and subscription end. Used on the
  platform overview and the companies screen. Emits "edit".
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { router } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';
import StatusBadge from './StatusBadge.vue';
import UsageMeter from './UsageMeter.vue';
import { useI18n } from '@/lang/i18n';
import { avatarColor, date } from '@/Utils/format';

defineProps({ companies: { type: Array, required: true } });
const emit = defineEmits(['edit']);
const { t } = useI18n();

const resend = (c) => router.post(route('admin.companies.activation', c.id), {}, { preserveScroll: true });
</script>

<template>
    <div class="tw">
        <table>
            <thead>
                <tr>
                    <th>{{ t('admin.company') }}</th><th>{{ t('admin.status') }}</th><th>{{ t('admin.admin') }}</th>
                    <th>{{ t('admin.officeUsers') }}</th><th>{{ t('admin.driverAccounts') }}</th><th>{{ t('admin.subscription') }}</th><th />
                </tr>
            </thead>
            <tbody>
                <tr v-if="!companies.length"><td colspan="7"><div class="empty" style="border:0">{{ t('admin.noCompanies') }}</div></td></tr>
                <tr v-for="c in companies" :key="c.id">
                    <td>
                        <div class="nc">
                            <span class="av" :style="{ background: avatarColor(c.name_en) }">{{ c.name_ar[0] }}</span>
                            <div><b>{{ c.name }}</b><div class="m">{{ c.name_ar === c.name ? c.name_en : c.name_ar }}</div></div>
                        </div>
                    </td>
                    <td><StatusBadge :status="c.status" :read-only="c.read_only" /></td>
                    <td class="sm">
                        <template v-if="c.admin">
                            <div style="color:var(--tx);font-weight:700">{{ c.admin.name }}</div>
                            <div class="xs num" style="color:var(--cu);font-weight:600">{{ c.admin.email }}</div>
                            <span v-if="!c.admin.activated" class="bd am nodot" style="margin-top:3px">{{ t('admin.pending') }}</span>
                        </template>
                    </td>
                    <td><UsageMeter :used="c.office_used" :limit="c.office_users_limit" compact /></td>
                    <td><UsageMeter :used="c.drivers_used" :limit="c.driver_accounts_limit" compact /></td>
                    <td class="sm">
                        <span :class="{ rd: c.read_only, am: c.expiring_soon }">{{ t(c.read_only ? 'admin.endedOn' : 'admin.ends', { date: date(c.subscription_ends_at) }) }}</span>
                    </td>
                    <td class="e" style="white-space:nowrap">
                        <button v-if="c.admin && !c.admin.activated" class="btn btn-gh sm" :title="t('admin.resend')" @click="resend(c)"><AppIcon name="sync" /></button>
                        <button class="btn btn-ln sm" @click="emit('edit', c)"><AppIcon name="edit" /> {{ t('admin.editLimits') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
