<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — NameSelect (a list of names, with Add above it)
  Location: resources/js/Components/NameSelect.vue

  The value is the name itself (not an id). A + sits on the label
  line, so the select stays level with the fields beside it. The
  little form for a new name floats over the select and does not
  push it down. Saving (POST add-url) puts the name in the list and
  selects it. `extra` is sent with the name (route places send
  their language).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    addUrl: { type: String, required: true },
    extra: { type: Object, default: () => ({}) },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    canAdd: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue', 'added']);
const { t } = useI18n();

const added = ref([]);
const items = computed(() => [...new Set([...props.options, ...added.value].filter((name) => name !== '' && name != null))]);

const adding = ref(false);
const name = ref('');
const problem = ref('');
const busy = ref(false);

function openAdd() {
    name.value = '';
    problem.value = '';
    adding.value = true;
}

async function add() {
    if (busy.value) return;
    if (!name.value.trim()) {
        problem.value = t('common.nameRequired');
        return;
    }
    busy.value = true;
    problem.value = '';
    try {
        const { data } = await axios.post(props.addUrl, { name: name.value.trim(), ...props.extra }, { headers: { Accept: 'application/json' } });
        if (!data?.name) {
            problem.value = t('common.somethingWrong');
            return;
        }
        added.value.push(data.name);
        emit('update:modelValue', data.name);
        emit('added', data);
        adding.value = false;
    } catch (e) {
        problem.value = e.response?.data?.errors?.name?.[0] ?? e.response?.data?.message ?? t('common.somethingWrong');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="fld name-select">
        <span v-if="label || canAdd" class="fl">
            <span>{{ label }}</span>
            <button v-if="canAdd" type="button" class="name-plus" data-testid="add-name" :aria-label="t('common.addNew')" :title="t('common.addNew')" @click="openAdd">
                <AppIcon name="plus" :size="14" />
            </button>
        </span>
        <select data-testid="name-select" :value="modelValue" :required="required" @change="emit('update:modelValue', $event.target.value)">
            <option value="">{{ placeholder || t('common.choose') }}</option>
            <option v-for="item in items" :key="item" :value="item">{{ item }}</option>
        </select>
        <div v-if="adding" class="name-pop">
            <input v-model="name" data-testid="new-name" maxlength="80" :placeholder="t('common.addNew')" autofocus @keydown.enter.prevent="add" @keydown.esc="adding = false">
            <span v-if="problem" class="err" role="alert">{{ problem }}</span>
            <div class="name-pop-a">
                <button type="button" class="btn btn-cu btn-sm" data-testid="confirm-name" :disabled="busy" @click="add"><AppIcon name="check" /> {{ t('common.add') }}</button>
                <button type="button" class="btn btn-ln btn-sm" @click="adding = false">{{ t('common.cancel') }}</button>
            </div>
        </div>
        <span v-if="error" class="err" role="alert">{{ error }}</span>
    </div>
</template>
