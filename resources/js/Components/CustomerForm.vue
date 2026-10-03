<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — CustomerForm ("New customer" / "Edit customer" pop-up)
  Location: resources/js/Components/CustomerForm.vue
  Names, payment terms, whether they sometimes pay the driver cash
  (Scope §6.9), contact person and details, tax number, notes.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import Field from './Field.vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ show: Boolean, customer: { type: Object, default: null } });
const emit = defineEmits(['close']);
const { t } = useI18n();

const FIELDS = { name_ar: '', name_en: '', payment_terms_days: 30, may_pay_driver_cash: false, contact_name: '', contact_phone: '', contact_email: '', address: '', tax_number: '', notes: '', is_active: true };
const form = useForm({ ...FIELDS });
const editing = computed(() => !!props.customer);

watch(() => [props.show, props.customer], () => {
    const values = { ...FIELDS };
    if (props.customer) for (const k of Object.keys(FIELDS)) values[k] = props.customer[k] ?? FIELDS[k];
    form.defaults(values);
    form.reset();
    form.clearErrors();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    editing.value ? form.patch(route('office.customers.update', props.customer.id), options) : form.post(route('office.customers.store'), options);
}
</script>

<template>
    <Modal :show="show" wide :title="editing ? t('cust.edit') : t('cust.add')" @close="emit('close')">
        <form id="customer-form" @submit.prevent="submit">
            <div class="fgrid">
                <Field :label="t('cust.nameAr')" :error="form.errors.name_ar"><input v-model="form.name_ar" required></Field>
                <Field :label="t('cust.nameEn')" :error="form.errors.name_en"><input v-model="form.name_en" dir="ltr"></Field>
                <Field :label="t('cust.termsDays')" :hint="t('cust.termsHint')" :error="form.errors.payment_terms_days"><input v-model="form.payment_terms_days" type="number" min="0" max="365" dir="ltr" required></Field>
                <Field :label="t('cust.contactName')" :error="form.errors.contact_name"><input v-model="form.contact_name"></Field>
                <Field :label="t('cust.contactPhone')" :error="form.errors.contact_phone"><input v-model="form.contact_phone" dir="ltr" inputmode="tel"></Field>
                <Field :label="t('cust.contactEmail')" :error="form.errors.contact_email"><input v-model="form.contact_email" type="email" dir="ltr"></Field>
                <Field class="w" :label="t('cust.address')" :error="form.errors.address"><input v-model="form.address"></Field>
                <Field :label="t('cust.taxNumber')" :error="form.errors.tax_number"><input v-model="form.tax_number" dir="ltr"></Field>
            </div>
            <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto;margin-top:10px">
                <div><b>{{ t('cust.paysCashQ') }}</b><span>{{ t('cust.paysCash') }}</span></div>
                <label class="sw"><input v-model="form.may_pay_driver_cash" type="checkbox"><i /></label>
            </div>
            <div v-if="editing" class="doc-row" style="grid-template-columns:minmax(0,1fr) auto">
                <div><b>{{ t('cust.active') }}</b></div>
                <label class="sw"><input v-model="form.is_active" type="checkbox"><i /></label>
            </div>
            <Field :label="t('cust.notes')" :error="form.errors.notes"><textarea v-model="form.notes" /></Field>
        </form>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" form="customer-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
        </template>
    </Modal>
</template>
