<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — ListSelect (a selector where the user can add a new item)
  Location: resources/js/Components/ListSelect.vue

  Used for "Truck type" and "Cargo / goods". Shows the list; under it
  a "+ Add new" link opens a tiny form (Arabic name, English name).
  The new item is saved at once (POST to add-url), appears in the list
  and is selected — the form the user is filling stays open.

  <ListSelect v-model="form.vehicle_type_id" :label="t('veh.type')"
              :options="options.types" :add-url="route('office.vehicle_types.store')"
              :error="form.errors.vehicle_type_id" required />
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    options: { type: Array, default: () => [] },        // [{ id, name }]
    addUrl: { type: String, required: true },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    canAdd: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const added = ref([]);
const items = computed(() => [...props.options, ...added.value.filter((a) => !props.options.some((o) => o.id === a.id))]);

const adding = ref(false);
const nameAr = ref('');
const nameEn = ref('');
const problem = ref('');
const busy = ref(false);

function openAdd() {
    nameAr.value = '';
    nameEn.value = '';
    problem.value = '';
    adding.value = true;
}

async function add() {
    if (busy.value) return;
    if (!nameAr.value.trim()) {
        problem.value = t('common.nameArRequired');
        return;
    }
    busy.value = true;
    problem.value = '';
    try {
        const { data } = await axios.post(props.addUrl, { name_ar: nameAr.value, name_en: nameEn.value }, { headers: { Accept: 'application/json' } });
        if (!data?.id) { // e.g. the same form sent twice within a few seconds is ignored by the server
            problem.value = t('common.somethingWrong');
            return;
        }
        added.value.push(data);
        emit('update:modelValue', data.id);
        adding.value = false;
    } catch (e) {
        problem.value = e.response?.data?.errors?.name_ar?.[0] ?? e.response?.data?.message ?? t('common.somethingWrong');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="fld">
        <span v-if="label" class="fl">{{ label }}</span>
        <select :value="modelValue" :required="required" :disabled="disabled" @change="emit('update:modelValue', $event.target.value === '' ? '' : Number($event.target.value))">
            <option value="">{{ placeholder || t('common.choose') }}</option>
            <option v-for="o in items" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>
        <span v-if="error" class="err" role="alert">{{ error }}</span>

        <button v-if="canAdd && !disabled && !adding" type="button" class="btn btn-ln btn-sm" style="align-self:flex-start;margin-top:6px" @click="openAdd">
            <AppIcon name="plus" /> {{ t('common.addNew') }}
        </button>

        <div v-if="adding" class="note" style="margin-top:8px;display:block">
            <div class="fgrid" style="margin:0">
                <label class="fld"><span class="fl">{{ t('cust.nameAr') }}</span>
                    <input v-model="nameAr" maxlength="80" autofocus @keydown.enter.prevent="add">
                </label>
                <label class="fld"><span class="fl">{{ t('cust.nameEn') }}</span>
                    <input v-model="nameEn" maxlength="80" dir="ltr" @keydown.enter.prevent="add">
                </label>
            </div>
            <span v-if="problem" class="err" role="alert">{{ problem }}</span>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button type="button" class="btn btn-cu btn-sm" :disabled="busy" @click="add"><AppIcon name="check" /> {{ t('common.add') }}</button>
                <button type="button" class="btn btn-ln btn-sm" @click="adding = false">{{ t('common.cancel') }}</button>
            </div>
        </div>
    </div>
</template>
