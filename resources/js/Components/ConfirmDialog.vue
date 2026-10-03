<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — ConfirmDialog ("Are you sure?")
  Location: resources/js/Components/ConfirmDialog.vue
  <ConfirmDialog :show="x" :title="…" :message="…" :confirm-label="…" danger
                 @confirm="…" @cancel="x = false" />
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import Modal from './Modal.vue';
import { useI18n } from '@/lang/i18n';

defineProps({
    show: Boolean,
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    danger: Boolean,
    busy: Boolean,
});
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useI18n();
</script>

<template>
    <Modal :show="show" :title="title" @close="emit('cancel')">
        <p class="sm" style="margin:12px 0 0">{{ message }}</p>
        <template #footer>
            <button type="button" class="btn btn-ln" @click="emit('cancel')">{{ t('common.cancel') }}</button>
            <button type="button" class="btn" :class="danger ? 'btn-rd' : 'btn-cu'" :disabled="busy" @click="emit('confirm')">
                {{ confirmLabel || t('common.yes') }}
            </button>
        </template>
    </Modal>
</template>
