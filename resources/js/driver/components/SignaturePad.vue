<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: finger signature
  Location: resources/js/driver/components/SignaturePad.vue

  The driver signs on the screen with his finger (Scope §8.2 "Receive
  custody"). The signature is saved on the phone as a small picture —
  it works with no signal — and the parent gets its photo id with
  v-model, exactly like a photo.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import SignaturePad from 'signature_pad';
import Icon from '@/Components/AppIcon.vue';
import { removePhoto, savePhotoBlob } from '../photos';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ modelValue: { type: String, default: null }, error: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const canvas = ref(null);
let pad = null;

function resize() {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const el = canvas.value;
    el.width = el.offsetWidth * ratio;
    el.height = el.offsetHeight * ratio;
    el.getContext('2d').scale(ratio, ratio);
    pad.clear();
}

async function saved() {
    if (pad.isEmpty()) return;
    const blob = await new Promise((resolve) => canvas.value.toBlob(resolve, 'image/png'));
    const old = props.modelValue;
    emit('update:modelValue', await savePhotoBlob(blob, 'signature'));
    await removePhoto(old);
}

async function clear() {
    pad.clear();
    await removePhoto(props.modelValue);
    emit('update:modelValue', null);
}

onMounted(() => {
    pad = new SignaturePad(canvas.value, { penColor: '#111', backgroundColor: '#fff', minWidth: 1, maxWidth: 2.6 });
    pad.addEventListener('endStroke', saved);
    resize();
});
onBeforeUnmount(() => pad?.off());
</script>

<template>
    <div class="fld">
        <span class="fl">{{ t('driver.signHere') }} <small class="rd">*</small></span>
        <canvas ref="canvas" style="width:100%;height:170px;border-radius:12px;border:1px solid var(--line);background:#fff;touch-action:none" />
        <div style="display:flex;justify-content:flex-end"><button type="button" class="btn btn-ln sm" @click="clear"><Icon name="trash" /> {{ t('driver.clearSignature') }}</button></div>
        <span v-if="error" class="err">{{ error }}</span>
    </div>
</template>
