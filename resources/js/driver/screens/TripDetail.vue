<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: trip details
  Location: resources/js/driver/screens/TripDetail.vue

  Scope §8.2: the trip's facts (never a price), the five steps with
  the one big button for the next step, the trip's three wallets, and
  what was spent / received / transferred. The steps accept → loading
  → depart are recorded with one tap (and a confirmation); delivery
  opens its own screen because it needs the stamped-note photo.
  Everything is recorded on the phone first, so it works offline.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import { pick, routeLabel, stepIndex, STEPS } from '../labels';
import { cashToConfirm } from '../projection';
import { act, state, view } from '../store';
import { date, money, time } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const categories = computed(() => Object.fromEntries((state.snapshot?.categories ?? []).map((c) => [c.id, c])));
const confirming = ref(false);
const disputing = ref(null);
const note = ref('');

const running = computed(() => trip.value && trip.value.status !== 'planned');
/** Custody was planned for this trip but the office has not handed it over yet. */
const custodyMissing = computed(() => trip.value?.status === 'accepted' && trip.value.custody_planned > 0 && !trip.value.custody_issued);
/** Custody + collections − what he paid from his own pocket. */
const net = computed(() => (trip.value ? Math.round((trip.value.wallets.custody + trip.value.wallets.collections - trip.value.wallets.pocket) * 100) / 100 : 0));
const toConfirm = computed(() => (trip.value ? cashToConfirm([trip.value]) : []));

const catName = (e) => (e.is_personal ? t('driver.personal') : pick(categories.value[e.category_id], isAr.value) || '—');

async function step() {
    const type = trip.value.next;
    if (type === 'trip.delivery') return router.push({ name: 'trip-deliver', params: { id: trip.value.id } });
    await act(type, { trip_id: trip.value.id }, true);
    confirming.value = false;
}

async function confirmCash(c) {
    await act('collection.confirm', { collection_id: c.id });
}

async function dispute(c) {
    await act('collection.dispute', { collection_id: c.id, note: note.value || null });
    disputing.value = null;
    note.value = '';
}
</script>

