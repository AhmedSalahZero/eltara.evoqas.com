<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Ratings & complaints ( /client/feedback )
  Location: resources/js/Pages/Client/Feedback.vue
  Scope §7: my complaints with the replies, delivered trips waiting for
  a rating, and my ratings. A new complaint can be about a trip or
  general. Server: App\Http\Controllers\Client\FeedbackController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import Stars from '@/Components/Stars.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date } from '@/Utils/format';

defineProps({ complaints: Array, ratings: Array, waiting: Array, trips: Array });
const { t } = useI18n();

const open = ref(false);
const form = useForm({ trip_id: '', subject: '', body: '' });
const send = () => form.post(route('client.feedback.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
</script>

<template>
    <PortalLayout :title="t('nav.clientRatings')">
        <PageHeader :title="t('nav.clientRatings')" :sub="t('cp.fbSub')">
            <button class="btn btn-cu" @click="open = true"><AppIcon name="plus" /> {{ t('cp.newComplaint') }}</button>
        </PageHeader>

        <div class="g2" style="align-items:start">
            <div style="display:grid;gap:14px;min-width:0">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="flag" />{{ t('cp.myComplaints') }}</h3></div></div>
                    <div v-if="!complaints.length" class="empty" style="border:0">{{ t('cp.noComplaints') }}</div>
                    <div v-for="c in complaints" :key="c.id" class="rowlist" style="padding-bottom:10px"><div class="r" style="grid-template-columns:minmax(0,1fr)">
                        <div>
                            <b>{{ c.subject }}</b> <span class="bd nodot" :class="c.status === 'open' ? 'am' : 'gn'">{{ t('cp.cpStatus.' + c.status) }}</span>
                            <div class="xs mu"><span class="num">{{ date(c.created_at) }}</span><template v-if="c.trip"> · <Link :href="route('client.shipments.show', c.trip.id)" style="color:var(--cu)" class="num">{{ c.trip.number }}</Link></template></div>
                            <p class="sm" style="margin:6px 0 0">{{ c.body }}</p>
                            <div v-if="c.reply" class="note gn" style="margin-top:8px"><AppIcon name="check" /><div><b>{{ t('cp.reply') }}</b> {{ c.reply }}</div></div>
                        </div>
                    </div></div>
                </div>
            </div>

            <div style="display:grid;gap:14px;min-width:0">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="clock" />{{ t('cp.toRate') }}</h3></div></div>
                    <div v-if="!waiting.length" class="empty" style="border:0">{{ t('cp.nothingToRate') }}</div>
                    <div class="rowlist">
                        <div v-for="w in waiting" :key="w.id" class="r">
                            <div><b class="num">{{ w.number }}</b><div class="xs mu">{{ w.route }} · {{ date(w.delivered_at) }}</div></div>
                            <Link :href="route('client.shipments.show', w.id)" class="btn btn-ln btn-sm">{{ t('cp.rateIt') }}</Link>
                        </div>
                    </div>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="check" />{{ t('cp.myRatings') }}</h3></div></div>
                    <div v-if="!ratings.length" class="empty" style="border:0">{{ t('cp.noRatings') }}</div>
                    <div class="rowlist">
                        <div v-for="r in ratings" :key="r.id" class="r">
                            <div><Link :href="route('client.shipments.show', r.trip.id)" class="num b" style="color:var(--cu)">{{ r.trip.number }}</Link><div class="xs mu">{{ r.trip.route }}<template v-if="r.comment"> · {{ r.comment }}</template></div></div>
                            <Stars :value="r.stars" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="open" :title="t('cp.newComplaint')" @close="open = false">
            <form id="fb-form" @submit.prevent="send">
                <Field :label="t('cp.aboutTrip')" :hint="t('common.optional')" :error="form.errors.trip_id">
                    <select v-model="form.trip_id"><option value="">{{ t('cp.general') }}</option><option v-for="x in trips" :key="x.id" :value="x.id">{{ x.number }}</option></select>
                </Field>
                <Field :label="t('cp.subject')" :error="form.errors.subject"><input v-model="form.subject" maxlength="150" required></Field>
                <Field :label="t('cp.details')" :error="form.errors.body"><textarea v-model="form.body" rows="4" maxlength="2000" required /></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="open = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="fb-form" class="btn btn-cu" :disabled="form.processing">{{ t('cp.send') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
