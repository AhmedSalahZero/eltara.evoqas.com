<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Request a trip ( /client/requests/new )
  Location: resources/js/Pages/Client/Requests/Create.vue

  Scope §7 "Request a trip": the place (only from his own agreed
  prices), the loading date and time, then LINES — each line is a
  number of trucks and a weight ("4 trucks of 5 Ton", "2 trucks of
  1 Ton"), cargo, notes, and a summary with the expected total
  (price per weight × trucks) before sending.
  Server: App\Http\Controllers\Client\RequestController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { money, todayIso } from '@/Utils/format';

const props = defineProps({ places: Array, cargo: Array, preset: Number });
const { t } = useI18n();

// Tomorrow 08:00, company time.
const soonest = () => `${todayIso(1)}T08:00`;

// A shortcut from "My agreed prices" opens with that place and weight already chosen.
const presetPlace = props.places.find((p) => p.options.some((o) => o.route_id === props.preset));

const form = useForm({
    place: presetPlace?.key ?? '',
    lines: [{ trip_route_id: presetPlace ? props.preset : '', trucks_count: 1 }],
    loading_at: soonest(),
    cargo_type_id: '',
    notes: '',
});

const place = computed(() => props.places.find((p) => p.key === form.place));
const optionOf = (id) => place.value?.options.find((o) => o.route_id === Number(id));
const weightText = (o) => o.label || t('cp.standardWeight');

// Changing the place clears the weights (they belong to that place); a place with one weight picks it.
watch(() => form.place, () => {
    const only = place.value?.options.length === 1 ? place.value.options[0].route_id : '';
    form.lines = [{ trip_route_id: only, trucks_count: 1 }];
});

const addLine = () => form.lines.push({ trip_route_id: '', trucks_count: 1 });
const removeLine = (i) => form.lines.splice(i, 1);

const rows = computed(() => form.lines.map((l) => {
    const o = optionOf(l.trip_route_id);
    const n = Math.max(0, Number(l.trucks_count) || 0);
    return { o, n, sub: o ? o.price * n : 0 };
}));
const totalTrucks = computed(() => rows.value.reduce((s, r) => s + (r.o ? r.n : 0), 0));
const total = computed(() => rows.value.reduce((s, r) => s + r.sub, 0));
const ready = computed(() => place.value && rows.value.length && rows.value.every((r) => r.o && r.n >= 1));

const submit = () => form
    .transform((d) => ({ lines: d.lines.map((l) => ({ trip_route_id: l.trip_route_id, trucks_count: l.trucks_count })), loading_at: d.loading_at, cargo_type_id: d.cargo_type_id || null, notes: d.notes }))
    .post(route('client.requests.store'));
</script>

<template>
    <PortalLayout :title="t('nav.clientNew')">
        <PageHeader :title="t('nav.clientNew')" :sub="t('cp.newSub')" />

        <div v-if="!places.length" class="note am"><AppIcon name="info" /><div>{{ t('cp.noPrices') }}</div></div>

        <form v-else class="g-73" style="align-items:start" @submit.prevent="submit">
            <div class="pn">
                <div class="fgrid">
                    <Field :label="t('cp.place')" :error="form.errors.lines">
                        <select v-model="form.place" required>
                            <option value="" disabled>{{ t('common.choose') }}</option>
                            <option v-for="p in places" :key="p.key" :value="p.key">{{ p.name }}</option>
                        </select>
                    </Field>
                    <Field :label="t('cp.loadingAt')" :error="form.errors.loading_at"><input v-model="form.loading_at" type="datetime-local" dir="ltr" required></Field>
                </div>

                <template v-if="place">
                    <div class="pn-h" style="margin-top:14px"><div><h3><AppIcon name="truck" />{{ t('cp.linesTitle') }}</h3><div class="s">{{ t('cp.linesHint') }}</div></div></div>
                    <div v-for="(l, i) in form.lines" :key="i" class="fgrid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) auto;align-items:end;margin-bottom:8px">
                        <Field :label="t('cp.trucksN')" :error="form.errors[`lines.${i}.trucks_count`]"><input v-model="l.trucks_count" type="number" min="1" max="50" dir="ltr" required></Field>
                        <Field :label="t('cp.weight2')" :error="form.errors[`lines.${i}.trip_route_id`]">
                            <select v-model="l.trip_route_id" required>
                                <option value="" disabled>{{ t('common.choose') }}</option>
                                <option v-for="o in place.options" :key="o.route_id" :value="o.route_id">{{ weightText(o) }} · {{ money(o.price) }} EGP</option>
                            </select>
                        </Field>
                        <button v-if="form.lines.length > 1" type="button" class="btn btn-ln btn-sm" style="margin-bottom:6px" :aria-label="t('common.delete')" @click="removeLine(i)"><AppIcon name="x" /></button>
                    </div>
                    <button type="button" class="btn btn-ln btn-sm" @click="addLine"><AppIcon name="plus" /> {{ t('cp.addLine') }}</button>
                </template>

                <div class="fgrid" style="margin-top:14px">
                    <Field class="w" :label="t('cp.cargo')" :hint="t('common.optional')" :error="form.errors.cargo_type_id">
                        <select v-model="form.cargo_type_id"><option value="">—</option><option v-for="c in cargo" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    </Field>
                    <Field class="w" :label="t('cp.notes')" :hint="t('common.optional')" :error="form.errors.notes"><textarea v-model="form.notes" rows="3" maxlength="1000" /></Field>
                </div>
            </div>

            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="receipt" />{{ t('cp.summary') }}</h3></div></div>
                <div class="kv"><span>{{ t('cp.place') }}</span><b>{{ place?.name ?? '—' }}</b></div>
                <div v-for="(r, i) in rows" :key="i" class="kv" v-show="r.o">
                    <span class="num">{{ r.n }} × {{ r.o ? weightText(r.o) : '' }}</span><b class="num">{{ money(r.sub) }} EGP</b>
                </div>
                <div class="kv"><span>{{ t('cp.trucksTotal') }}</span><b class="num">{{ totalTrucks }}</b></div>
                <div class="kv"><span>{{ t('cp.expectedTotal') }}</span><b class="num" style="font-size:18px">{{ ready ? money(total) + ' EGP' : '—' }}</b></div>
                <p class="xs mu">{{ t('cp.summaryNote') }}</p>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button class="btn btn-cu" :disabled="form.processing || !ready"><AppIcon name="arrow" /> {{ t('cp.send') }}</button>
                    <Link :href="route('client.requests.index')" class="btn btn-ln">{{ t('common.cancel') }}</Link>
                </div>
            </div>
        </form>
    </PortalLayout>
</template>
