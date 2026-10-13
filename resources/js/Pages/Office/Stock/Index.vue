<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Workshop store ( /office/stock )
  Location: resources/js/Pages/Office/Stock/Index.vue

  What is on hand, receipts in, and issues out to a truck.
  An issue is created by a company-workshop maintenance job.
  Server: App\Http\Controllers\Office\StockController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { date, money, nowInput, num } from '@/Utils/format';

const props = defineProps({
    store: [Number, null], stores: Array, items: Array, balances: Array, moves: Array, totals: Object,
});
const { t } = useI18n();
const { can } = usePermissions();

const go = (store) => router.get(route('office.stock.index'), { store: store || undefined }, { preserveScroll: true, preserveState: false });

const which = ref(null);
const storeForm = useForm({ name: '' });
const itemForm = useForm({ name: '', unit: 'piece' });
const receipt = useForm({ store_id: props.store ?? '', stock_item_id: '', qty: '', unit_price: '', supplier: '', occurred_at: nowInput() });

function open(kind) {
    which.value = kind;
    storeForm.reset();
    itemForm.reset();
    receipt.reset();
    receipt.store_id = props.store ?? '';
    receipt.occurred_at = nowInput();
    storeForm.clearErrors();
    itemForm.clearErrors();
    receipt.clearErrors();
}

function saveStore() {
    storeForm.post(route('office.stock.stores.store'), { preserveScroll: true, onSuccess: () => (which.value = null) });
}
function saveItem() {
    itemForm.post(route('office.stock.items.store'), { preserveScroll: true, onSuccess: () => (which.value = null) });
}
function saveReceipt() {
    receipt.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])))
        .post(route('office.stock.receipts.store'), { preserveScroll: true, onSuccess: () => (which.value = null) });
}
</script>

<template>
    <PortalLayout :title="t('stock.title')">
        <PageHeader :title="t('stock.title')" :sub="t('stock.sub')">
            <select v-if="stores.length" class="sel" :value="store" @change="go($event.target.value)">
                <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <button v-if="can('maintenance.create')" class="btn btn-ln" @click="open('store')"><AppIcon name="plus" /> {{ t('stock.addStore') }}</button>
            <button v-if="can('maintenance.create')" class="btn btn-ln" @click="open('item')"><AppIcon name="plus" /> {{ t('stock.addItem') }}</button>
            <button v-if="can('maintenance.create') && stores.length" class="btn btn-cu" @click="open('receipt')"><AppIcon name="plus" /> {{ t('stock.receipt') }}</button>
        </PageHeader>

        <div class="g2">
            <Kpi :label="t('stock.value')" :value="money(totals.value)" unit="EGP" color="var(--am)" />
            <Kpi :label="t('stock.onHand')" :value="num(balances.length)" color="var(--bl)" />
        </div>

        <div class="tw" style="margin-top:18px">
            <table>
                <thead><tr>
                    <th>{{ t('stock.cols.item') }}</th><th>{{ t('stock.cols.unit') }}</th>
                    <th class="e">{{ t('stock.cols.qty') }}</th><th class="e">{{ t('stock.cols.avg') }}</th><th class="e">{{ t('stock.cols.value') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-if="!balances.length"><td colspan="5"><div class="empty" style="border:0">{{ t('stock.none') }}</div></td></tr>
                    <tr v-for="b in balances" :key="b.id">
                        <td>{{ b.name }}</td>
                        <td>{{ t('stock.units.' + b.unit) }}</td>
                        <td class="e num">{{ num(b.qty, 3) }}</td>
                        <td class="e num">{{ money(b.average) }}</td>
                        <td class="e num"><b>{{ money(b.value) }}</b></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="sec" style="margin-top:18px">{{ t('stock.movements') }}</div>
        <div class="tw">
            <table>
                <thead><tr>
                    <th>{{ t('stock.cols.when') }}</th><th>{{ t('stock.cols.direction') }}</th><th>{{ t('stock.cols.item') }}</th>
                    <th class="e">{{ t('stock.cols.qty') }}</th><th class="e">{{ t('stock.cols.amount') }}</th><th>{{ t('stock.cols.supplier') }}</th><th>{{ t('stock.cols.truck') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-if="!moves.length"><td colspan="7"><div class="empty" style="border:0">{{ t('stock.noneMoves') }}</div></td></tr>
                    <tr v-for="m in moves" :key="m.id">
                        <td class="num sm">{{ date(m.at) }}</td>
                        <td><span class="bd nodot" :class="m.direction === 'in' ? 'gn' : 'am'">{{ t('stock.dir.' + m.direction) }}</span></td>
                        <td>{{ m.item }}</td>
                        <td class="e num">{{ num(m.qty, 3) }}</td>
                        <td class="e num">{{ money(m.amount) }}</td>
                        <td>{{ m.supplier || '—' }}</td>
                        <td>{{ m.truck || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="which === 'store'" :title="t('stock.addStore')" @close="which = null">
            <form id="store-form" @submit.prevent="saveStore">
                <Field :label="t('stock.f.name')" :error="storeForm.errors.name"><input v-model="storeForm.name" required maxlength="80"></Field>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="which = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="store-form" class="btn btn-cu" :disabled="storeForm.processing">{{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="which === 'item'" :title="t('stock.addItem')" @close="which = null">
            <form id="item-form" @submit.prevent="saveItem">
                <div class="fgrid">
                    <Field :label="t('stock.f.name')" :error="itemForm.errors.name"><input v-model="itemForm.name" required maxlength="80"></Field>
                    <Field :label="t('stock.f.unit')" :error="itemForm.errors.unit">
                        <select v-model="itemForm.unit"><option v-for="u in ['litre', 'piece', 'kg']" :key="u" :value="u">{{ t('stock.units.' + u) }}</option></select>
                    </Field>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="which = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="item-form" class="btn btn-cu" :disabled="itemForm.processing">{{ t('common.save') }}</button>
            </template>
        </Modal>

        <Modal :show="which === 'receipt'" :title="t('stock.receipt')" @close="which = null">
            <form id="receipt-form" @submit.prevent="saveReceipt">
                <div class="fgrid">
                    <Field :label="t('stock.f.store')" :error="receipt.errors.store_id">
                        <select v-model="receipt.store_id" required><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </Field>
                    <Field :label="t('stock.f.item')" :error="receipt.errors.stock_item_id">
                        <select v-model="receipt.stock_item_id" required>
                            <option value="" disabled>{{ t('common.choose') }}</option>
                            <option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }}</option>
                        </select>
                    </Field>
                    <Field :label="t('stock.f.qty')" :error="receipt.errors.qty"><input v-model="receipt.qty" type="number" min="0.001" step="any" dir="ltr" required></Field>
                    <Field :label="t('stock.f.price')" :error="receipt.errors.unit_price"><input v-model="receipt.unit_price" type="number" min="0" step="0.01" dir="ltr" required></Field>
                    <Field :label="t('stock.f.supplier')" :error="receipt.errors.supplier"><input v-model="receipt.supplier" maxlength="120"></Field>
                    <Field :label="t('stock.f.when')" :error="receipt.errors.occurred_at"><input v-model="receipt.occurred_at" type="datetime-local" dir="ltr"></Field>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="which = null">{{ t('common.cancel') }}</button>
                <button type="submit" form="receipt-form" class="btn btn-cu" :disabled="receipt.processing">{{ t('common.save') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
