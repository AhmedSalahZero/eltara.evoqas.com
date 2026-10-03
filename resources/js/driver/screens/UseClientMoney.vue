<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: use client money for road costs
  Location: resources/js/driver/screens/UseClientMoney.vue

  Scope §6.4: money never moves between wallets silently. The driver
  asks to move some of his collections into his custody, with a
  reason. The trip's policy decides: inside the limit it goes through
  at once, above it a manager must approve (the screen says which).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import { act, view } from '../store';
import { money } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const form = reactive({ amount: '', reason: '' });
const errors = reactive({});

const available = computed(() => trip.value?.wallets.collections ?? 0);
const policy = computed(() => {
    const p = trip.value?.transfer_policy;
    const amount = Number(form.amount);
    if (p === 'auto' || (p === 'limit' && amount > 0 && amount <= (trip.value.auto_transfer_limit ?? 0) + 0.001)) return 'auto';
    return p === 'limit' && !(amount > 0) ? 'limit' : 'approval';
});

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    const amount = Number(form.amount);
    if (!(amount > 0)) errors.amount = t('driver.amountPositive');
    else if (amount > available.value + 0.001) errors.amount = t('driver.exceedsCollections', { amount: money(available.value, 2) });
    if (!form.reason.trim()) errors.reason = t('driver.reasonRequired');
    if (Object.keys(errors).length) return;

    await act('trip.transfer', { trip_id: trip.value.id, amount, reason: form.reason.trim() });
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.useClientMoney') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip" class="empty"><p class="sm" style="margin:0">{{ t('driver.tripGone') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <div class="p-walls" style="margin-top:0">
            <div style="--w:var(--gn);--w-dim:var(--gn-dim);--w-bd:var(--gn-bd)"><span>{{ t('driver.collections') }}</span><b class="num">{{ money(available, 2) }}</b></div>
            <div style="--w:var(--cu);--w-dim:var(--cu-dim);--w-bd:var(--cu-bd)"><span>{{ t('driver.custody') }}</span><b class="num">{{ money(trip.wallets.custody, 2) }}</b></div>
        </div>

        <label class="fld"><span class="fl">{{ t('driver.amount') }} <small>{{ t('common.egp') }}</small></span>
            <input v-model="form.amount" type="number" inputmode="decimal" step="any" min="0" :class="{ bad: errors.amount }" autocomplete="off">
            <span v-if="errors.amount" class="err">{{ errors.amount }}</span>
        </label>
        <label class="fld"><span class="fl">{{ t('driver.reason') }}</span>
            <input v-model="form.reason" type="text" maxlength="250" :class="{ bad: errors.reason }">
            <span v-if="errors.reason" class="err">{{ errors.reason }}</span>
        </label>

        <p class="sm" :class="policy === 'auto' ? 'gn' : 'am'" style="margin:12px 0 0">
            {{ policy === 'auto' ? t('driver.policyAuto') : policy === 'limit' ? t('driver.policyLimit', { limit: money(trip.auto_transfer_limit) }) : t('driver.policyApproval') }}
        </p>

        <button type="submit" class="bigbtn">{{ t('driver.sendRequest') }}</button>
    </form>
</template>
