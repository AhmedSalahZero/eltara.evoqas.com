<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — PinNotice (a driver's new PIN, shown ONCE)
  Location: resources/js/Components/PinNotice.vue
  Opens after "New driver" or "New PIN". The PIN is stored scrambled,
  so this is the only time anyone can see it: the office user notes
  it and gives it to the driver, with the Driver App address.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from './Modal.vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const page = usePage();
const { t } = useI18n();
const pin = ref(null);
const url = `${window.location.origin}/driver`;

watch(() => page.props.flash?.pin, (value) => { if (value) pin.value = value; }, { immediate: true });
</script>

<template>
    <Modal :show="!!pin" :title="pin ? t('drv.pinTitle', { name: pin.name }) : ''" @close="pin = null">
        <div v-if="pin" style="text-align:center;padding:10px 0 4px">
            <div class="num" style="font-size:44px;font-weight:800;letter-spacing:.3em;direction:ltr;color:var(--cu)">{{ pin.pin }}</div>
            <p class="sm" style="margin:10px 0 0">{{ t('drv.pinText') }}</p>
            <p class="sm mu" style="margin:8px 0 0">{{ t('drv.pinSignIn', { url, mobile: pin.mobile }) }}</p>
        </div>
        <template #footer>
            <button type="button" class="btn btn-cu" @click="pin = null"><AppIcon name="check" /> {{ t('drv.pinOk') }}</button>
        </template>
    </Modal>
</template>
