<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: add expense
  Location: resources/js/driver/screens/AddExpense.vue

  Scope §8.2 "Add expense": pick the category, type the amount, say
  which wallet paid (custody / client money / own pocket), a note and
  the receipt photo. Saved on the phone at once; uploads by itself.
  "Personal" spending is not a trip cost — the office treats it as an
  advance. The receipt photo is required when the company says so.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import PhotoPicker from '../components/PhotoPicker.vue';
import { pick } from '../labels';
import { act, state, view } from '../store';
import { money } from '@/Utils/format';
import { useI18n } from '@/lang/i18n';

const { t, isAr } = useI18n();
const route = useRoute();
const router = useRouter();

const trip = computed(() => view.value.trips.find((x) => x.id === Number(route.params.id)));
const categories = computed(() => state.snapshot?.categories ?? []);
const photoRequired = computed(() => !!state.snapshot?.settings?.receipt_photo_required && !form.personal);

const form = reactive({ category: null, personal: false, amount: '', paid_from: 'custody', note: '', photo: null });
const errors = reactive({});
const saving = ref(false);

const icons = { fuel: 'fuel', toll: 'toll', weigh: 'scale', allow: 'bed', labor: 'users', repair: 'wrench', fine: 'ticket' };
const iconFor = (c) => (['fuel', 'wrench', 'toll', 'scale', 'bed', 'users', 'ticket', 'box', 'tag', 'receipt'].includes(c.icon) ? c.icon : icons[c.code] || 'tag');

const available = computed(() => ({ custody: trip.value?.wallets.custody ?? 0, collections: trip.value?.wallets.collections ?? 0 }));
/** The route's standard for the chosen category, what is already spent on it, and whether this amount passes the company's over-budget %. */
const usual = computed(() => {
    const row = trip.value?.custody.budget.find((b) => b.category_id === form.category);
    if (!row || form.personal) return null;
    const spent = trip.value.expenses.filter((e) => e.category_id === form.category && !e.is_personal).reduce((sum, e) => sum + e.amount, 0);
    const limit = row.amount * (1 + (state.snapshot?.settings?.over_budget_percent ?? 15) / 100);

    return { standard: row.amount, spent, above: spent + Number(form.amount || 0) > limit + 0.001 };
});
const over = computed(() => form.paid_from !== 'own_pocket' && Number(form.amount) > available.value[form.paid_from] + 0.001);

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    if (!form.personal && !form.category) errors.category = t('driver.pickCategory');
    if (!(Number(form.amount) > 0)) errors.amount = t('driver.amountPositive');
    if (photoRequired.value && !form.photo) errors.photo = t('driver.photoRequired');
    if (Object.keys(errors).length) return;

    saving.value = true;
    await act('trip.expense', {
        trip_id: trip.value.id,
        expense_category_id: form.personal ? null : form.category,
        is_personal: form.personal,
        paid_from: form.paid_from,
        amount: Number(form.amount),
        note: form.note.trim() || null,
        photo: form.photo,
    }, true);
    router.replace({ name: 'trip', params: { id: trip.value.id } });
}
</script>

<template>
    <div class="p-h">
        <button type="button" class="ib" :aria-label="t('common.back')" @click="router.back()"><Icon name="back" /></button>
        <b>{{ t('driver.addExpense') }}</b>
        <span v-if="trip" class="xs mu num">{{ trip.number }}</span>
    </div>

    <div v-if="!trip" class="empty"><p class="sm" style="margin:0">{{ t('driver.tripGone') }}</p></div>
    <form v-else class="p-card" style="margin-top:0" @submit.prevent="save">
        <div class="fl b sm">{{ t('driver.category') }}</div>
        <div class="chips" style="margin-top:8px">
            <button v-for="c in categories" :key="c.id" type="button" class="chip" :class="{ on: !form.personal && form.category === c.id }" @click="form.category = c.id; form.personal = false">
                <Icon :name="iconFor(c)" :size="14" /> {{ pick(c, isAr) }}
            </button>
            <button type="button" class="chip" :class="{ on: form.personal }" @click="form.personal = !form.personal; form.category = null"><Icon name="person" :size="14" /> {{ t('driver.personal') }}</button>
        </div>
        <span v-if="errors.category" class="err rd xs b">{{ errors.category }}</span>
        <p v-if="form.personal" class="xs mu" style="margin:8px 0 0">{{ t('driver.personalHint') }}</p>

        <label class="fld"><span class="fl">{{ t('driver.amount') }} <small>{{ t('common.egp') }}</small></span>
            <input v-model="form.amount" type="number" inputmode="decimal" step="any" min="0" :class="{ bad: errors.amount }" autocomplete="off">
            <span v-if="errors.amount" class="err">{{ errors.amount }}</span>
            <small v-if="usual">{{ t('driver.usualAmount', { standard: money(usual.standard, 2), spent: money(usual.spent, 2) }) }}</small>
            <small v-if="usual?.above" class="am b">{{ t('driver.aboveUsual') }}</small>
        </label>

        <div class="fld">
            <span class="fl">{{ t('driver.paidFrom') }}</span>
            <div class="p-seg" style="margin:0">
                <button v-for="w in ['custody', 'collections', 'own_pocket']" :key="w" type="button" :class="{ on: form.paid_from === w }" @click="form.paid_from = w">{{ t('driver.from.' + w) }}</button>
            </div>
            <small v-if="form.paid_from !== 'own_pocket'">{{ t('driver.available', { amount: money(available[form.paid_from], 2) }) }}</small>
            <small v-if="over" class="rd b">{{ t('driver.overBalance') }}</small>
            <small v-if="form.paid_from === 'collections'">{{ t('driver.collectionsHint') }}</small>
        </div>

        <label class="fld"><span class="fl">{{ t('driver.note') }}</span><input v-model="form.note" type="text" maxlength="250"></label>

        <PhotoPicker v-model="form.photo" kind="receipt" :label="t('driver.receiptPhoto')" :required="photoRequired" :error="errors.photo" />

        <button type="submit" class="bigbtn" :disabled="saving">{{ t('driver.saveExpense') }}</button>
    </form>
</template>
