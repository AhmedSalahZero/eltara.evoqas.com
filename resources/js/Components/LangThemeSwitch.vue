<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — LangThemeSwitch (ع / EN and sun / moon buttons)
  Location: resources/js/Components/LangThemeSwitch.vue
  Used in the top bar and on the sign-in page (Scope §2 — language
  and theme switchable per user). Saved via usePreferences.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { usePreferences } from '@/composables/usePreferences';

const { locale, t } = useI18n();
const { savePreference } = usePreferences();
const light = ref(document.documentElement.classList.contains('light'));

function toggleTheme() {
    light.value = !light.value;
    savePreference({ theme: light.value ? 'light' : 'dark' });
}
</script>

<template>
    <div class="seg">
        <button type="button" :aria-pressed="locale === 'ar'" @click="savePreference({ language: 'ar' })">ع</button>
        <button type="button" :aria-pressed="locale === 'en'" @click="savePreference({ language: 'en' })">EN</button>
    </div>
    <button type="button" class="ib" :aria-label="light ? t('common.dark') : t('common.light')" @click="toggleTheme">
        <AppIcon :name="light ? 'moon' : 'sun'" />
    </button>
</template>
