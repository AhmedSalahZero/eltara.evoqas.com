<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — All notifications ( /office/notifications, /client/notifications )
  Location: resources/js/Pages/Notifications/Index.vue
  Scope §11: the full history behind the bell — every line, newest
  first, unread ones highlighted. A click marks the line read and
  goes to its screen. (App\Http\Controllers\NotificationController)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { ago, date, time } from '@/Utils/format';
import { notificationText } from '@/Utils/notify';

const props = defineProps({ items: Object, filter: String, unread: Number });
const { t, locale } = useI18n();
const page = usePage();
const base = computed(() => (page.props.auth?.portal === 'client' ? 'client' : 'office'));

const post = (name, params = {}) => fetch(route(`${base.value}.${name}`, params), {
    method: 'POST',
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
});

async function open(n) {
    if (!n.read) await post('notifications.read', { id: n.id });
    if (n.url) router.visit(n.url);
    else router.reload();
}
async function readAll() {
    await post('notifications.read-all');
    router.reload();
}
const setFilter = (f) => router.get(route(`${base.value}.notifications.index`), f === 'unread' ? { filter: 'unread' } : {}, { preserveState: true });
</script>

<template>
    <PortalLayout :title="t('notif.title')">
        <PageHeader :title="t('notif.title')" :sub="t('notif.pageSub')">
            <div class="chips">
                <button class="chip" :class="{ on: filter === 'all' }" @click="setFilter('all')">{{ t('common.all') }}</button>
                <button class="chip" :class="{ on: filter === 'unread' }" @click="setFilter('unread')">{{ t('notif.unread') }} <span class="c">{{ unread }}</span></button>
            </div>
            <button v-if="unread" class="btn btn-ln" @click="readAll"><AppIcon name="check" /> {{ t('notif.markAll') }}</button>
        </PageHeader>

        <div v-if="!items.data.length" class="empty">{{ t('notif.none') }}</div>
        <div v-else class="pn" style="padding:0;overflow:hidden">
            <button v-for="n in items.data" :key="n.id" type="button" class="notif-row" :class="{ unread: !n.read }" @click="open(n)">
                <span class="dot-s" :style="{ visibility: n.read ? 'hidden' : 'visible' }"></span>
                <span class="nt">{{ notificationText(n, t) }}</span>
                <span class="nw num">{{ date(n.at) }} {{ time(n.at) }} · {{ ago(n.at, locale) }}</span>
            </button>
        </div>
        <Pagination :links="items.links" />
    </PortalLayout>
</template>
