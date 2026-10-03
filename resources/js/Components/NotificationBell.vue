<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — NotificationBell (the bell in the top bar)
  Location: resources/js/Components/NotificationBell.vue

  Scope §11 in-app notifications, office and client portal. Shows the
  latest lines (shared prop `notifications`, filled by
  HandleInertiaRequests) and a red dot while any is unread. A click on
  a line marks it read and goes to its screen; "Mark all as read"
  clears the dot. Each line keeps a key and numbers, written here in
  the reader's language (lang key  notif.<key with _ for .>).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { ago } from '@/Utils/format';
import { notificationText } from '@/Utils/notify';

const page = usePage();
const { t, locale } = useI18n();
const open = ref(false);

const data = computed(() => page.props.notifications ?? { unread: 0, items: [] });
const base = computed(() => (page.props.auth?.portal === 'client' ? 'client' : 'office'));

const text = (n) => notificationText(n, t);

async function post(name, params = {}) {
    await fetch(route(`${base.value}.${name}`, params), {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
}

async function go(n) {
    open.value = false;
    if (!n.read) await post('notifications.read', { id: n.id });
    if (n.url) router.visit(n.url);
    else router.reload({ only: ['notifications'] });
}

async function readAll() {
    await post('notifications.read-all');
    router.reload({ only: ['notifications'] });
}
</script>

<template>
    <div class="bell-wrap">
        <button type="button" class="ib" :aria-label="t('notif.title')" :aria-expanded="open" @click="open = !open">
            <AppIcon name="bell" /><span v-if="data.unread" class="dot" />
        </button>
        <template v-if="open">
            <div class="bell-scrim" @click="open = false" />
            <div class="bell-menu" role="menu">
                <div class="bh">
                    <b>{{ t('notif.title') }}</b>
                    <button v-if="data.unread" type="button" class="lnk" @click="readAll">{{ t('notif.markAll') }}</button>
                </div>
                <div v-if="!data.items.length" class="bn">{{ t('notif.none') }}</div>
                <button v-for="n in data.items" :key="n.id" type="button" class="bi" :class="{ unread: !n.read }" @click="go(n)">
                    <span class="bt">{{ text(n) }}</span>
                    <span class="bw">{{ ago(n.at, locale) }}</span>
                </button>
                <Link :href="route(`${base}.notifications.index`)" class="bf" @click="open = false">{{ t('notif.seeAll') }}</Link>
            </div>
        </template>
    </div>
</template>
