<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Company settings ( /office/settings )
  Location: resources/js/Pages/Office/Settings/Index.vue

  The demo's four panels (Scope §6.15):
    Wallets & approvals · True profit & month close · Driver app · General
  plus the expense categories (add / rename / cash share / hide),
  the truck types and the cargo (goods) lists (add / rename / hide).
  People with settings.view but not settings.edit see everything
  greyed out. Server: App\Http\Controllers\Office\SettingsController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ settings: Object, categories: Array, icons: Array, vehicle_types: { type: Array, default: () => [] }, cargo_types: { type: Array, default: () => [] } });
const { t, tr } = useI18n();
const { can } = usePermissions();
const locked = computed(() => !can('settings.edit'));

const form = useForm({ ...props.settings, ga_rate_estimate: props.settings.ga_rate_estimate ?? '' });
const save = () => form.transform((d) => ({ ...d, ga_rate_estimate: d.ga_rate_estimate === '' ? null : d.ga_rate_estimate })).put(route('office.settings.update'), { preserveScroll: true });

// ── Expense categories ──────────────────────────────────────────
const catOpen = ref(false);
const editingCat = ref(null);
const catForm = useForm({ name_ar: '', name_en: '', icon: 'receipt', cash_percent: 100, is_active: true });
function openCat(c = null) {
    if (locked.value) return;
    editingCat.value = c;
    catForm.defaults({ name_ar: c?.name_ar ?? '', name_en: c?.name_en ?? '', icon: c?.icon ?? 'receipt', cash_percent: c?.cash_percent ?? 100, is_active: c?.is_active ?? true });
    catForm.reset();
    catForm.clearErrors();
    catOpen.value = true;
}
function saveCat() {
    const options = { preserveScroll: true, onSuccess: () => (catOpen.value = false) };
    editingCat.value ? catForm.patch(route('office.settings.categories.update', editingCat.value.id), options) : catForm.post(route('office.settings.categories.store'), options);
}

// ── Truck types and cargo (goods) ───────────────────────────────
const listOpen = ref(false);
const listKind = ref('vehicle');
const editingItem = ref(null);
const listForm = useForm({ name_ar: '', name_en: '', is_active: true });
function openList(kind, item = null) {
    if (locked.value) return;
    listKind.value = kind;
    editingItem.value = item;
    listForm.defaults({ name_ar: item?.name_ar ?? '', name_en: item?.name_en ?? '', is_active: item?.is_active ?? true });
    listForm.reset();
    listForm.clearErrors();
    listOpen.value = true;
}
function saveList() {
    const base = listKind.value === 'vehicle' ? 'office.vehicle_types' : 'office.cargo_types';
    const options = { preserveScroll: true, onSuccess: () => (listOpen.value = false) };
    editingItem.value ? listForm.patch(route(base + '.update', editingItem.value.id), options) : listForm.post(route(base + '.store'), options);
}
</script>

