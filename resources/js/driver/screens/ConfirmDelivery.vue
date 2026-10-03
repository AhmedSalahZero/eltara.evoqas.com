<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: confirm delivery
  Location: resources/js/driver/screens/ConfirmDelivery.vue

  Scope §6.5, §8.2: delivery needs the photo of the stamped delivery
  note (required — the office refuses delivery without it) and the
  receiver's name. The time and, if the company asked for it, the
  place are captured at this moment.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import PhotoPicker from '../components/PhotoPicker.vue';
import { routeLabel } from '../labels';
import { act, view } from '../store';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const form = reactive({ receiver: '', photo: null });
const errors = reactive({});
const saving = ref(false);

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    if (!form.photo) errors.photo = t('driver.podRequired');
    if (Object.keys(errors).length) return;

    saving.value = true;
    await act('trip.delivery', { trip_id: trip.value.id, photo: form.photo, receiver: form.receiver.trim() || null }, true);
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.deliverTitle') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip || trip.status !== 'on_road'" class="empty"><p class="sm" style="margin:0">{{ t('driver.cannotDeliver') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <div class="b sm">{{ routeLabel(trip, isAr) }}</div>
        <PhotoPicker v-model="form.photo" kind="pod" :label="t('driver.podPhoto')" required :error="errors.photo" />
        <label class="fld"><span class="fl">{{ t('driver.receiver') }}</span>
            <input v-model="form.receiver" type="text" maxlength="120">
        </label>
        <button type="submit" class="bigbtn" :disabled="saving">{{ t('driver.confirmDelivery') }}</button>
    </form>
</template>