<template>
    <div v-if="!trip" class="empty" style="margin-top:10px">
        <p class="sm" style="margin:0">{{ t('driver.tripGone') }}</p>
        <router-link :to="{ name: 'trips' }" class="btn btn-ln" style="margin-top:10px">{{ t('driver.trips') }}</router-link>
    </div>

    <template v-else>
        <div class="p-h">
            <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
            <b class="num">{{ trip.number }}</b>
            <span class="xs b cu">{{ t('driver.st.' + trip.status) }}</span>
        </div>

        <div class="p-card" style="margin-top:0">
            <div class="b">{{ routeLabel(trip, isAr) }}</div>
            <div class="xs mu">{{ pick(trip.customer, isAr) }}</div>
            <div class="p-steps">
                <i v-for="(s, i) in STEPS" :key="s" :class="{ ok: i < stepIndex(trip.status) || trip.status === 'delivered', now: i === stepIndex(trip.status) && trip.status !== 'delivered' }" />
            </div>
            <div class="p-kv">
                <div><span>{{ t('driver.cargo') }}</span><b>{{ pick(trip.cargo, isAr) || '—' }}</b></div>
                <div><span>{{ t('driver.weight') }}</span><b class="num">{{ trip.weight_tons != null ? `${trip.weight_tons} ${t('driver.ton')}` : '—' }}</b></div>
                <div><span>{{ t('driver.truck') }}</span><b class="num">{{ trip.truck || '—' }}</b></div>
                <div><span>{{ t('driver.loadingAt') }}</span><b class="num">{{ trip.loading_at ? `${date(trip.loading_at)} ${time(trip.loading_at)}` : '—' }}</b></div>
            </div>
            <p v-if="trip.notes" class="sm" style="margin:10px 0 0">{{ trip.notes }}</p>
            <p v-if="trip.status === 'delivered'" class="sm gn b" style="margin:10px 0 0">
                {{ t('driver.deliveredTo', { name: trip.pod_receiver || '—' }) }} · {{ t('driver.awaitingSettlement') }}
            </p>
        </div>

        <!-- The one big next step -->
        <template v-if="trip.next">
            <p v-if="custodyMissing" class="p-off" style="margin:10px 0 0"><Icon name="alert" /> <span style="flex:1">{{ t('driver.custodyFirst') }}</span></p>
            <button v-if="!confirming && trip.next !== 'trip.delivery'" type="button" class="bigbtn" :disabled="custodyMissing" :style="custodyMissing ? 'opacity:.5' : ''" @click="confirming = true">
                {{ t('driver.nextStep.' + trip.next.replace('.', '_')) }}
            </button>
            <button v-else-if="trip.next === 'trip.delivery'" type="button" class="bigbtn" @click="step">{{ t('driver.nextStep.trip_delivery') }}</button>
            <div v-else class="p-card" style="border-color:var(--cu)">
                <p class="sm" style="margin:0">{{ t('driver.sure.' + trip.next.replace('.', '_')) }}</p>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="confirming = false">{{ t('common.cancel') }}</button>
                    <button type="button" class="btn btn-nv" style="flex:1;justify-content:center" @click="step">{{ t('driver.confirm') }}</button>
                </div>
            </div>
        </template>

        <!-- Custody to sign for (Scope §8.2 "Receive custody") -->
        <div v-if="trip.custody.to_sign > 0" class="p-card" style="border-color:var(--cu)">
            <b class="sm">{{ t('driver.custodyReady', { amount: money(trip.custody.to_sign, 2) }) }}</b>
            <router-link :to="{ name: 'trip-custody', params: { id: trip.id } }" class="bigbtn" style="display:block;text-align:center;text-decoration:none;margin-top:10px">{{ t('driver.receiveCustody') }}</router-link>
        </div>
        <p v-else-if="trip.custody.received" class="xs gn b" style="margin:10px 2px 0"><Icon name="check" :size="13" /> {{ t('driver.custodySigned') }}</p>

        <!-- After delivery: what he hands over now (Scope §10 "Net at settlement") -->
        <div v-if="trip.status === 'delivered'" class="p-card" style="border-color:var(--gn-bd)">
            <b class="sm">{{ net >= 0 ? t('driver.handOver') : t('driver.companyOwes') }}</b>
            <div class="num b" style="font-size:22px;margin-top:4px">{{ money(Math.abs(net), 2) }} <small style="font-size:12px">{{ t('common.egp') }}</small></div>
            <p class="xs mu" style="margin:4px 0 0">{{ t('driver.netHint') }}</p>
        </div>

        <!-- Money -->
        <div class="p-walls" style="grid-template-columns:repeat(3,1fr)">
            <div style="--w:var(--cu);--w-dim:var(--cu-dim);--w-bd:var(--cu-bd)"><span>{{ t('driver.custody') }}</span><b class="num" style="font-size:16px">{{ money(trip.wallets.custody, 2) }}</b></div>
            <div style="--w:var(--gn);--w-dim:var(--gn-dim);--w-bd:var(--gn-bd)"><span>{{ t('driver.collections') }}</span><b class="num" style="font-size:16px">{{ money(trip.wallets.collections, 2) }}</b></div>
            <div style="--w:var(--am);--w-dim:var(--am-dim);--w-bd:var(--am-bd)"><span>{{ t('driver.pocket') }}</span><b class="num" style="font-size:16px">{{ money(trip.wallets.pocket, 2) }}</b></div>
        </div>

        <div v-if="running" class="p-acts">
            <router-link :to="{ name: 'trip-expense', params: { id: trip.id } }" class="p-act" style="text-decoration:none;color:inherit"><span class="ic" style="--a:var(--cu);--a-dim:var(--cu-dim)"><Icon name="receipt" /></span><b style="font-size:12px">{{ t('driver.addExpense') }}</b></router-link>
            <router-link :to="{ name: 'trip-cash', params: { id: trip.id } }" class="p-act" style="text-decoration:none;color:inherit"><span class="ic" style="--a:var(--gn);--a-dim:var(--gn-dim)"><Icon name="cash" /></span><b style="font-size:12px">{{ t('driver.cashFromClient') }}</b></router-link>
            <router-link :to="{ name: 'trip-transfer', params: { id: trip.id } }" class="p-act" style="text-decoration:none;color:inherit"><span class="ic" style="--a:var(--am);--a-dim:var(--am-dim)"><Icon name="swap" /></span><b style="font-size:12px">{{ t('driver.useClientMoney') }}</b></router-link>
            <router-link v-if="trip.status !== 'delivered'" :to="{ name: 'trip-custody-request', params: { id: trip.id } }" class="p-act" style="text-decoration:none;color:inherit"><span class="ic" style="--a:var(--rd);--a-dim:var(--rd-dim)"><Icon name="wallet" /></span><b style="font-size:12px">{{ t('driver.custodyRanOut') }}</b></router-link>
        </div>

        <!-- Cash the client says he paid: confirm or dispute (two-sided) -->
        <div v-for="c in toConfirm" :key="c.id" class="p-card" style="border-color:var(--am-bd)">
            <b class="sm">{{ t('driver.clientSaysPaid', { amount: money(c.amount, 2) }) }}</b>
            <div v-if="disputing !== c.id" style="display:flex;gap:8px;margin-top:10px">
                <button type="button" class="btn btn-rd" style="flex:1;justify-content:center" @click="disputing = c.id">{{ t('driver.notReceived') }}</button>
                <button type="button" class="btn btn-gn" style="flex:1;justify-content:center" @click="confirmCash(c)">{{ t('driver.receivedIt') }}</button>
            </div>
            <div v-else class="fld">
                <input v-model="note" type="text" maxlength="250" :placeholder="t('driver.disputeNote')">
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="disputing = null">{{ t('common.cancel') }}</button>
                    <button type="button" class="btn btn-rd" style="flex:1;justify-content:center" @click="dispute(c)">{{ t('driver.sendDispute') }}</button>
                </div>
            </div>
        </div>

        <!-- What was recorded -->
        <div class="p-card">
            <b class="sm">{{ t('driver.expenses') }}</b>
            <p v-if="!trip.expenses.length" class="xs mu" style="margin:6px 0 0">{{ t('driver.none') }}</p>
            <div v-for="e in trip.expenses" :key="e.id" class="q-item">
                <Icon :name="e.has_receipt ? 'receipt' : 'dash'" />
                <span style="flex:1">{{ catName(e) }}<span v-if="e.note" class="xs mu"> · {{ e.note }}</span>
                    <span class="xs mu" style="display:block">{{ t('driver.from.' + e.paid_from) }} · {{ time(e.spent_at) }}<template v-if="e.local"> · <span class="am">{{ t('driver.notUploaded') }}</span></template></span>
                </span>
                <b class="num">{{ money(e.amount, 2) }}</b>
            </div>
        </div>

        <div class="p-card">
            <b class="sm">{{ t('driver.cashReceived') }}</b>
            <p v-if="!trip.collections.length" class="xs mu" style="margin:6px 0 0">{{ t('driver.none') }}</p>
            <div v-for="c in trip.collections" :key="c.id" class="q-item">
                <Icon name="cash" />
                <span style="flex:1"><span class="xs" :class="c.state === 'confirmed' ? 'gn' : c.state === 'disputed' ? 'rd' : 'am'">{{ t('driver.cstate.' + c.state) }}</span>
                    <span class="xs mu" style="display:block">{{ time(c.received_at) }}<template v-if="c.local"> · <span class="am">{{ t('driver.notUploaded') }}</span></template></span>
                </span>
                <b class="num">{{ money(c.amount, 2) }}</b>
            </div>
        </div>

        <div v-if="trip.transfers.length" class="p-card">
            <b class="sm">{{ t('driver.transfers') }}</b>
            <div v-for="x in trip.transfers" :key="x.id" class="q-item">
                <Icon name="swap" />
                <span style="flex:1">{{ x.reason }}
                    <span class="xs" :class="['approved', 'auto'].includes(x.status) ? 'gn' : x.status === 'rejected' ? 'rd' : 'am'" style="display:block">{{ t('driver.xstate.' + x.status) }}<template v-if="x.local"> · {{ t('driver.notUploaded') }}</template></span>
                </span>
                <b class="num">{{ money(x.amount, 2) }}</b>
            </div>
        </div>
    </template>
</template>
