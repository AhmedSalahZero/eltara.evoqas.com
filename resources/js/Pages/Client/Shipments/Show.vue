<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Shipment details ( /client/shipments/{trip} )
  Location: resources/js/Pages/Client/Shipments/Show.vue

  Scope §7 "Shipment details": client-friendly steps (confirmed → truck
  ready → loading → on the road → delivered), truck and driver with a
  call button, expected arrival, the timeline with locations, the cash
  handed on this trip (confirm or dispute), the price, the delivery
  proof photo, a 1–5 star rating with a comment, and a complaint form.
  Never shown: costs, profit, custody, advances.
  Server: App\Http\Controllers\Client\ShipmentController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Plate from '@/Components/Plate.vue';
import Stars from '@/Components/Stars.vue';
import TripStatus from '@/Components/TripStatus.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, money, num, time } from '@/Utils/format';

const props = defineProps({ trip: Object, timeline: Array, collections: Array, rating: Object, canRate: Boolean });
const { t } = useI18n();

const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');
const steps = computed(() => ['confirmed', 'ready', 'loading', 'on_road', 'delivered'].map((key, i) => ({ key, state: i < props.trip.step || props.trip.step === 4 ? 'ok' : i === props.trip.step ? 'now' : '' })));
const stepTime = { 1: 'accepted', 2: 'loading', 3: 'departed', 4: 'delivered' };
const at = (i) => props.timeline.find((e) => e.type === stepTime[i])?.at;
const mapUrl = (e) => `https://www.google.com/maps?q=${e.lat},${e.lng}`;

const rateForm = useForm({ stars: 0, comment: '' });
const rate = () => rateForm.post(route('client.shipments.rate', props.trip.id), { preserveScroll: true });

const cState = { confirmed: 'gn', awaiting_client: 'am', awaiting_driver: 'am', disputed: 'rd', cancelled: 'pl' };
const confirm = (c) => router.post(route('client.cash.confirm', c.id), {}, { preserveScroll: true });
const disputing = ref(null);
const disputeForm = useForm({ note: '' });
const dispute = () => disputeForm.post(route('client.cash.dispute', disputing.value.id), { preserveScroll: true, onSuccess: () => (disputing.value = null) });

const complaint = ref(false);
const complaintForm = useForm({ trip_id: props.trip.id, subject: '', body: '' });
const sendComplaint = () => complaintForm.post(route('client.feedback.store'), { preserveScroll: true, onSuccess: () => { complaint.value = false; complaintForm.reset('subject', 'body'); } });

const cashForm = useForm({ trip_id: props.trip.id, amount: '', note: '' });
const cashOpen = ref(false);
const sendCash = () => cashForm.post(route('client.cash.record'), { preserveScroll: true, onSuccess: () => { cashOpen.value = false; cashForm.reset('amount', 'note'); } });
</script>

