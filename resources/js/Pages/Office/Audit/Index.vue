<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Audit log ( /office/audit )   Scope §12   COMPANY ADMIN ONLY
  Location: resources/js/Pages/Office/Audit/Index.vue
  Who did what and when. Read-only. Click a row to see what changed
  (before → after). Filters: period, person, area, word search.
  (App\Http\Controllers\Office\AuditController)
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { date, time } from '@/Utils/format';

const props = defineProps({ logs: Object, filters: Object, areas: Array, actors: Array });
const { t } = useI18n();

const f = reactive({ ...props.filters });
const query = computed(() => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')));
const apply = () => router.get(route('office.audit.index'), query.value, { preserveState: true, preserveScroll: true });
const exportUrl = computed(() => route('office.audit.export', query.value));
let timer;
const typing = () => { clearTimeout(timer); timer = setTimeout(apply, 400); };

const openId = ref(null);
const toggle = (id) => { openId.value = openId.value === id ? null : id; };

// A changes block is {before:{…}, after:{…}} or a flat object: show it as readable lines.
const flat = (value) => (value !== null && typeof value === 'object' ? JSON.stringify(value, null, 0) : String(value ?? '—'));
function lines(changes) {
    if (!changes) return [];
    const out = [];
    const { before, after, ...rest } = changes;
    const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];
    for (const k of keys) out.push({ k, before: before ? flat(before[k]) : null, after: after ? flat(after[k]) : null });
    for (const [k, v] of Object.entries(rest)) out.push({ k, before: null, after: flat(v) });
    return out;
}
</script>

<template>
    <PortalLayout :title="t('audit.title')">
        <PageHeader :title="t('audit.title')" :sub="t('audit.sub')">
            <a :href="exportUrl" class="btn btn-ln"><AppIcon name="download" /> {{ t('audit.excel') }}</a>
        </PageHeader>

        <div class="pn" style="margin-bottom:14px">
            <div class="fgrid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                <div class="fld"><label class="fl">{{ t('rep.from') }}</label><input v-model="f.from" type="date" class="sel" dir="ltr" @change="apply" /></div>
                <div class="fld"><label class="fl">{{ t('rep.to') }}</label><input v-model="f.to" type="date" class="sel" dir="ltr" @change="apply" /></div>
                <div class="fld"><label class="fl">{{ t('audit.person') }}</label>
                    <select v-model="f.actor" class="sel" @change="apply"><option :value="null">{{ t('common.all') }}</option><option v-for="a in actors" :key="a" :value="a">{{ a }}</option></select></div>
                <div class="fld"><label class="fl">{{ t('audit.area') }}</label>
                    <select v-model="f.area" class="sel" @change="apply"><option :value="null">{{ t('common.all') }}</option><option v-for="a in areas" :key="a.key" :value="a.key">{{ a.label }}</option></select></div>
                <div class="fld"><label class="fl">{{ t('common.search') }}</label><input v-model="f.q" class="sel" :placeholder="t('audit.searchPh')" @input="typing" /></div>
            </div>
        </div>

        <div v-if="!logs.data.length" class="empty">{{ t('audit.empty') }}</div>
        <div v-else class="tw">
            <table>
                <thead><tr><th>{{ t('audit.when') }}</th><th>{{ t('audit.who') }}</th><th>{{ t('audit.action') }}</th><th>{{ t('audit.on') }}</th><th></th></tr></thead>
                <tbody>
                    <template v-for="l in logs.data" :key="l.id">
                        <tr class="ck" @click="toggle(l.id)">
                            <td class="num" style="white-space:nowrap">{{ date(l.at) }} {{ time(l.at) }}</td>
                            <td><b>{{ l.actor || '—' }}</b><div class="xs mu">{{ t(`audit.actors.${l.actor_type}`) }}</div></td>
                            <td><b>{{ l.label }}</b><div class="xs mu num">{{ l.action }}</div></td>
                            <td class="sm">{{ l.subject_type ? `${t(`audit.subjects.${l.subject_type}`) === `audit.subjects.${l.subject_type}` ? l.subject_type : t(`audit.subjects.${l.subject_type}`)} #${l.subject_id}` : '—' }}</td>
                            <td class="e"><span v-if="l.changes" class="mu">{{ openId === l.id ? '▲' : '▼' }}</span></td>
                        </tr>
                        <tr v-if="openId === l.id && l.changes">
                            <td colspan="5" style="background:var(--card-2)">
                                <table class="kv" style="width:100%">
                                    <tbody>
                                        <tr v-for="x in lines(l.changes)" :key="x.k">
                                            <td class="b" style="width:200px">{{ x.k }}</td>
                                            <td v-if="x.before !== null" class="num rd" dir="ltr" style="text-decoration:line-through">{{ x.before }}</td>
                                            <td class="num" dir="ltr">{{ x.after ?? '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-if="l.ip" class="xs mu" style="margin-top:6px">{{ t('audit.ip') }}: <span class="num">{{ l.ip }}</span></div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <Pagination :links="logs.links" />
    </PortalLayout>
</template>
