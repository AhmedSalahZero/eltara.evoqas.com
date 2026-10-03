<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: signal & upload banner
  Location: resources/js/driver/components/SyncBanner.vue
  Always tells the driver where their entries are (Scope §8.1 "the
  phone shows how many entries are waiting to upload"):
    green  → online, everything uploaded
    amber  → no signal: saved on the phone, uploads by itself
    red    → needs attention (sign in again / subscription ended)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import Icon from '@/Components/AppIcon.vue';
import { state, syncNow } from '../store';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();

const problemText = computed(() => ({
    signedOut: t('driver.sessionEnded'),
    readOnly: t('driver.readOnly'),
    suspended: t('driver.sessionEnded'),
}[state.problem]));
</script>

<template>
    <div v-if="state.problem" class="p-off" style="background:var(--rd-dim);color:var(--rd);border-color:var(--rd-bd)">
        <Icon name="alert" /> <span style="flex:1">{{ problemText }}</span>
        <b v-if="state.pending" class="num">{{ state.pending }}</b>
    </div>
    <div v-else class="p-off" :class="{ 'p-on': state.online }">
        <Icon :name="state.online ? 'wifi' : 'wifioff'" />
        <span style="flex:1">
            {{ state.online ? t('driver.online') : t('driver.offline') }}
            <template v-if="state.pending"> · {{ t('driver.waiting', { n: state.pending }) }}</template>
        </span>
        <button v-if="state.online && state.pending" type="button" class="btn btn-gh sm" :disabled="state.syncing" @click="syncNow">
            <Icon name="sync" /> {{ state.syncing ? t('driver.syncing') : t('driver.syncNow') }}
        </button>
    </div>
</template>
