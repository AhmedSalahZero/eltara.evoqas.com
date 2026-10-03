<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Pagination (page numbers under a long list)
  Location: resources/js/Components/Pagination.vue
  <Pagination :links="companies.links" />   (Laravel's paginator links)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ links: { type: Array, default: () => [] } });

const label = (text) => text.replace('&laquo; Previous', '‹').replace('Next &raquo;', '›');
</script>

<template>
    <nav v-if="links.length > 3" class="pager">
        <template v-for="link in links" :key="link.label">
            <Link v-if="link.url" :href="link.url" :class="{ on: link.active }" preserve-scroll>{{ label(link.label) }}</Link>
            <span v-else>{{ label(link.label) }}</span>
        </template>
    </nav>
</template>
