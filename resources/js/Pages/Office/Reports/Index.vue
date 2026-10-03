<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Reports hub ( /office/reports )   Scope §6.14
  Location: resources/js/Pages/Office/Reports/Index.vue
  The 12 report cards of the demo's Reports screen. Titles and
  descriptions come from the server (lang/*/reports.php).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ items: Array });
const { t } = useI18n();
const COLOURS = ['cu', 'bl', 'gn', 'vi', 'am', 'rd'];
</script>

<template>
    <PortalLayout :title="t('nav.reports')">
        <PageHeader :title="t('nav.reports')" :sub="t('rep.sub')" />
        <div class="g3">
            <button v-for="(r, i) in items" :key="r.key" class="alert" :style="`--a:var(--${COLOURS[i % 6]});--a-dim:var(--${COLOURS[i % 6]}-dim);--a-bd:var(--${COLOURS[i % 6]}-bd)`"
                @click="router.visit(route('office.reports.show', r.key))">
                <span class="ai"><AppIcon :name="r.icon" /></span>
                <div><b>{{ r.title }}</b><p>{{ r.desc }}</p></div>
            </button>
        </div>
    </PortalLayout>
</template>
