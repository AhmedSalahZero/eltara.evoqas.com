<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Client requests ( /office/client-requests )
  Location: resources/js/Pages/Office/ClientRequests/Index.vue

  Scope §6.2: four tabs —
    New                  what clients asked for: assign a truck (and
                         driver) for each truck asked → one planned trip
                         each, the client is told; or decline with a
                         reason
    Waiting for trucks   approved (yes said) but trucks not chosen yet:
                         sorted by loading date, urgent ones flagged
    Answered             assigned (with its trips) / declined / withdrawn
    Complaints & ratings complaints to answer, and the stars given
  Server: App\Http\Controllers\Office\ClientRequestController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Plate from '@/Components/Plate.vue';
import Stars from '@/Components/Stars.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, time } from '@/Utils/format';

const props = defineProps({ tab: String, tiles: Object, requests: Array, complaints: Array, ratings: Array, average: Number, options: Object });
const { t } = useI18n();
const { can } = usePermissions();

const go = (tab) => router.get(route('office.client-requests.index'), { tab }, { preserveScroll: true });
const when = (iso) => (iso ? `${date(iso)} ${time(iso)}` : '—');
const colour = { new: 'am', approved: 'cu', assigned: 'gn', declined: 'rd', cancelled: 'pl' };

// ── Assign trucks ───────────────────────────────────────────────
const assigning = ref(null);
const assignForm = useForm({ assignments: [] });

function openAssign(r) {
    assigning.value = r;
    assignForm.assignments = Array.from({ length: r.slots.length }, () => ({ vehicle_id: '', driver_id: '' }));
    assignForm.clearErrors();
}

function pickTruck(row) {
    const v = props.options.vehicles.find((x) => x.id === Number(row.vehicle_id));
    row.driver_id = v?.driver_id ?? '';
}

const truckLabel = (v) => {
    const note = v.maintenance ? ` — ${t('trip.inMaintenance')}` : v.on_trip ? ` — ${t('trip.onTrip', { trip: v.on_trip })}` : v.booked ? ` — ${t('trip.booked', { trip: v.booked })}` : '';
    return `${v.number} ${v.letters ?? ''}${v.type ? ' · ' + v.type : ''}${v.driver ? ' · ' + v.driver : ''}${note}`;
};
const driverLabel = (d) => d.name + (d.on_trip ? ` — ${t('trip.onTrip', { trip: d.on_trip })}` : d.booked ? ` — ${t('trip.booked', { trip: d.booked })}` : '');

const assign = () => assignForm
    .transform((data) => ({ assignments: data.assignments.map((a) => ({ vehicle_id: a.vehicle_id, driver_id: a.driver_id || null })) }))
    .post(route('office.client-requests.assign', assigning.value.id), { preserveScroll: true, onSuccess: () => (assigning.value = null) });

const approve = (r) => router.post(route('office.client-requests.approve', r.id), {}, { preserveScroll: true });

// ── Decline ─────────────────────────────────────────────────────
const declining = ref(null);
const declineForm = useForm({ reason: '' });
const decline = () => declineForm.post(route('office.client-requests.decline', declining.value.id), { preserveScroll: true, onSuccess: () => { declining.value = null; declineForm.reset(); } });

// ── Reply to a complaint ────────────────────────────────────────
const replying = ref(null);
const replyForm = useForm({ reply: '' });
const openReply = (c) => { replying.value = c; replyForm.reply = c.reply ?? ''; };
const reply = () => replyForm.post(route('office.complaints.reply', replying.value.id), { preserveScroll: true, onSuccess: () => (replying.value = null) });
</script>

