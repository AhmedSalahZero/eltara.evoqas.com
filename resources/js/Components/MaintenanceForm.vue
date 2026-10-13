<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Maintenance job form
  Location: resources/js/Components/MaintenanceForm.vue

  The fields of one maintenance job. Periodic work shows the km and
  day intervals; a breakdown hides them and clears them. A breakdown,
  and the item "other", require a note.
  The parent owns the form object (Inertia useForm) and saves it.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, watch } from 'vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    form: { type: Object, required: true },
    vehicles: { type: Array, default: () => [] },
    workshop: { type: Boolean, default: false },
    stores: { type: Array, default: () => [] },
    stock: { type: Array, default: () => [] },
});

const { t } = useI18n();

const periodic = computed(() => props.form.kind === 'periodic');
const noteRequired = computed(() => props.form.kind === 'emergency' || props.form.category === 'other');
const chosen = computed(() => props.vehicles.find((v) => v.id === Number(props.form.vehicle_id)));
const own = computed(() => props.workshop && props.form.source === 'own');
const stockHere = computed(() => props.stock.map((item) => ({
    ...item,
    qty: (item.places || []).find((p) => p.store_id === Number(props.form.store_id))?.qty ?? 0,
})));

function addLine() {
    if (!props.form.lines) props.form.lines = [];
    props.form.lines.push({ item_id: '', qty: '' });
}
function dropLine(i) {
    props.form.lines.splice(i, 1);
}

watch(() => props.form.source, (source) => {
    if (source === 'own') {
        props.form.cost = '';
        props.form.workshop = '';
        if (!props.form.lines.length) props.form.lines.push({ item_id: '', qty: '' });
    } else {
        props.form.lines = [];
    }
});

watch(() => props.form.kind, (kind) => {
    if (kind === 'emergency') {
        props.form.interval_km = '';
        props.form.interval_days = '';
    }
});

watch(() => props.form.status, (status) => {
    if (status === 'open') {
        props.form.completed_at = '';
    } else if (!props.form.completed_at) {
        props.form.completed_at = props.form.opened_at || '';
    }
});
</script>

<template>
    <div class="fgrid">
        <Field v-if="workshop" class="w" :label="t('maintenance.f.source')" :error="form.errors.source">
            <select v-model="form.source">
                <option value="external">{{ t('maintenance.sources.external') }}</option>
                <option value="own">{{ t('maintenance.sources.own') }}</option>
            </select>
        </Field>
        <Field :label="t('maintenance.f.truck')" :error="form.errors.vehicle_id">
            <select v-model="form.vehicle_id" data-testid="vehicle" required>
                <option value="" disabled>{{ t('common.choose') }}</option>
                <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.number }} {{ v.letters }}</option>
            </select>
        </Field>
        <Field :label="t('maintenance.f.kind')" :error="form.errors.kind">
            <select v-model="form.kind" data-testid="kind" required>
                <option value="periodic">{{ t('maintenance.kinds.periodic') }}</option>
                <option value="emergency">{{ t('maintenance.kinds.emergency') }}</option>
            </select>
        </Field>
        <Field :label="t('maintenance.f.category')" :error="form.errors.category">
            <select v-model="form.category" data-testid="category" required>
                <option value="oil">{{ t('maintenance.categories.oil') }}</option>
                <option value="tyres">{{ t('maintenance.categories.tyres') }}</option>
                <option value="filters">{{ t('maintenance.categories.filters') }}</option>
                <option value="other">{{ t('maintenance.categories.other') }}</option>
            </select>
        </Field>
        <Field :label="t('maintenance.f.status')" :error="form.errors.status">
            <select v-model="form.status" data-testid="status" required>
                <option value="open">{{ t('maintenance.statuses.open') }}</option>
                <option value="done">{{ t('maintenance.statuses.done') }}</option>
            </select>
        </Field>
        <Field :label="t('maintenance.f.opened')" :error="form.errors.opened_at">
            <input v-model="form.opened_at" data-testid="opened" type="datetime-local" dir="ltr" required>
        </Field>
        <Field v-if="form.status === 'done'" :label="t('maintenance.f.completed')" :error="form.errors.completed_at">
            <input v-model="form.completed_at" data-testid="completed" type="datetime-local" dir="ltr" required>
        </Field>
        <Field :label="t('maintenance.f.odometer')" :hint="chosen && chosen.odometer != null ? t('maintenance.f.lastOdo', { n: chosen.odometer }) : t('common.optional')" :error="form.errors.odometer_km">
            <input v-model="form.odometer_km" type="number" min="0" step="1" dir="ltr">
        </Field>
        <Field v-if="!own" :label="t('maintenance.f.cost')" :hint="form.status === 'open' ? t('common.optional') : ''" :error="form.errors.cost">
            <input v-model="form.cost" type="number" min="0" step="0.01" dir="ltr" :required="form.status === 'done'">
        </Field>
        <Field v-if="!own" :label="t('maintenance.f.workshop')" :hint="t('common.optional')" :error="form.errors.workshop">
            <input v-model="form.workshop" maxlength="120">
        </Field>
        <template v-if="own">
            <Field :label="t('maintenance.f.store')" :error="form.errors.store_id">
                <select v-model="form.store_id" required>
                    <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </Field>
            <p class="xs mu w" style="margin:0">{{ t('maintenance.fromStock') }}</p>
            <div v-for="(line, i) in form.lines" :key="i" class="fgrid w" style="margin-top:0">
                <Field :label="t('maintenance.f.item')" :error="form.errors['lines.' + i + '.item_id']">
                    <select v-model="line.item_id" required>
                        <option value="" disabled>{{ t('common.choose') }}</option>
                        <option v-for="item in stockHere" :key="item.id" :value="item.id">{{ item.name }} ({{ item.qty }} {{ t('stock.units.' + item.unit) }})</option>
                    </select>
                </Field>
                <Field :label="t('maintenance.f.qty')" :error="form.errors['lines.' + i + '.qty']">
                    <span style="display:flex;gap:6px">
                        <input v-model="line.qty" type="number" min="0.001" step="any" dir="ltr" required>
                        <button type="button" class="ib" :title="t('common.delete')" @click="dropLine(i)"><AppIcon name="x" /></button>
                    </span>
                </Field>
            </div>
            <button type="button" class="btn btn-ln btn-sm w" style="justify-self:start" @click="addLine"><AppIcon name="plus" /> {{ t('maintenance.addLine') }}</button>
        </template>
        <template v-if="periodic">
            <Field :label="t('maintenance.f.intervalKm')" :hint="t('maintenance.intervalHint')" :error="form.errors.interval_km">
                <input v-model="form.interval_km" data-testid="interval-km" type="number" min="1" step="1" dir="ltr">
            </Field>
            <Field :label="t('maintenance.f.intervalDays')" :error="form.errors.interval_days">
                <input v-model="form.interval_days" data-testid="interval-days" type="number" min="1" step="1" dir="ltr">
            </Field>
        </template>
        <Field class="w" :label="t('maintenance.f.note')" :hint="noteRequired ? t('maintenance.noteRequired') : t('common.optional')" :error="form.errors.note">
            <input v-model="form.note" data-testid="note" maxlength="250" :required="noteRequired">
        </Field>
    </div>
</template>
