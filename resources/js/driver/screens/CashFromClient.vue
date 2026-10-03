<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: cash received from the client
  Location: resources/js/driver/screens/CashFromClient.vue

  Scope §9: the driver records cash the client paid him (amount, note,
  optional photo). It counts in his collections wallet at once; the
  client then confirms or disputes it in the client portal.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import PhotoPicker from '../components/PhotoPicker.vue';
import { act, state, view } from '../store';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const form = reactive({ amount: '', note: '', photo: null });
const error = ref('');
const photoError = ref('');
const photoRequired = computed(() => !!state.snapshot?.settings?.receipt_photo_required);

async function save() {
    error.value = '';
    photoError.value = '';
    if (!(Number(form.amount) > 0)) return (error.value = t('driver.amountPositive'));
    if (photoRequired.value && !form.photo) return (photoError.value = t('driver.cashPhotoRequired'));

    await act('trip.collection', { trip_id: trip.value.id, amount: Number(form.amount), note: form.note.trim() || null, photo: form.photo });
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.cashFromClient') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip" class="empty"><p class="sm" style="margin:0">{{ t('driver.tripGone') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <p class="sm mu" style="margin:0">{{ t('driver.cashHint') }}</p>
        <label class="fld"><span class="fl">{{ t('driver.amount') }} <small>{{ t('common.egp') }}</small></span>
            <input v-model="form.amount" type="number" inputmode="decimal" step="any" min="0" :class="{ bad: error }" autocomplete="off">
            <span v-if="error" class="err">{{ error }}</span>
        </label>
        <label class="fld"><span class="fl">{{ t('driver.note') }}</span><input v-model="form.note" type="text" maxlength="250"></label>
        <PhotoPicker v-model="form.photo" kind="collection" :label="t('driver.cashPhoto')" :required="photoRequired" :error="photoError" />
        <button type="submit" class="bigbtn">{{ t('driver.saveCash') }}</button>
    </form>
</template>
