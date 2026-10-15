<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Routes & budgets ( /office/routes )
  Location: resources/js/Pages/Office/Routes/Index.vue

  Scope §6.6: each route with its round-trip km and its STANDARD
  BUDGET per expense category. The list shows the standard total,
  the cash road costs (what the driver pays from custody), the
  suggested custody, the cost per km and how many customers have a
  price on it. The form has one box per expense category, and adds
  up the totals as you type.
  Server: App\Http\Controllers\Office\RouteController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import NameSelect from '@/Components/NameSelect.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { money, num } from '@/Utils/format';

const props = defineProps({ routes: Array, categories: Array, buffer: Number, overBudget: { type: Number, default: 15 }, filters: Object, places: Object });
const { t } = useI18n();
const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
watch(search, useDebounceFn(() => router.get(route('office.routes.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 350));

const addedPlaces = ref({ ar: [], en: [] });
const placeOptions = (language) => [...new Set([...(props.places?.[language] ?? []), ...addedPlaces.value[language]])];
const rememberPlace = (place) => {
    if (place?.name && !addedPlaces.value[place.language]?.includes(place.name)) addedPlaces.value[place.language].push(place.name);
};

const open = ref(false);
const editing = ref(null);
const form = useForm({ origin_ar: '', origin_en: '', destination_ar: '', destination_en: '', weight_tons: '', km_round_trip: '', usual_hours: '', wait_hours: '', notes: '', is_active: true, budgets: {} });

function openRoute(r = null) {
    editing.value = r;
    const budgets = {};
    for (const c of props.categories) budgets[c.id] = r?.budgets?.[c.id] ?? '';
    form.defaults({
        origin_ar: r?.origin_ar ?? '', origin_en: r?.origin_en ?? '', destination_ar: r?.destination_ar ?? '', destination_en: r?.destination_en ?? '',
        weight_tons: r?.weight_tons ?? '', km_round_trip: r?.km_round_trip ?? '', usual_hours: r?.usual_hours ?? '', wait_hours: r?.wait_hours ?? '', notes: r?.notes ?? '', is_active: r?.is_active ?? true, budgets,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

// Totals worked out as the office user types (the server works them out again).
const totals = computed(() => {
    let standard = 0, cash = 0;
    for (const c of props.categories) {
        const v = Number(form.budgets[c.id]) || 0;
        standard += v;
        cash += v * c.cash_percent / 100;
    }
    return { standard, cash, custody: cash > 0 ? Math.ceil(cash * (1 + props.buffer / 100) / 500) * 500 : 0, perKm: form.km_round_trip ? standard / form.km_round_trip : null };
});

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.patch(route('office.routes.update', editing.value.id), options) : form.post(route('office.routes.store'), options);
}

const confirming = ref(null);
const remove = () => router.delete(route('office.routes.destroy', confirming.value.id), { preserveScroll: true, onFinish: () => (confirming.value = null) });
const visibleCategories = computed(() => props.categories.filter((c) => c.is_active || Number(form.budgets[c.id]) > 0));
</script>

<template>
    <PortalLayout :title="t('rt.title')">
        <PageHeader :title="t('rt.title')" :sub="t('rt.sub')">
            <button v-if="can('customers.create')" class="btn btn-cu" @click="openRoute()"><AppIcon name="plus" /> {{ t('rt.add') }}</button>
        </PageHeader>

        <div class="fbar">
            <label class="srch"><AppIcon name="search" /><input v-model="search" :placeholder="t('rt.searchPh')"></label>
            <span class="xs mu" style="margin-inline-start:auto">{{ t('rt.custodyRule', { buffer: num(buffer, 0) }) }}</span>
        </div>

        <div class="tw">
            <table>
                <thead>
                    <tr>
                        <th>{{ t('rt.route') }}</th><th class="e">{{ t('rt.km') }}</th><th class="e">{{ t('rt.hours') }}</th><th class="e">{{ t('rt.standard') }}</th>
                        <th class="e">{{ t('rt.cash') }}</th><th class="e" :title="t('rt.bufferTip', { buffer: num(buffer, 0) })">{{ t('rt.buffer') }}</th><th class="e">{{ t('rt.custody') }}</th><th class="e">{{ t('rt.perKm') }}</th><th class="e">{{ t('rt.customers') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!routes.length"><td colspan="9"><div class="empty" style="border:0">{{ filters.search ? t('common.noResults') : t('rt.none') }}</div></td></tr>
                    <tr v-for="r in routes" :key="r.id" :class="{ ck: can('customers.edit') }" :style="r.is_active ? '' : 'opacity:.55'" @click="can('customers.edit') && openRoute(r)">
                        <td><b class="route"><AppIcon name="route" />{{ r.name }}</b> <span v-if="!r.is_active" class="bd pl nodot">{{ t('rt.hidden') }}</span></td>
                        <td class="e num">{{ num(r.km_round_trip) }}</td>
                        <td class="e num">{{ r.usual_hours ? num(r.usual_hours, 0) : '—' }}<span v-if="r.wait_hours" class="xs mu"> + {{ num(r.wait_hours, 0) }}</span></td>
                        <td class="e"><b class="num">{{ money(r.standard) }}</b></td>
                        <td class="e num">{{ money(r.cash) }}</td>
                        <td class="e num mu" :title="t('rt.bufferTip', { buffer: num(buffer, 0) })">+{{ money(Math.max(0, (r.custody || 0) - (r.cash || 0))) }}</td>
                        <td class="e num" style="color:var(--bl)">{{ money(r.custody) }}</td>
                        <td class="e num">{{ r.per_km == null ? '—' : num(r.per_km, 2) }}</td>
                        <td class="e num">{{ r.customers }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="xs mu" style="margin-top:10px">{{ t('rt.flagRule', { n: num(overBudget, 0) }) }} · {{ t('rt.permNote') }}</p>

        <Modal :show="open" wide :title="editing ? t('rt.edit') : t('rt.add')" @close="open = false">
            <form id="route-form" @submit.prevent="save">
                <div class="fgrid">
                    <NameSelect v-model="form.origin_ar" :label="`${t('rt.from')} (${t('rt.ar')})`" :options="placeOptions('ar')" :extra="{ language: 'ar' }"
                                :add-url="route('office.route_places.store')" :can-add="can('customers.create')" :error="form.errors.origin_ar" required @added="rememberPlace" />
                    <NameSelect v-model="form.destination_ar" :label="`${t('rt.to')} (${t('rt.ar')})`" :options="placeOptions('ar')" :extra="{ language: 'ar' }"
                                :add-url="route('office.route_places.store')" :can-add="can('customers.create')" :error="form.errors.destination_ar" required @added="rememberPlace" />
                    <NameSelect v-model="form.origin_en" :label="`${t('rt.from')} (${t('rt.en')})`" :options="placeOptions('en')" :extra="{ language: 'en' }"
                                :add-url="route('office.route_places.store')" :can-add="can('customers.create')" :error="form.errors.origin_en" @added="rememberPlace" />
                    <NameSelect v-model="form.destination_en" :label="`${t('rt.to')} (${t('rt.en')})`" :options="placeOptions('en')" :extra="{ language: 'en' }"
                                :add-url="route('office.route_places.store')" :can-add="can('customers.create')" :error="form.errors.destination_en" @added="rememberPlace" />
                    <Field :label="t('rt.weight')" :hint="t('rt.weightHint')" :error="form.errors.weight_tons"><input v-model="form.weight_tons" type="number" min="0" step="any" dir="ltr" required></Field>
                    <Field :label="t('rt.km')" :error="form.errors.km_round_trip"><input v-model="form.km_round_trip" type="number" min="1" dir="ltr" required></Field>
                    <Field :label="t('rt.hours')" :hint="t('rt.hoursHint')" :error="form.errors.usual_hours"><input v-model="form.usual_hours" type="number" min="0" step="0.5" dir="ltr"></Field>
                    <Field :label="t('rt.waitHours')" :hint="t('rt.waitHoursHint')" :error="form.errors.wait_hours"><input v-model="form.wait_hours" type="number" min="0" step="0.5" dir="ltr"></Field>
                </div>

                <div class="sec" style="margin-top:16px">{{ t('rt.budget') }} <span class="xs mu">· {{ t('rt.budgetHint') }}</span></div>
                <div class="fgrid3">
                    <Field v-for="c in visibleCategories" :key="c.id" :label="c.name" :hint="t('rt.cashShare', { n: c.cash_percent })" :error="form.errors['budgets.' + c.id]">
                        <input v-model="form.budgets[c.id]" type="number" min="0" step="any" dir="ltr">
                    </Field>
                </div>

                <div class="kv" style="margin-top:14px">
                    <div><span>{{ t('rt.standard') }}</span><b class="num">{{ money(totals.standard) }}</b></div>
                    <div><span>{{ t('rt.cash') }}</span><b class="num">{{ money(totals.cash) }}</b></div>
                    <div><span>{{ t('rt.custody') }}</span><b class="num" style="color:var(--bl)">{{ money(totals.custody) }}</b></div>
                    <div><span>{{ t('rt.perKm') }}</span><b class="num">{{ totals.perKm == null ? '—' : num(totals.perKm, 2) }}</b></div>
                </div>

                <Field :label="t('rt.notes')" :error="form.errors.notes"><textarea v-model="form.notes" /></Field>
                <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto">
                    <div><b>{{ t('rt.active') }}</b></div>
                    <label class="sw"><input v-model="form.is_active" type="checkbox"><i /></label>
                </div>
            </form>
            <template #footer>
                <button v-if="editing && can('customers.delete')" type="button" class="btn btn-rd" style="margin-inline-end:auto" @click="open = false; confirming = editing"><AppIcon name="trash" /></button>
                <button type="button" class="btn btn-ln" @click="open = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="route-form" class="btn btn-cu" :disabled="form.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <ConfirmDialog :show="!!confirming" :title="t('common.delete')" danger :confirm-label="t('common.delete')"
                       :message="confirming ? t('rt.deleteConfirm', { name: confirming.name }) : ''" @confirm="remove" @cancel="confirming = null" />
    </PortalLayout>
</template>