<template>
    <PortalLayout :title="trip.number">
        <div class="tr-hero">
            <div class="rt">
                <Link :href="route('client.shipments.index')" class="ib" :aria-label="t('common.back')"><AppIcon name="back" /></Link>
                <h1><span class="num">{{ trip.number }}</span> <TripStatus :status="trip.status" /></h1>
                <button class="btn btn-ln btn-sm" style="margin-inline-start:auto" @click="complaint = true"><AppIcon name="flag" /> {{ t('cp.complain') }}</button>
            </div>
            <div class="tr-meta">
                <div><span>{{ t('cp.from') }}</span><b>{{ trip.from }}</b></div>
                <div><span>{{ t('cp.to') }}</span><b>{{ trip.to }}</b></div>
                <div><span>{{ t('cp.loadingAt') }}</span><b class="num">{{ when(trip.loading_at) }}</b></div>
                <div><span>{{ t('cp.col.price') }}</span><b class="num">{{ money(trip.price) }} EGP</b></div>
                <div><span>{{ t('cp.cargo') }}</span><b>{{ trip.cargo ?? '—' }}</b></div>
                <div><span>{{ t('cp.weight') }}</span><b class="num">{{ trip.weight_tons != null ? num(trip.weight_tons, 2) : '—' }}</b></div>
                <div><span>{{ t('cp.expected') }}</span><b class="num">{{ trip.arrival ? when(trip.arrival) : '—' }}</b></div>
                <div><span>{{ t('cp.delivered') }}</span><b class="num">{{ when(trip.delivered_at) }}</b></div>
            </div>
            <div class="steps">
                <div v-for="(s, i) in steps" :key="s.key" class="step" :class="s.state">
                    <i><AppIcon v-if="s.state === 'ok'" name="check" /></i>{{ t('cp.steps.' + s.key) }}<small>{{ at(i) ? when(at(i)) : '' }}</small>
                </div>
            </div>
        </div>

        <div class="g-73" style="margin-top:14px;align-items:start">
            <div style="display:grid;gap:14px;min-width:0">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="hand" />{{ t('cp.cashOnTrip') }}</h3></div>
                        <button v-if="trip.cash_allowed" class="btn btn-ln btn-sm" @click="cashOpen = true"><AppIcon name="plus" /> {{ t('cp.recordCash') }}</button></div>
                    <div v-if="!collections.length" class="empty" style="border:0">{{ t('cp.noCash') }}</div>
                    <div v-for="c in collections" :key="c.id" class="rowlist"><div class="r">
                        <div>
                            <b class="num">{{ money(c.amount) }}</b> <span class="bd nodot" :class="cState[c.state]">{{ t('cp.cState.' + c.state) }}</span>
                            <div class="xs mu"><span class="num">{{ when(c.received_at) }}</span> · {{ t('cp.by.' + c.recorded_by) }}<template v-if="c.dispute_note"> · «{{ c.dispute_note }}»</template></div>
                        </div>
                        <div v-if="c.state === 'awaiting_client'" class="two-side">
                            <button class="btn btn-gn btn-sm" @click="confirm(c)"><AppIcon name="check" /> {{ t('cp.confirm') }}</button>
                            <button class="btn btn-rd btn-sm" @click="disputing = c; disputeForm.reset()">{{ t('cp.dispute') }}</button>
                        </div>
                    </div></div>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="clock" />{{ t('cp.timeline') }}</h3></div></div>
                    <div v-if="!timeline.length" class="empty" style="border:0">{{ t('cp.noTimeline') }}</div>
                    <ul class="tl">
                        <li v-for="e in timeline" :key="e.id">
                            <div><b>{{ t('cp.ev.' + e.type) }}</b>
                                <span v-if="e.lat != null"><a :href="mapUrl(e)" target="_blank" rel="noopener" style="color:var(--cu)"><AppIcon name="pin" :size="13" /> {{ t('cp.viewOnMap') }}</a></span></div>
                            <span />
                            <div class="t">{{ when(e.at) }}</div>
                        </li>
                    </ul>
                </div>
            </div>

            <div style="display:grid;gap:14px;min-width:0">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="truck" />{{ t('cp.truckDriver') }}</h3></div></div>
                    <template v-if="trip.vehicle">
                        <div class="kv"><span>{{ t('cp.col.truck') }}</span><b><Plate :number="trip.vehicle.number" :letters="trip.vehicle.letters" /></b></div>
                        <div v-if="trip.truck_type" class="kv"><span>{{ t('cp.truckType') }}</span><b>{{ trip.truck_type }}</b></div>
                        <div class="kv"><span>{{ t('cp.driver') }}</span><b>{{ trip.driver ?? '—' }}</b></div>
                        <a v-if="trip.driver_phone" :href="'tel:' + trip.driver_phone" class="call" style="margin-top:10px"><AppIcon name="phone" /> {{ t('cp.callDriver') }} <span class="num" dir="ltr">{{ trip.driver_phone }}</span></a>
                    </template>
                    <p v-else class="xs mu">{{ t('cp.truckSoon') }}</p>
                </div>

                <div v-if="trip.has_pod" class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="camera" />{{ t('cp.pod') }}</h3></div></div>
                    <a :href="route('client.shipments.pod', trip.id)" target="_blank" rel="noopener"><img :src="route('client.shipments.pod', trip.id)" :alt="t('cp.pod')" style="width:100%;border-radius:12px;border:1px solid var(--line)"></a>
                    <p v-if="trip.pod_receiver" class="xs mu" style="margin:8px 0 0">{{ t('cp.receivedBy', { name: trip.pod_receiver }) }}</p>
                    <a :href="route('client.shipments.pod', trip.id)" download class="btn btn-ln btn-sm" style="margin-top:8px"><AppIcon name="download" /> {{ t('cp.download') }}</a>
                </div>

                <div v-if="rating || canRate" class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="flag" />{{ t('cp.rating') }}</h3></div></div>
                    <template v-if="rating">
                        <Stars :value="rating.stars" /><p v-if="rating.comment" class="sm" style="margin:8px 0 0">{{ rating.comment }}</p>
                    </template>
                    <form v-else @submit.prevent="rate">
                        <Stars v-model="rateForm.stars" editable />
                        <Field :label="t('cp.comment')" :hint="t('common.optional')" :error="rateForm.errors.comment || rateForm.errors.stars"><textarea v-model="rateForm.comment" rows="2" maxlength="500" /></Field>
                        <button class="btn btn-cu btn-sm" :disabled="rateForm.processing || !rateForm.stars">{{ t('cp.sendRating') }}</button>
                    </form>
                </div>
            </div>
        </div>

        <Modal :show="!!disputing" :title="t('cp.disputeTitle')" @close="disputing = null">
            <form id="c-dispute" @submit.prevent="dispute">
                <p v-if="disputing" class="sm" style="margin:10px 0 0"><b class="num">{{ money(disputing.amount) }}</b></p>
                <Field :label="t('cp.disputeWhy')" :error="disputeForm.errors.note"><input v-model="disputeForm.note" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="disputing = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="c-dispute" class="btn btn-rd" :disabled="disputeForm.processing">{{ t('cp.dispute') }}</button>
            </template>
        </Modal>

        <Modal :show="complaint" :title="t('cp.complain')" @close="complaint = false">
            <form id="c-complaint" @submit.prevent="sendComplaint">
                <Field :label="t('cp.subject')" :error="complaintForm.errors.subject"><input v-model="complaintForm.subject" maxlength="150" required></Field>
                <Field :label="t('cp.details')" :error="complaintForm.errors.body"><textarea v-model="complaintForm.body" rows="4" maxlength="2000" required /></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="complaint = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="c-complaint" class="btn btn-cu" :disabled="complaintForm.processing">{{ t('cp.send') }}</button>
            </template>
        </Modal>

        <Modal :show="cashOpen" :title="t('cp.recordCash')" @close="cashOpen = false">
            <form id="c-cash" @submit.prevent="sendCash">
                <p class="xs mu" style="margin:10px 0 0">{{ t('cp.recordCashHint') }}</p>
                <Field :label="t('cp.amount')" :error="cashForm.errors.amount"><input v-model="cashForm.amount" type="number" min="1" step="0.01" dir="ltr" required></Field>
                <Field :label="t('cp.noteOpt')" :error="cashForm.errors.note"><input v-model="cashForm.note" maxlength="250"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="cashOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="c-cash" class="btn btn-cu" :disabled="cashForm.processing">{{ t('common.save') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
