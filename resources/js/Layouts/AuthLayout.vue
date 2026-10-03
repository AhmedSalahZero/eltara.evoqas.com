<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — AuthLayout (sign-in, forgot / reset / activate password)
  Location: resources/js/Layouts/AuthLayout.vue

  The demo's sign-in page: the road picture with the logo and the
  promise on one side, the form on the other (stacked on phones).
  Language and theme can be switched before signing in.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Head } from '@inertiajs/vue3';
import LangThemeSwitch from '@/Components/LangThemeSwitch.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ title: { type: String, default: '' } });
const { t } = useI18n();
</script>

<template>
    <Head :title="title" />
    <div class="login">
        <div class="art">
            <div class="brandbig">
                <img src="/images/logo.png" alt="">
                <div><b>التارة</b><span>كل مشوار محسوب</span></div>
            </div>
            <div style="position:relative">
                <h2>{{ t('auth.artTitle') }}</h2>
                <p>{{ t('auth.artText') }}</p>
            </div>
            <div class="ticks">
                <div><b>3</b><span>{{ t('auth.tick1') }}</span></div>
                <div><b>2</b><span>{{ t('auth.tick2') }}</span></div>
                <div><b>&#10003;</b><span>{{ t('auth.tick3') }}</span></div>
            </div>
        </div>
        <div class="form">
            <div class="box">
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:26px">
                    <LangThemeSwitch />
                </div>
                <slot />
            </div>
        </div>
    </div>
    <FlashToast />
</template>
