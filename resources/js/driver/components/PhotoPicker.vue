<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: photo picker (camera)
  Location: resources/js/driver/components/PhotoPicker.vue

  Opens the phone camera (or the gallery), shrinks the photo and
  saves it on the phone at once — works with no signal. The parent
  gets the photo's id with v-model; the photo uploads later by itself.
  Retaking replaces the previous photo. A photo that was taken
  more than a few minutes ago (an old one from the gallery) is refused.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import Icon from '@/Components/AppIcon.vue';
import { isFreshPhoto, photoUrl, removePhoto, savePhoto } from '../photos';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    modelValue: { type: String, default: null },
    kind: { type: String, required: true },
    label: { type: String, required: true },
    required: Boolean,
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const input = ref(null);
const url = ref(null);
const busy = ref(false);
const tooOld = ref(false);

async function show(uuid) {
    if (url.value) URL.revokeObjectURL(url.value);
    url.value = await photoUrl(uuid);
}

watch(() => props.modelValue, show, { immediate: true });
onBeforeUnmount(() => url.value && URL.revokeObjectURL(url.value));

async function picked(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    // An old picture from the gallery (an earlier receipt) is not accepted: take it again with the camera.
    tooOld.value = !isFreshPhoto(file);
    if (tooOld.value) return;

    busy.value = true;
    try {
        const old = props.modelValue;
        emit('update:modelValue', await savePhoto(file, props.kind));
        await removePhoto(old);
    } finally {
        busy.value = false;
    }
}

async function clear() {
    await removePhoto(props.modelValue);
    emit('update:modelValue', null);
}
</script>

<template>
    <div class="fld">
        <span class="fl">{{ label }} <small v-if="required" class="rd">*</small></span>
        <input ref="input" type="file" accept="image/*" capture="environment" hidden @change="picked">

        <div v-if="url" style="position:relative">
            <img :src="url" alt="" style="width:100%;max-height:220px;object-fit:cover;border-radius:12px;border:1px solid var(--line)">
            <div style="display:flex;gap:8px;margin-top:8px">
                <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="input.click()"><Icon name="camera" /> {{ t('driver.retake') }}</button>
                <button type="button" class="btn btn-rd" @click="clear"><Icon name="trash" /></button>
            </div>
        </div>
        <button v-else type="button" class="p-act" style="grid-template-columns:none;width:100%;padding:18px 10px" :disabled="busy" @click="input.click()">
            <span class="ic" style="--a:var(--cu);--a-dim:var(--cu-dim)"><Icon name="camera" /></span>
            {{ busy ? t('driver.savingPhoto') : t('driver.takePhoto') }}
        </button>
        <span v-if="tooOld" class="err">{{ t('driver.photoTooOld') }}</span>
        <span v-else-if="error" class="err">{{ error }}</span>
    </div>
</template>
