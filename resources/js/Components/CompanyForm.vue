<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — CompanyForm ("New company" / "Edit limits" pop-up)
  Location: resources/js/Components/CompanyForm.vue

  The demo's company form (Scope §4.1):
    New  → names, the company admin (receives the activation link),
           limits, subscription dates, status, default language
    Edit → names, status, limits, dates, defaults (the admin is
           managed by the company itself)
  Server checks: StoreCompanyRequest / UpdateCompanyRequest.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Field from './Field.vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    show: Boolean,
    company: { type: Object, default: null },
    defaults: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const blank = () => ({
    name_ar: '', name_en: '', status: 'trial',
    office_users_limit: props.defaults.office_users_limit ?? 5,
    driver_accounts_limit: props.defaults.driver_accounts_limit ?? 15,
    subscription_starts_at: props.defaults.subscription_starts_at ?? '',
    subscription_ends_at: props.defaults.subscription_ends_at ?? '',
    default_language: 'ar', default_theme: 'dark', contact_phone: '', contact_email: '', notes: '',
    admin_name: '', admin_phone: '', admin_email: '', admin_job_title: '',
});

const form = useForm(blank());
const editing = computed(() => !!props.company);

watch(() => [props.show, props.company], () => {
    form.clearErrors();
    const values = props.company
        ? Object.fromEntries(Object.keys(blank()).map((k) => [k, props.company[k] ?? '']))
        : blank();
    form.defaults(values);
    form.reset();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    editing.value
        ? form.patch(route('admin.companies.update', props.company.id), options)
        : form.post(route('admin.companies.store'), options);
}
</script>

<template>
    <Modal :show="show" wide :title="editing ? `${t('admin.editLimits')} — ${company.name}` : t('admin.newCompany')" @close="emit('close')">
        <form id="company-form" @submit.prevent="submit">
            <div class="fgrid">
                <Field :label="t('admin.nameAr')" :error="form.errors.name_ar"><input v-model="form.name_ar" required></Field>
                <Field :label="t('admin.nameEn')" :error="form.errors.name_en"><input v-model="form.name_en" dir="ltr" required></Field>
            </div>

            <template v-if="!editing">
                <div class="sec" style="margin-top:18px">{{ t('admin.companyAdmin') }}</div>
                <div class="fgrid">
                    <Field :label="t('admin.adminName')" :error="form.errors.admin_name"><input v-model="form.admin_name" required></Field>
                    <Field :label="t('admin.adminPhone')" :error="form.errors.admin_phone"><input v-model="form.admin_phone" dir="ltr" inputmode="tel"></Field>
                    <Field class="w" :label="t('admin.adminEmail')" :error="form.errors.admin_email"><input v-model="form.admin_email" type="email" dir="ltr" required></Field>
                    <Field class="w" :label="t('admin.adminJob')" :error="form.errors.admin_job_title"><input v-model="form.admin_job_title"></Field>
                </div>
            </template>

            <div class="sec" style="margin-top:18px">{{ t('admin.limitsSub') }}</div>
            <div class="fgrid">
                <Field :label="t('admin.officeLimit')" :hint="t('admin.officeLimitHint')" :error="form.errors.office_users_limit">
                    <input v-model.number="form.office_users_limit" type="number" min="1" required>
                </Field>
                <Field :label="t('admin.driverLimit')" :hint="t('admin.driverLimitHint')" :error="form.errors.driver_accounts_limit">
                    <input v-model.number="form.driver_accounts_limit" type="number" min="0" required>
                </Field>
                <Field :label="t('admin.starts')" :error="form.errors.subscription_starts_at"><input v-model="form.subscription_starts_at" type="date" required></Field>
                <Field :label="t('admin.endsAt')" :hint="t('admin.readOnlyAfter')" :error="form.errors.subscription_ends_at"><input v-model="form.subscription_ends_at" type="date" required></Field>
                <Field :label="t('admin.status')" :error="form.errors.status">
                    <select v-model="form.status">
                        <option value="trial">{{ t('status.trial') }}</option>
                        <option value="active">{{ t('status.active') }}</option>
                        <option value="suspended">{{ t('status.suspended') }}</option>
                    </select>
                </Field>
                <Field :label="t('admin.defaultLanguage')">
                    <select v-model="form.default_language"><option value="ar">العربية</option><option value="en">English</option></select>
                </Field>
                <Field :label="t('admin.defaultTheme')">
                    <select v-model="form.default_theme"><option value="dark">{{ t('common.dark') }}</option><option value="light">{{ t('common.light') }}</option></select>
                </Field>
                <Field :label="t('admin.contactPhone')" :error="form.errors.contact_phone"><input v-model="form.contact_phone" dir="ltr"></Field>
                <Field class="w" :label="t('admin.notes')" :error="form.errors.notes"><textarea v-model="form.notes" /></Field>
            </div>
            <div v-if="form.status === 'suspended'" class="note rd" style="margin-top:12px"><AppIcon name="alert" /><div>{{ t('admin.suspendNote') }}</div></div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" form="company-form" class="btn btn-cu" :disabled="form.processing">
                <AppIcon name="check" /> {{ editing ? t('admin.saveChanges') : t('admin.create') }}
            </button>
        </template>
    </Modal>
</template>
