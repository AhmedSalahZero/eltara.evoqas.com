<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: receive custody
  Location: resources/js/driver/screens/ReceiveCustody.vue

  Scope §8.2: the amount handed over, what it is meant for (the
  route's budget lines), the driver's finger signature, confirm.
  The amount is the one the office issued and he has not signed for yet
  (all of it, or just a top-up) — the driver does not type it. Works offline; the signature uploads like a photo.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import SignaturePad from '../components/SignaturePad.vue';
import { pick } from '../labels';
import { act, view } from '../store';
import { money } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const signature = ref(null);
const error = ref('');
watch(signature, () => (error.value = ''));

async function save() {
    error.value = '';
    if (!signature.value) return (error.value = t('driver.signatureRequired'));

    await act('trip.custody_receive', { trip_id: trip.value.id, signature: signature.value }, true);
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.receiveCustody') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip || !trip.custody.to_sign" class="empty"><p class="sm" style="margin:0">{{ t('driver.nothingToReceive') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <div class="p-walls" style="grid-template-columns:1fr;margin-top:0">
            <div style="--w:var(--cu);--w-dim:var(--cu-dim);--w-bd:var(--cu-bd)"><span>{{ t('driver.custodyHanded') }}</span><b class="num">{{ money(trip.custody.to_sign, 2) }} <small style="font-size:12px">{{ t('common.egp') }}</small></b></div>
        </div>

        <template v-if="trip.custody.budget.length">
            <div class="b sm" style="margin-top:12px">{{ t('driver.meantFor') }}</div>
            <div v-for="b in trip.custody.budget" :key="b.category_id" class="q-item"><span style="flex:1">{{ pick(b, isAr) }}</span><b class="num">{{ money(b.amount, 2) }}</b></div>
        </template>

        <SignaturePad v-model="signature" :error="error" />
        <button type="submit" class="bigbtn">{{ t('driver.confirmReceipt') }}</button>
    </form>
</template>