<template>
    <PortalLayout :title="t('set.title')">
        <PageHeader :title="t('set.title')" :sub="t('set.sub')">
            <button v-if="!locked" class="btn btn-cu" :disabled="form.processing || !form.isDirty" @click="save"><AppIcon name="check" /> {{ t('set.saveAll') }}</button>
        </PageHeader>
        <div v-if="locked" class="note" style="margin-bottom:14px"><AppIcon name="info" /><div>{{ t('set.readOnlyNote') }}</div></div>
        <div v-if="form.isDirty && !locked" class="banner am"><AppIcon name="alert" /> {{ t('users.unsaved') }}</div>

        <fieldset :disabled="locked" style="border:0;padding:0;margin:0;min-width:0">
            <div class="g2">
                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="swap" />{{ t('set.wallets') }}</h3></div></div>
                    <div class="fld"><span class="fl">{{ t('set.policy') }}</span>
                        <div class="opts">
                            <label v-for="k in ['approval', 'limit', 'auto']" :key="k" class="opt">
                                <input v-model="form.default_transfer_policy" type="radio" :value="k">
                                <div><b>{{ tr('set.policies.' + k)[0] }}</b><span>{{ tr('set.policies.' + k)[1] }}</span></div>
                            </label>
                        </div>
                    </div>
                    <div class="fgrid">
                        <Field :label="t('set.autoLimit')" :error="form.errors.auto_transfer_limit"><input v-model="form.auto_transfer_limit" type="number" min="0" step="any" dir="ltr"></Field>
                        <Field :label="t('set.buffer')" :error="form.errors.custody_buffer_percent"><input v-model="form.custody_buffer_percent" type="number" min="0" max="100" step="0.5" dir="ltr"></Field>
                        <Field :label="t('set.overBudget')" :hint="t('set.overBudgetHint')" :error="form.errors.over_budget_percent"><input v-model="form.over_budget_percent" type="number" min="0" max="500" step="1" dir="ltr"></Field>
                        <Field :label="t('set.fuelFlag')" :hint="t('set.fuelFlagHint')" :error="form.errors.fuel_flag_percent"><input v-model="form.fuel_flag_percent" type="number" min="0" max="100" step="0.5" dir="ltr"></Field>
                    </div>
                    <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto;margin-top:8px">
                        <div><b>{{ t('set.personalAdvance') }}</b><span>{{ t('set.personalAdvanceSub') }}</span></div>
                        <label class="sw"><input v-model="form.personal_spend_to_advance" type="checkbox"><i /></label>
                    </div>
                    <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto">
                        <div><b>{{ t('set.closeRule') }}</b><span>{{ t('set.mandatory') }}</span></div>
                        <label class="sw"><input type="checkbox" checked disabled><i /></label>
                    </div>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="lock" />{{ t('set.trueProfit') }}</h3></div></div>
                    <div class="fld"><span class="fl">{{ t('set.split') }}</span>
                        <div class="opts">
                            <label v-for="k in ['hours', 'start', 'delivery']" :key="k" class="opt">
                                <input v-model="form.month_split_rule" type="radio" :value="k">
                                <div><b>{{ tr('set.splits.' + k)[0] }} <span v-if="k === 'hours'" class="bd gn nodot">{{ t('set.recommended') }}</span></b><span>{{ tr('set.splits.' + k)[1] }}</span></div>
                            </label>
                        </div>
                    </div>
                    <Field :label="t('set.gaBasis')" :error="form.errors.ga_basis">
                        <select v-model="form.ga_basis"><option value="own_km">{{ t('set.gaOwn') }}</option><option value="all_km">{{ t('set.gaAll') }}</option></select>
                    </Field>
                    <Field :label="t('set.gaEstimate')" :hint="t('set.gaEstimateHint')" :error="form.errors.ga_rate_estimate">
                        <input v-model="form.ga_rate_estimate" type="number" min="0" step="0.01" dir="ltr">
                    </Field>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="phone" />{{ t('set.driverApp') }}</h3></div></div>
                    <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto"><div><b>{{ t('set.receipt') }}</b></div><label class="sw"><input v-model="form.receipt_photo_required" type="checkbox"><i /></label></div>
                    <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto"><div><b>{{ t('set.location') }}</b><span>{{ t('set.locationSub') }}</span></div><label class="sw"><input v-model="form.capture_location" type="checkbox"><i /></label></div>
                    <div class="doc-row" style="grid-template-columns:minmax(0,1fr) auto"><div><b>{{ t('set.offline') }}</b><span>{{ t('set.offlineSub') }}</span></div><label class="sw"><input v-model="form.offline_mode" type="checkbox"><i /></label></div>
                    <Field :label="t('set.maxSync')" :error="form.errors.max_hours_without_sync">
                        <select v-model.number="form.max_hours_without_sync"><option v-for="h in [6, 12, 24, 48]" :key="h" :value="h">{{ t('set.hoursN', { n: h }) }}</option></select>
                    </Field>
                </div>

                <div class="pn">
                    <div class="pn-h"><div><h3><AppIcon name="settings" />{{ t('set.general') }}</h3></div></div>
                    <div class="fgrid">
                        <Field :label="t('set.language')"><select v-model="form.default_language"><option value="ar">العربية</option><option value="en">English</option></select></Field>
                        <Field :label="t('set.theme')"><select v-model="form.default_theme"><option value="dark">{{ t('common.dark') }}</option><option value="light">{{ t('common.light') }}</option></select></Field>
                        <Field :label="t('set.diesel')" :error="form.errors.diesel_price"><input v-model="form.diesel_price" type="number" min="0" step="0.05" dir="ltr"></Field>
                        <Field :label="t('set.currency')"><select v-model="form.currency"><option value="EGP">{{ t('set.egp') }}</option></select></Field>
                    </div>
                    <div class="fld"><span class="fl">{{ t('set.categories') }}<small>{{ t('set.categoriesSub') }}</small></span>
                        <div class="chips">
                            <button v-for="c in categories" :key="c.id" type="button" class="chip" :style="c.is_active ? '' : 'opacity:.5;text-decoration:line-through'" @click="openCat(c)">
                                <AppIcon :name="c.icon" :size="13" /> {{ c.name }} <span class="c">{{ c.cash_percent }}%</span>
                            </button>
                            <button v-if="!locked" type="button" class="chip" @click="openCat()"><AppIcon name="plus" :size="13" /> {{ t('set.addCategory') }}</button>
                        </div>
                    </div>
                    <div class="fld"><span class="fl">{{ t('set.vehicleTypes') }}<small>{{ t('set.vehicleTypesSub') }}</small></span>
                        <div class="chips">
                            <button v-for="v in vehicle_types" :key="v.id" type="button" class="chip" :style="v.is_active ? '' : 'opacity:.5;text-decoration:line-through'" @click="openList('vehicle', v)">{{ v.name }}</button>
                            <button v-if="!locked" type="button" class="chip" @click="openList('vehicle')"><AppIcon name="plus" :size="13" /> {{ t('set.addType') }}</button>
                        </div>
                    </div>
                    <div class="fld"><span class="fl">{{ t('set.cargoTypes') }}<small>{{ t('set.cargoTypesSub') }}</small></span>
                        <div class="chips">
                            <button v-for="c in cargo_types" :key="c.id" type="button" class="chip" :style="c.is_active ? '' : 'opacity:.5;text-decoration:line-through'" @click="openList('cargo', c)">{{ c.name }}</button>
                            <button v-if="!locked" type="button" class="chip" @click="openList('cargo')"><AppIcon name="plus" :size="13" /> {{ t('set.addCargo') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </fieldset>

        <Modal :show="catOpen" :title="t('set.categoryTitle')" @close="catOpen = false">
            <div v-if="editingCat?.is_system" class="note" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('set.standardCat') }}</div></div>
            <form id="cat-form" @submit.prevent="saveCat">
                <div class="fgrid">
                    <Field :label="t('cust.nameAr')" :error="catForm.errors.name_ar"><input v-model="catForm.name_ar" required></Field>
                    <Field :label="t('cust.nameEn')" :error="catForm.errors.name_en"><input v-model="catForm.name_en" dir="ltr"></Field>
                    <Field :label="t('set.cashPercent')" :hint="t('set.cashHint')" :error="catForm.errors.cash_percent"><input v-model="catForm.cash_percent" type="number" min="0" max="100" dir="ltr" required></Field>
                    <Field :label="t('set.icon')" :error="catForm.errors.icon">
                        <div class="chips">
                            <button v-for="i in icons" :key="i" type="button" class="chip" :class="{ on: catForm.icon === i }" @click="catForm.icon = i"><AppIcon :name="i" :size="15" /></button>
                        </div>
                    </Field>
                </div>
                <div v-if="editingCat" class="doc-row" style="grid-template-columns:minmax(0,1fr) auto;margin-top:8px">
                    <div><b>{{ t('set.visible') }}</b></div>
                    <label class="sw"><input v-model="catForm.is_active" type="checkbox"><i /></label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="catOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="cat-form" class="btn btn-cu" :disabled="catForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="listOpen" :title="listKind === 'vehicle' ? t('set.typeTitle') : t('set.cargoTitle')" @close="listOpen = false">
            <div v-if="listKind === 'vehicle' && editingItem?.is_system" class="note" style="margin-top:12px"><AppIcon name="info" /><div>{{ t('set.standardType') }}</div></div>
            <form id="list-form" @submit.prevent="saveList">
                <div class="fgrid">
                    <Field :label="t('cust.nameAr')" :error="listForm.errors.name_ar"><input v-model="listForm.name_ar" maxlength="80" required></Field>
                    <Field :label="t('cust.nameEn')" :error="listForm.errors.name_en"><input v-model="listForm.name_en" maxlength="80" dir="ltr"></Field>
                </div>
                <div v-if="editingItem" class="doc-row" style="grid-template-columns:minmax(0,1fr) auto;margin-top:8px">
                    <div><b>{{ t('set.visibleType') }}</b></div>
                    <label class="sw"><input v-model="listForm.is_active" type="checkbox"><i /></label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="listOpen = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="list-form" class="btn btn-cu" :disabled="listForm.processing"><AppIcon name="check" /> {{ t('common.save') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
