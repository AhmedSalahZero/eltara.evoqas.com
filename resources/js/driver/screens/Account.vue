<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: my account
  Location: resources/js/driver/screens/Account.vue
  Profile and licence date, offline status (entries waiting, entries
  the office refused and why), install on the home screen, language,
  dark mode and sign out (Scope §8.2 "My account").
  Signing out wipes the phone's data, so it first uploads what is waiting
  and REFUSES to sign out while any entry is still not on the server. The
  driver can only lose them on purpose (tick the box, then "Sign out anyway").
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import { logout, setPreference, state, syncNow, uploadAllBeforeLeaving } from '../store';
import { useI18n } from '@/lang/i18n';
import { avatarColor, date, time } from '@/Utils/format';
import { computed } from 'vue';

const { t, isAr } = useI18n();
const docs = computed(() => {
    const p = state.snapshot?.profile;
    if (!p) return [];
    return [{ key: 'driverLicence', ...p.licence }, ...Object.entries(p.vehicle?.documents ?? {}).map(([key, d]) => ({ key, ...d }))];
});
const vehicle = computed(() => state.snapshot?.profile?.vehicle);
const router = useRouter();
const confirmOut = ref(false);

async function install() {
    state.installPrompt?.prompt();
    await state.installPrompt?.userChoice;
    state.installPrompt = null;
}

const leaving = ref(false);
const stuck = ref(0); // entries that could not be uploaded, so sign-out is held back
const understood = ref(false);

/** Safe sign-out: upload first; if anything is still not on the server, do NOT sign out. */
async function signOut() {
    leaving.value = true;
    try {
        stuck.value = await uploadAllBeforeLeaving();
        if (stuck.value === 0 && (await logout()) === 0) router.replace({ name: 'login' });
    } finally {
        leaving.value = false;
    }
}

/** The driver chose to lose the entries that never uploaded (only after ticking the box). */
async function signOutAnyway() {
    await logout({ force: true });
    router.replace({ name: 'login' });
}

function cancelOut() {
    confirmOut.value = false;
    stuck.value = 0;
    understood.value = false;
}
</script>

<template>
    <div class="p-h"><b>{{ t('driver.account') }}</b></div>

    <div class="p-card" style="display:flex;gap:12px;align-items:center">
        <span class="av" :style="{ background: avatarColor(state.profile.driver.name), width: '44px', height: '44px', fontSize: '15px' }">{{ state.profile.driver.initials }}</span>
        <div style="flex:1;min-width:0">
            <b>{{ state.profile.driver.name }}</b>
            <div class="xs mu num">{{ state.profile.driver.mobile }}</div>
            <div v-if="state.profile.driver.license_expires_at" class="xs mu">{{ t('driver.licence', { date: date(state.profile.driver.license_expires_at) }) }}</div>
        </div>
    </div>

    <div v-if="vehicle" class="p-card">
        <b class="sm">{{ t('driver.myTruck') }}</b>
        <div class="p-set"><span>{{ t('driver.truck') }}</span><b class="num">{{ vehicle.plate }}</b></div>
        <div v-if="vehicle.type_ar" class="p-set"><span>{{ t('driver.truckType') }}</span><b>{{ isAr ? vehicle.type_ar : vehicle.type_en }}</b></div>
    </div>

    <div v-if="docs.length" class="p-card">
        <b class="sm">{{ t('driver.documents') }}</b>
        <div v-for="d in docs" :key="d.key" class="p-set">
            <span>{{ t('driver.doc.' + d.key) }}</span>
            <b class="num" :class="{ rd: d.state === 'expired', am: d.state === 'soon' }">{{ d.date ? date(d.date) : '—' }}<template v-if="d.state === 'expired'"> · {{ t('driver.docExpired') }}</template><template v-else-if="d.state === 'soon'"> · {{ t('driver.docSoon', { n: d.days }) }}</template></b>
        </div>
    </div>

    <div class="p-card">
        <b class="sm">{{ t('driver.offlineStatus') }}</b>
        <div class="p-set"><span>{{ t('driver.pending') }}</span><b class="num">{{ state.pending }}</b></div>
        <div class="p-set"><span>{{ t('driver.lastSync', { time: state.lastSync ? `${date(state.lastSync)} ${time(state.lastSync)}` : '—' }) }}</span>
            <button type="button" class="btn btn-ln sm" :disabled="!state.online || state.syncing" @click="syncNow"><Icon name="sync" /></button>
        </div>
    </div>

    <button v-if="state.installPrompt" type="button" class="p-banner gn" @click="install">
        <Icon name="download" /><span style="flex:1"><b>{{ t('driver.install') }}</b><br><span class="xs">{{ t('driver.installSub') }}</span></span>
    </button>

    <div class="p-card">
        <div class="p-set"><span>{{ t('common.language') }}</span>
            <div class="p-seg" style="margin:0;width:140px">
                <button type="button" :class="{ on: state.profile.driver.language === 'ar' }" @click="setPreference({ language: 'ar' })">عربي</button>
                <button type="button" :class="{ on: state.profile.driver.language === 'en' }" @click="setPreference({ language: 'en' })">EN</button>
            </div>
        </div>
        <div class="p-set"><span>{{ t('common.dark') }}</span>
            <label class="sw"><input type="checkbox" :checked="state.profile.driver.theme !== 'light'" @change="setPreference({ theme: $event.target.checked ? 'dark' : 'light' })"><i /></label>
        </div>
    </div>

    <button type="button" class="bigbtn" style="background:var(--rd-dim);color:var(--rd);border:1px solid var(--rd-bd)" @click="confirmOut = true">
        {{ t('driver.signOut') }}
    </button>
    <div v-if="confirmOut" class="p-card" style="border-color:var(--rd-bd)">
        <template v-if="stuck > 0">
            <p class="sm rd b" style="margin:0">{{ t('driver.signOutBlocked', { n: stuck }) }}</p>
            <label class="sm" style="display:flex;gap:8px;align-items:flex-start;margin-top:10px">
                <input v-model="understood" type="checkbox" style="margin-top:3px">
                <span>{{ t('driver.signOutLose', { n: stuck }) }}</span>
            </label>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="cancelOut">{{ t('common.cancel') }}</button>
                <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" :disabled="leaving" @click="signOut">{{ t('driver.tryAgain') }}</button>
            </div>
            <button v-if="understood" type="button" class="btn btn-rd" style="width:100%;justify-content:center;margin-top:8px" @click="signOutAnyway">{{ t('driver.signOutAnyway') }}</button>
        </template>
        <template v-else>
            <p class="sm" style="margin:0">{{ state.pending ? t('driver.signOutWarn', { n: state.pending }) : t('driver.signOutConfirm') }}</p>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="button" class="btn btn-ln" style="flex:1;justify-content:center" @click="cancelOut">{{ t('common.cancel') }}</button>
                <button type="button" class="btn btn-rd" style="flex:1;justify-content:center" :disabled="leaving" @click="signOut">{{ leaving ? t('driver.uploadingBeforeOut') : t('driver.signOut') }}</button>
            </div>
        </template>
    </div>
</template>
