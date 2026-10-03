<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: Alerts screen
  Location: resources/js/driver/screens/Alerts.vue

  What needs the driver's attention (Scope §8.2):
    · cash the client says he paid — confirm or dispute
    · transfer requests waiting for a manager / refused
    · entries the office refused when they uploaded (with the reason)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import Icon from '@/Components/AppIcon.vue';
import { cashToConfirm } from '../projection';
import { act, dismissRejected, state, view } from '../store';
import { money, time } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();

const cash = computed(() => cashToConfirm(view.value.trips));
const transfers = computed(() => view.value.trips.flatMap((tr) => tr.transfers.filter((x) => ['pending', 'rejected'].includes(x.status)).map((x) => ({ ...x, trip: tr }))));
const empty = computed(() => !cash.value.length && !transfers.value.length && !state.rejected.length);

const disputing = ref(null);
const note = ref('');

async function dispute(c) {
    await act('collection.dispute', { collection_id: c.id, note: note.value || null });
    disputing.value = null;
    note.value = '';
}
</script>

<template>
    <div class="p-h"><b>{{ t('driver.alerts') }}</b></div>

    <div v-if="empty" class="empty" style="margin-top:10px">
        <Icon name="bell" :size="26" />
        <p class="sm" style="margin:8px 0 0">{{ t('driver.alertsEmpty') }}</p>
    </div>

    <div v-for="c in cash" :key="c.id" class="p-card" style="border-color:var(--am-bd)">
        <b class="sm">{{ t('driver.clientSaysPaid', { amount: money(c.amount, 2) }) }}</b>
        <div class="xs mu"><span class="num">{{ c.trip.number }}</span> · {{ time(c.received_at) }}</div>
        <div v-if="disputing !== c.id" style="display:flex;gap:8px;margin-top:10px">
            <button type="button" class="btn btn-rd" style="flex:1;justify-content:center" @click="disputing = c.id">{{ t('driver.notReceived') }}</button>
            <button type="button" class="btn btn-gn" style="flex:1;justify-content:center" @click="act('collection.confirm', { collection_id: c.id })">{{ t('driver.receivedIt') }}</button>
        </div>
        <div v-else class="fld">
            <input v-model="note" type="text" maxlength="250" :placeholder="t('driver.disputeNote')">
            <div style="display:flex;gap:8px">
                <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="disputing = null">{{ t('common.cancel') }}</button>
                <button type="button" class="btn btn-rd" style="flex:1;justify-content:center" @click="dispute(c)">{{ t('driver.sendDispute') }}</button>
            </div>
        </div>
    </div>

    <div v-for="x in transfers" :key="x.id" class="p-card">
        <div style="display:flex;gap:8px;align-items:center">
            <Icon name="swap" />
            <div style="flex:1"><b class="sm">{{ x.reason }}</b><div class="xs mu"><span class="num">{{ x.trip.number }}</span></div></div>
            <div style="text-align:end"><b class="num">{{ money(x.amount, 2) }}</b><div class="xs" :class="x.status === 'rejected' ? 'rd' : 'am'">{{ t('driver.xstate.' + x.status) }}</div></div>
        </div>
    </div>

    <div v-if="state.rejected.length" class="p-card" style="border-color:var(--rd-bd)">
        <div class="xs rd b">{{ t('driver.rejectedList') }}</div>
        <p class="xs mu" style="margin:4px 0 0">{{ t('driver.rejectedHelp') }}</p>
        <div v-for="r in state.rejected" :key="r.id" class="q-item">
            <Icon name="alert" />
            <span style="flex:1">{{ r.error }}<span class="xs mu" style="display:block">{{ t('driver.entry.' + r.type.replace('.', '_')) }} · {{ time(r.recordedAt) }}</span></span>
            <button type="button" class="btn btn-ln sm" @click="dismissRejected(r.id)">{{ t('driver.gotIt') }}</button>
        </div>
    </div>
</template>
