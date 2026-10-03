<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Driver App frame
  Location: resources/js/driver/App.vue

  Full-screen phone layout from the demo: top bar (logo, ع / EN,
  sun / moon), the signal banner (online / offline + how many entries
  wait to upload), the screen, and the bottom menu.
  On the sign-in screen the frame gets the class "drv-login": the
  warehouse picture (public/images/driver-login-bg.jpg) fills the
  screen behind it (styles: resources/css/eltara.css).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Icon from '@/Components/AppIcon.vue';
import SyncBanner from './components/SyncBanner.vue';
import { setPreference, state, view } from './store';
import { cashToConfirm } from './projection';
import { applyUpdate, updateReady } from '@/pwa/register';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();
const route = useRoute();

const tabs = [
    { name: 'home', icon: 'home', label: 'driver.home' },
    { name: 'trips', icon: 'route', label: 'driver.trips' },
    { name: 'wallets', icon: 'wallet', label: 'driver.wallets' },
    { name: 'alerts', icon: 'bell', label: 'driver.alerts' },
    { name: 'account', icon: 'person', label: 'driver.account' },
];

/** Alerts badge: refused entries + cash waiting for the driver's confirmation. */
const alertCount = computed(() => state.rejected.length + cashToConfirm(view.value.trips).length);
const signedIn = computed(() => !!state.profile);
const theme = computed(() => state.profile?.driver.theme ?? 'dark');
</script>

<template>
    <div class="drv-full">
        <div class="scr" :class="{ 'drv-login': route.name === 'login' }">
            <header class="p-top" style="padding-top:12px">
                <img src="/images/logo.png" alt="">
                <b>{{ t('driver.appName') }}</b>
                <template v-if="signedIn">
                    <div class="seg">
                        <button type="button" :aria-pressed="state.profile.driver.language === 'ar'" @click="setPreference({ language: 'ar' })">ع</button>
                        <button type="button" :aria-pressed="state.profile.driver.language === 'en'" @click="setPreference({ language: 'en' })">EN</button>
                    </div>
                    <button type="button" class="ib" @click="setPreference({ theme: theme === 'dark' ? 'light' : 'dark' })">
                        <Icon :name="theme === 'dark' ? 'sun' : 'moon'" />
                    </button>
                </template>
            </header>

            <SyncBanner v-if="signedIn" />

            <button v-if="updateReady" type="button" class="p-banner gn" style="margin:0 12px 8px;width:auto" @click="applyUpdate">
                <Icon name="sync" /> <span style="flex:1">{{ t('driver.update') }}</span> <b>{{ t('driver.reload') }}</b>
            </button>

            <!-- Screens other than sign-in are drawn only while a driver is signed in:
                 signing out empties the profile a moment before the move to the
                 sign-in screen, and drawing Home / Account then would crash. -->
            <div class="p-body"><router-view v-if="signedIn || route.meta.guest" /></div>

            <nav v-if="signedIn" class="p-nav">
                <router-link v-for="tab in tabs" :key="tab.name" v-slot="{ navigate }" :to="{ name: tab.name }" custom>
                    <button type="button" :class="{ on: (route.meta.tab ?? route.name) === tab.name }" @click="navigate">
                        <Icon :name="tab.icon" />{{ t(tab.label) }}
                        <span v-if="tab.name === 'alerts' && alertCount" class="p-badge">{{ alertCount }}</span>
                    </button>
                </router-link>
            </nav>
        </div>
    </div>
</template>
