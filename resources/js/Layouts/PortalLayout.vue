<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — PortalLayout (the frame of every signed-in screen)
  Location: resources/js/Layouts/PortalLayout.vue

  The demo's shell, for the three web portals:
    · sidebar: logo, the company / client chip, the menu of this
      portal (Layouts/navigation.js, filtered by permissions), and
      the Driver App shortcut for the office;
    · top bar: page title, ع / EN, sun / moon, and the person menu
      (My profile, Sign out);
    · banners: subscription ended (read-only) or ending soon;
    · the ☰ button in the top bar: on a computer it folds the sidebar
      to icons only and back (the choice is remembered on this
      computer); on a phone it opens the sidebar as a drawer.

  <PortalLayout :title="t('users.title')"> …screen… </PortalLayout>
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import FlashToast from '@/Components/FlashToast.vue';
import LangThemeSwitch from '@/Components/LangThemeSwitch.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { adminMenu, clientMenu, officeMenu } from './navigation';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { avatarColor, date } from '@/Utils/format';
import { storageGet, storageSet } from '@/Utils/safeStorage';

defineProps({ title: { type: String, default: '' } });

const page = usePage();
const { t } = useI18n();
const { can } = usePermissions();

const drawer = ref(false);

// Folded sidebar (computer screens only), remembered on this computer.
const SIDEBAR_KEY = 'eltara.sidebar';
const collapsed = ref(storageGet(SIDEBAR_KEY) === 'collapsed');

function toggleMenu() {
    if (window.matchMedia('(max-width: 920px)').matches) {
        drawer.value = !drawer.value;
        return;
    }
    collapsed.value = !collapsed.value;
    storageSet(SIDEBAR_KEY, collapsed.value ? 'collapsed' : 'open');
}
const menuOpen = ref(false);

const auth = computed(() => page.props.auth);
const portal = computed(() => auth.value.portal);
const company = computed(() => auth.value.company);

const menu = computed(() => {
    const source = { admin: adminMenu, office: officeMenu, client: clientMenu }[portal.value] ?? [];
    const visible = source.filter((item) => typeof item === 'string' || (!item.permission || can(item.permission)) && (!item.adminOnly || auth.value.user?.role === 'company_admin'));
    // Drop section headings that have no item under them.
    return visible.filter((item, i) => typeof item !== 'string' || (visible[i + 1] && typeof visible[i + 1] !== 'string'));
});

function isActive(item) {
    if (!item.route) return false;
    const current = page.url.split('?')[0];
    const target = new URL(route(item.route, item.params ?? {}), window.location.origin).pathname;
    return item.exact ? current === target : current === target || current.startsWith(target + '/');
}

const profileRoute = computed(() => (portal.value === 'client' ? 'client.profile.show' : 'profile.show'));

const chip = computed(() => {
    if (portal.value === 'admin') return { letter: null, name: t('nav.superAdmin'), sub: t('nav.allCompanies'), color: 'vi' };
    if (portal.value === 'client') return { letter: auth.value.customer?.name?.[0], name: auth.value.customer?.name, sub: t('nav.clientOf', { company: company.value?.name }), color: 'bl' };
    return { letter: company.value?.name?.[0], name: company.value?.name, sub: auth.value.user?.job_title || auth.value.user?.role_label, color: 'cu' };
});

const roleLine = computed(() => (portal.value === 'client' ? auth.value.customer?.name : auth.value.user?.job_title || auth.value.user?.role_label));

function signOut() {
    router.post(route('logout'));
}
</script>

<template>
    <Head :title="title" />
    <div class="app" :class="{ drawer, collapsed }">
        <aside class="side">
            <div class="brand">
                <img src="/images/logo.png" alt="">
                <div><div class="nm">{{ t('app.name') }}</div><div class="tg">كل مشوار محسوب</div></div>
            </div>

            <div class="co-chip" :title="chip.name">
                <span class="lg" :style="`background:var(--${chip.color}-dim);color:var(--${chip.color})`">
                    <AppIcon v-if="!chip.letter" name="shield" :size="15" /><template v-else>{{ chip.letter }}</template>
                </span>
                <div><b>{{ chip.name }}</b><span>{{ chip.sub }}</span></div>
            </div>

            <nav class="nav">
                <template v-for="(item, i) in menu" :key="i">
                    <div v-if="typeof item === 'string'" class="nav-l">{{ t(item) }}</div>
                    <span v-else-if="item.phase2" class="nav-i" style="cursor:default;opacity:.7" :title="t(item.label)">
                        <AppIcon :name="item.icon" /><span>{{ t(item.label) }}</span><span class="ph2">{{ t('common.phase2') }}</span>
                    </span>
                    <Link v-else :href="route(item.route, item.params ?? {})" class="nav-i" :class="{ on: isActive(item) }" :title="collapsed ? t(item.label) : null" @click="drawer = false">
                        <AppIcon :name="item.icon" /><span>{{ t(item.label) }}</span>
                    </Link>
                </template>
            </nav>

            <div class="side-foot">
                <a v-if="portal === 'office'" href="/driver" class="drv-card" target="_blank" rel="noopener" :title="t('nav.driverApp')">
                    <b><AppIcon name="phone" :size="16" /> <em>{{ t('nav.driverApp') }}</em></b>
                    <span>{{ t('nav.driverAppSub') }}</span>
                </a>
            </div>
        </aside>
        <div class="scrim" @click="drawer = false" />

        <div class="main">
            <header class="top">
                <button type="button" class="ib menu-btn" :aria-label="t('common.menu')" :aria-expanded="!collapsed" @click="toggleMenu"><AppIcon name="menu" /></button>
                <div class="crumb"><b>{{ title }}</b></div>
                <div class="top-r">
                    <NotificationBell v-if="portal !== 'admin'" />
                    <LangThemeSwitch />
                    <div class="who" @click="menuOpen = !menuOpen">
                        <span class="av" :style="{ background: avatarColor(auth.user.name) }">{{ auth.user.initials }}</span>
                        <div class="txt"><div class="n">{{ auth.user.name }}</div><div class="r">{{ roleLine }}</div></div>
                        <div v-if="menuOpen" class="menu" @click.stop>
                            <div class="mh">{{ auth.user.email }}</div>
                            <Link :href="route(profileRoute)" as="button" type="button" @click="menuOpen = false">
                                <AppIcon name="person" /><div><b>{{ t('common.profile') }}</b></div>
                            </Link>
                            <button type="button" @click="signOut">
                                <AppIcon name="logout" /><div><b>{{ t('common.signOut') }}</b></div>
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <main class="content">
                <div v-if="portal === 'admin'" class="sa-strip"><AppIcon name="shield" /> {{ t('admin.strip') }}</div>
                <div v-if="company?.read_only" class="banner rd"><AppIcon name="lock" /> {{ t('banner.readOnly') }}</div>
                <div v-else-if="company?.expiring_soon && portal === 'office'" class="banner am">
                    <AppIcon name="clock" />
                    {{ t('banner.expiring', { days: company.days_left, date: date(company.subscription_ends_at) }) }}
                    <span v-if="page.props.support?.phone" class="num" style="margin-inline-start:auto">{{ page.props.support.phone }}</span>
                </div>
                <slot />
            </main>
        </div>
    </div>
    <div v-if="menuOpen" style="position:fixed;inset:0;z-index:25" @click="menuOpen = false" />
    <FlashToast />
</template>
