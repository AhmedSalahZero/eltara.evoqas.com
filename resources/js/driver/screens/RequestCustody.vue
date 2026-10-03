<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: custody ran out
  Location: resources/js/driver/screens/RequestCustody.vue

  Scope §8.2 Home "custody ran out": asks the office for more custody
  (amount + why). It is a request only; the office answers by handing
  over a top-up, which shows on the phone at the next refresh.
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
const form = reactive({ amount: '', note: '' });
const error = ref('');

async function save() {
    error.value = '';
    if (!(Number(form.amount) > 0)) return (error.value = t('driver.amountPositive'));

    await act('trip.custody_request', { trip_id: trip.value.id, amount: Number(form.amount), note: form.note.trim() || null }, true);
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.custodyRanOut') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip" class="empty"><p class="sm" style="margin:0">{{ t('driver.tripGone') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <p class="sm mu" style="margin:0">{{ t('driver.custodyRequestHint', { balance: money(trip.wallets.custody, 2) }) }}</p>
        <p v-if="trip.custody.requested" class="sm am" style="margin:8px 0 0">{{ t('driver.alreadyRequested', { n: trip.custody.requested }) }}</p>
        <label class="fld"><span class="fl">{{ t('driver.amountNeeded') }} <small>{{ t('common.egp') }}</small></span>
            <input v-model="form.amount" type="number" inputmode="decimal" step="any" min="0" :class="{ bad: error }" autocomplete="off">
            <span v-if="error" class="err">{{ error }}</span>
        </label>
        <label class="fld"><span class="fl">{{ t('driver.why') }}</span><input v-model="form.note" type="text" maxlength="250"></label>
        <button type="submit" class="bigbtn">{{ t('driver.sendRequest') }}</button>
    </form>
</template>
