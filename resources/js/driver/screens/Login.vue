<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App: sign in (mobile, then 4-digit PIN)
  Location: resources/js/driver/screens/Login.vue
  The demo's two-step sign-in with big buttons and a number keypad.
  The first sign-in needs signal; the driver then stays signed in on
  the phone, so the app keeps working on the road (Scope §8.2).
  The form sits in a panel at the top so the warehouse picture
  (truck and logo) stays visible below it (App.vue adds the picture).
  Server: POST /driver/api/login (DriverLoginRequest).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import { login } from '../store';
import { ApiError } from '../api';
import { setLocale, useI18n } from '@/lang/i18n';

const { t, locale } = useI18n();
const router = useRouter();

const step = ref('mobile');
const mobile = ref('');
const pin = ref('');
const error = ref('');
const busy = ref(false);

function toPin() {
    error.value = '';
    if (mobile.value.replace(/\D/g, '').length < 10) {
        error.value = t('driver.mobile');
        return;
    }
    step.value = 'pin';
}

async function press(key) {
    error.value = '';
    if (key === 'del') { pin.value = pin.value.slice(0, -1); return; }
    if (pin.value.length >= 4) return;
    pin.value += key;
    if (pin.value.length === 4) await submit();
}

async function submit() {
    busy.value = true;
    try {
        await login(mobile.value, pin.value);
        router.replace({ name: 'home' });
    } catch (e) {
        pin.value = '';
        error.value = e instanceof ApiError
            ? (e.kind === 'offline' ? t('driver.firstSignIn') : Object.values(e.errors).flat()[0] ?? e.message)
            : String(e);
        if (e instanceof ApiError && e.errors.mobile) step.value = 'mobile';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="p-login">
        <div class="drv-login-card">
            <h2>{{ t('driver.signInTitle') }}</h2>

            <div class="seg" style="margin:8px auto 0;width:max-content">
                <button type="button" :aria-pressed="locale === 'ar'" @click="setLocale('ar')">ع</button>
                <button type="button" :aria-pressed="locale === 'en'" @click="setLocale('en')">EN</button>
            </div>

            <template v-if="step === 'mobile'">
                <label class="fld" style="text-align:start">
                    <span class="fl">{{ t('driver.mobile') }}</span>
                    <input v-model="mobile" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="01X XXXX XXXX" style="font-size:20px;text-align:center;padding:12px" @keyup.enter="toPin">
                </label>
                <button type="button" class="bigbtn" @click="toPin">{{ t('driver.next') }}</button>
            </template>

            <template v-else>
                <p class="sm mu" style="margin:10px 0 0">{{ t('driver.enterPin') }} · <span class="num">{{ mobile }}</span></p>
                <div class="pin"><i v-for="n in 4" :key="n" :class="{ on: pin.length >= n }" /></div>
                <div class="keys">
                    <button v-for="k in ['1','2','3','4','5','6','7','8','9']" :key="k" type="button" :disabled="busy" @click="press(k)">{{ k }}</button>
                    <button type="button" style="font-size:13px" @click="step = 'mobile'; pin = ''">{{ t('common.back') }}</button>
                    <button type="button" :disabled="busy" @click="press('0')">0</button>
                    <button type="button" :disabled="busy" @click="press('del')"><Icon name="back" /></button>
                </div>
            </template>

            <div v-if="error" class="note rd" style="text-align:start;margin-top:10px"><Icon name="alert" /><div>{{ error }}</div></div>
            <p class="xs mu" style="margin:10px 0 0">{{ t('driver.firstSignIn') }}</p>
        </div>
    </div>
</template>