<template>
    <PortalLayout :title="t('nav.requests')">
        <PageHeader :title="t('nav.requests')" :sub="t('req.sub')" />

        <div class="tabs">
            <button class="tab" :class="{ on: tab === 'new' }" @click="go('new')">{{ t('req.tabs.new') }}<span v-if="tiles.new" class="c">{{ tiles.new }}</span></button>
            <button class="tab" :class="{ on: tab === 'approved' }" @click="go('approved')">{{ t('req.tabs.approved') }}<span v-if="tiles.approved" class="c">{{ tiles.approved }}</span></button>
            <button class="tab" :class="{ on: tab === 'answered' }" @click="go('answered')">{{ t('req.tabs.answered') }}</button>
            <button class="tab" :class="{ on: tab === 'feedback' }" @click="go('feedback')">{{ t('req.tabs.feedback') }}<span v-if="tiles.complaints" class="c">{{ tiles.complaints }}</span></button>
        </div>

        <!-- New / Answered -->
        <template v-if="tab !== 'feedback'">
            <div v-if="!requests.length" class="empty">{{ t('req.none.' + tab) }}</div>
            <div v-for="r in requests" :key="r.id" class="req">
                <div style="min-width:0">
                    <h4>
                        <span class="num">{{ r.number }}</span>
                        <span class="bd" :class="colour[r.status]">{{ t('cp.rStatus.' + r.status) }}</span>
                        {{ r.customer }}
                    </h4>
                    <div v-for="l in r.lines" :key="l.id" class="sm" style="margin:2px 0"><b class="num">{{ l.trucks }} ×</b> {{ l.route }} <span class="xs mu num">({{ money(l.subtotal) }} EGP)</span></div>
                    <div class="meta">
                        <span>{{ t('cp.loadingAt') }}: <b class="num">{{ when(r.loading_at) }}</b></span>
                        <span>{{ t('cp.trucksTotal') }}: <b class="num">{{ r.trucks }}</b></span>
                        <span v-if="r.cargo">{{ t('cp.cargo') }}: <b>{{ r.cargo }}</b></span>
                        <span v-if="r.total !== null">{{ t('cp.expectedTotal') }}: <b class="num">{{ money(r.total) }} EGP</b></span>
                        <span v-if="r.by">{{ t('req.by') }}: <b>{{ r.by }}</b></span>
                        <span v-if="r.urgent" class="bd am nodot">{{ t('req.urgent') }}</span>
                    </div>
                    <p v-if="r.notes" class="xs mu" style="margin:6px 0 0">{{ r.notes }}</p>
                    <p v-if="r.status === 'declined'" class="xs" style="margin:6px 0 0;color:var(--rd)">{{ t('cp.declinedReason') }} {{ r.decline_reason }}</p>
                    <div v-if="r.trips.length" class="rowlist" style="margin-top:8px">
                        <div v-for="tr in r.trips" :key="tr.id" class="r">
                            <Link :href="route('office.trips.show', tr.id)" class="num b" style="color:var(--cu)">{{ tr.number }}</Link>
                            <div class="xs"><Plate v-if="tr.vehicle" :number="tr.vehicle.number" :letters="tr.vehicle.letters" /> {{ tr.driver }}</div>
                        </div>
                    </div>
                </div>
                <div v-if="['new', 'approved'].includes(r.status) && can('client_requests.approve')" class="acts">
                    <button v-if="r.status === 'new'" class="btn btn-cu btn-sm" @click="approve(r)"><AppIcon name="check" /> {{ t('req.approve') }}</button>
                    <button class="btn btn-gn btn-sm" @click="openAssign(r)"><AppIcon name="truck" /> {{ t('req.assign') }}</button>
                    <button class="btn btn-rd btn-sm" @click="declining = r; declineForm.reset()">{{ t('req.decline') }}</button>
                </div>
            </div>
        </template>

        <!-- Complaints & ratings -->
        <div v-else class="g2" style="align-items:start">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="flag" />{{ t('req.complaints') }}</h3></div></div>
                <div v-if="!complaints.length" class="empty" style="border:0">{{ t('req.none.complaints') }}</div>
                <div v-for="c in complaints" :key="c.id" style="padding:10px 0;border-bottom:1px solid var(--line-soft)">
                    <b>{{ c.subject }}</b> <span class="bd nodot" :class="c.status === 'open' ? 'am' : 'gn'">{{ t('cp.cpStatus.' + c.status) }}</span>
                    <div class="xs mu">{{ c.customer }}<template v-if="c.by"> · {{ c.by }}</template> · <span class="num">{{ date(c.created_at) }}</span>
                        <template v-if="c.trip"> · <Link :href="route('office.trips.show', c.trip.id)" class="num" style="color:var(--cu)">{{ c.trip.number }}</Link></template></div>
                    <p class="sm" style="margin:6px 0">{{ c.body }}</p>
                    <div v-if="c.reply" class="note gn"><AppIcon name="check" /><div><b>{{ t('req.yourReply') }}</b> {{ c.reply }}</div></div>
                    <button v-if="can('client_requests.edit')" class="btn btn-ln btn-sm" style="margin-top:6px" @click="openReply(c)">{{ c.reply ? t('req.editReply') : t('req.reply') }}</button>
                </div>
            </div>

            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="check" />{{ t('req.ratings') }}</h3></div>
                    <span v-if="average" class="bd nodot gn">{{ t('req.avg', { n: average }) }}</span></div>
                <div v-if="!ratings.length" class="empty" style="border:0">{{ t('req.none.ratings') }}</div>
                <div class="rowlist">
                    <div v-for="r in ratings" :key="r.id" class="r">
                        <div><Link :href="route('office.trips.show', r.trip.id)" class="num b" style="color:var(--cu)">{{ r.trip.number }}</Link>
                            <div class="xs mu">{{ r.customer }} · {{ date(r.at) }}<template v-if="r.comment"> · {{ r.comment }}</template></div></div>
                        <Stars :value="r.stars" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Assign trucks -->
        <Modal :show="!!assigning" :title="t('req.assignTitle')" @close="assigning = null">
            <form v-if="assigning && options" id="req-assign" @submit.prevent="assign">
                <p class="sm" style="margin:10px 0 0"><b class="num">{{ assigning.number }}</b> · {{ assigning.customer }} · {{ t('req.needs', { n: assigning.trucks }) }}</p>
                <div v-for="(row, i) in assignForm.assignments" :key="i" class="fgrid" style="margin-top:8px">
                    <Field :label="`${t('req.truckN', { n: i + 1 })} — ${assigning.slots[i]}`" :error="assignForm.errors[`assignments.${i}.vehicle_id`]">
                        <select v-model="row.vehicle_id" required @change="pickTruck(row)">
                            <option value="" disabled>{{ t('common.choose') }}</option>
                            <option v-for="v in options.vehicles" :key="v.id" :value="v.id">{{ truckLabel(v) }}</option>
                        </select>
                    </Field>
                    <Field :label="t('trip.driver')" :error="assignForm.errors[`assignments.${i}.driver_id`]">
                        <select v-model="row.driver_id"><option value="">—</option><option v-for="d in options.drivers" :key="d.id" :value="d.id">{{ driverLabel(d) }}</option></select>
                    </Field>
                </div>
                <p class="xs mu">{{ t('req.assignNote') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="assigning = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="req-assign" class="btn btn-gn" :disabled="assignForm.processing">{{ t('req.assignGo') }}</button>
            </template>
        </Modal>

        <Modal :show="!!declining" :title="t('req.declineTitle')" @close="declining = null">
            <form id="req-decline" @submit.prevent="decline">
                <p v-if="declining" class="sm" style="margin:10px 0 0"><b class="num">{{ declining.number }}</b> · {{ declining.customer }}</p>
                <Field :label="t('req.declineReason')" :error="declineForm.errors.reason"><input v-model="declineForm.reason" maxlength="250" required></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="declining = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="req-decline" class="btn btn-rd" :disabled="declineForm.processing">{{ t('req.decline') }}</button>
            </template>
        </Modal>

        <Modal :show="!!replying" :title="t('req.replyTitle')" @close="replying = null">
            <form id="req-reply" @submit.prevent="reply">
                <p v-if="replying" class="sm" style="margin:10px 0 0"><b>{{ replying.subject }}</b></p>
                <Field :label="t('req.yourReply')" :error="replyForm.errors.reply"><textarea v-model="replyForm.reply" rows="4" maxlength="2000" required /></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="replying = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="req-reply" class="btn btn-cu" :disabled="replyForm.processing">{{ t('req.sendReply') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
