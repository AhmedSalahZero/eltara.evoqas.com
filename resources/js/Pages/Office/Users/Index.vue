<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Users & permissions ( /office/users )
  Location: resources/js/Pages/Office/Users/Index.vue

  The demo's screen (Scope §5):
    · the two limit meters (office users, driver accounts);
    · users list on one side — click a user to open their permissions;
    · the permission grid for that NAMED user: one row per feature,
      a tick per action that applies (View / Create / Edit / Approve /
      Delete …), "Copy permissions from…", and the approval limit;
    · New user (invite by email), edit details, suspend / reactivate,
      resend activation or send a password reset.
  The company admin's grid is shown fully ticked and locked.
  Server: App\Http\Controllers\Office\UserController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import UsageMeter from '@/Components/UsageMeter.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/lang/i18n';
import { ago, avatarColor } from '@/Utils/format';

const props = defineProps({ users: Array, matrix: Array, needsApprovalLimit: Array, limits: Object });
const { t, locale } = useI18n();
const { can } = usePermissions();
const me = usePage().props.auth.user;

// ── Selected user + permission grid ──────────────────────────────
const selectedId = ref(props.users.find((u) => !u.is_admin)?.id ?? props.users[0]?.id);
const selected = computed(() => props.users.find((u) => u.id === selectedId.value));
const locked = computed(() => !selected.value || selected.value.is_admin || !can('users.edit'));

const perms = useForm({ permissions: [], approval_limit: '' });

watch(selected, (u) => {
    if (!u) return;
    perms.defaults({ permissions: [...u.permissions], approval_limit: u.approval_limit ?? '' });
    perms.reset();
    perms.clearErrors();
}, { immediate: true });

const has = (key) => perms.permissions.includes(key);
function toggle(key) {
    if (locked.value) return;
    perms.permissions = has(key) ? perms.permissions.filter((k) => k !== key) : [...perms.permissions, key];
}
function toggleRow(row) {
    if (locked.value) return;
    const keys = row.actions.map((a) => a.key);
    const all = keys.every(has);
    perms.permissions = all ? perms.permissions.filter((k) => !keys.includes(k)) : [...new Set([...perms.permissions, ...keys])];
}
function copyFrom(event) {
    const source = props.users.find((u) => u.id === Number(event.target.value));
    if (source) {
        perms.permissions = [...source.permissions];
        perms.approval_limit = source.approval_limit ?? '';
    }
    event.target.value = '';
}
const needsLimit = computed(() => props.needsApprovalLimit.some(has));
const savePerms = () => perms.put(route('office.users.permissions', selected.value.id), { preserveScroll: true });

// ── New / edit user ──────────────────────────────────────────────
const userModal = ref(false);
const editingUser = ref(null);
const userForm = useForm({ name: '', job_title: '', email: '', phone: '', language: locale.value, copy_from_user_id: '' });

function openUser(u = null) {
    editingUser.value = u;
    userForm.defaults(u ? { name: u.name, job_title: u.job_title ?? '', email: u.email, phone: u.phone ?? '', language: locale.value, copy_from_user_id: '' }
        : { name: '', job_title: '', email: '', phone: '', language: locale.value, copy_from_user_id: '' });
    userForm.reset();
    userForm.clearErrors();
    userModal.value = true;
}
function saveUser() {
    const options = { preserveScroll: true, onSuccess: () => (userModal.value = false) };
    editingUser.value
        ? userForm.patch(route('office.users.update', editingUser.value.id), options)
        : userForm.post(route('office.users.store'), options);
}

// ── Suspend / reactivate / links ─────────────────────────────────
const confirming = ref(null);
const doToggle = () => router.post(route('office.users.toggle', confirming.value.id), {}, { preserveScroll: true, onFinish: () => (confirming.value = null) });
const sendLink = (u) => router.post(route('office.users.link', u.id), {}, { preserveScroll: true });

const full = computed(() => props.limits.office_used >= props.limits.office_limit);
</script>

<template>
    <PortalLayout :title="t('users.title')">
        <PageHeader :title="t('users.title')" :sub="t('users.sub')">
            <button v-if="can('users.create')" class="btn btn-cu" :disabled="full" :title="full ? t('users.limitReached') : ''" @click="openUser()">
                <AppIcon name="plus" /> {{ t('users.add') }}
            </button>
        </PageHeader>

        <div class="g2" style="margin-bottom:14px">
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="users" />{{ t('users.officeUsers') }}</h3><div class="s">{{ t('users.limitHint') }}</div></div></div>
                <UsageMeter :used="limits.office_used" :limit="limits.office_limit" />
            </div>
            <div class="pn">
                <div class="pn-h"><div><h3><AppIcon name="phone" />{{ t('users.driverAccounts') }}</h3><div class="s">{{ t('users.separate') }}</div></div></div>
                <UsageMeter :used="limits.drivers_used" :limit="limits.drivers_limit" />
            </div>
        </div>

        <div class="g-37">
            <div class="tw">
                <table>
                    <thead><tr><th>{{ t('users.user') }}</th><th>{{ t('users.lastSeen') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id" class="ck" :style="u.id === selectedId ? 'background:var(--cu-dim)' : ''" @click="selectedId = u.id">
                            <td>
                                <div class="nc">
                                    <span class="av" :style="{ background: avatarColor(u.name), width: '30px', height: '30px' }">{{ u.initials }}</span>
                                    <div>
                                        <b>{{ u.name }}
                                            <span v-if="u.is_admin" class="bd cu nodot">{{ t('users.admin') }}</span>
                                            <span v-if="!u.is_active" class="bd pl nodot">{{ t('users.suspended') }}</span>
                                            <span v-else-if="!u.activated" class="bd am nodot">{{ t('users.pending') }}</span>
                                        </b>
                                        <div class="m">{{ u.job_title || u.email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="xs mu">{{ ago(u.last_login_at, locale) || t('common.never') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="selected" class="pn">
                <div class="pn-h">
                    <div>
                        <h3><AppIcon name="key" />{{ t('users.permsOf', { name: selected.name }) }}</h3>
                        <div class="s">{{ selected.job_title }} · <span class="num">{{ selected.email }}</span></div>
                    </div>
                    <div v-if="can('users.edit')" style="display:flex;gap:6px;flex-wrap:wrap">
                        <select v-if="!locked" class="sel" @change="copyFrom">
                            <option value="">{{ t('users.copyFrom') }}</option>
                            <option v-for="u in users.filter((x) => x.id !== selected.id)" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <button v-if="!selected.is_admin || selected.id === me.id" class="btn btn-ln sm" @click="openUser(selected)"><AppIcon name="edit" /> {{ t('common.edit') }}</button>
                    </div>
                </div>

                <div v-if="selected.is_admin" class="note cu" style="margin-bottom:12px"><AppIcon name="shield" /><div>{{ t('users.adminAll') }}</div></div>

                <div class="tw flat">
                    <table class="perm">
                        <thead>
                            <tr><th>{{ t('users.feature') }}</th><th v-for="a in ['view', 'create', 'edit', 'approve', 'delete']" :key="a">{{ t('perm.' + a) }}</th><th /></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in matrix" :key="row.key">
                                <td><button type="button" class="btn-gh" style="border:0;background:none;padding:0;font:inherit;font-weight:700;cursor:pointer;color:inherit" @click="toggleRow(row)">{{ row.label }}</button></td>
                                <td v-for="a in ['view', 'create', 'edit', 'approve', 'delete']" :key="a">
                                    <input v-if="row.actions.some((x) => x.action === a)" type="checkbox" class="cb" :checked="has(`${row.key}.${a}`)" :disabled="locked" @change="toggle(`${row.key}.${a}`)">
                                    <span v-else class="mu-2">·</span>
                                </td>
                                <td class="xs">
                                    <label v-for="x in row.actions.filter((x) => !['view', 'create', 'edit', 'approve', 'delete'].includes(x.action))" :key="x.key" style="display:inline-flex;gap:5px;align-items:center;white-space:nowrap">
                                        <input type="checkbox" class="cb" :checked="has(x.key)" :disabled="locked" @change="toggle(x.key)"> {{ x.label }}
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Field v-if="needsLimit && !selected.is_admin" :label="t('users.approvalLimit')" :hint="t('users.approvalHint')" :error="perms.errors.approval_limit" style="max-width:340px">
                    <input v-model="perms.approval_limit" type="number" min="0" step="any" dir="ltr" :disabled="locked">
                </Field>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;align-items:center">
                    <button v-if="!locked" class="btn btn-cu" :disabled="perms.processing || !perms.isDirty" @click="savePerms"><AppIcon name="check" /> {{ t('users.savePerms') }}</button>
                    <span v-if="perms.isDirty && !locked" class="xs am">{{ t('users.unsaved') }}</span>
                    <template v-if="can('users.edit') && !selected.is_admin">
                        <button class="btn btn-ln sm" style="margin-inline-start:auto" @click="sendLink(selected)">
                            <AppIcon name="sync" /> {{ selected.activated ? t('users.sendReset') : t('users.sendActivation') }}
                        </button>
                        <button class="btn sm" :class="selected.is_active ? 'btn-rd' : 'btn-ln'" :disabled="selected.id === me.id" @click="confirming = selected">
                            <AppIcon :name="selected.is_active ? 'lock' : 'unlock'" /> {{ selected.is_active ? t('users.suspend') : t('users.reactivate') }}
                        </button>
                    </template>
                </div>
            </div>
            <div v-else class="empty">{{ t('users.selectUser') }}</div>
        </div>

        <Modal :show="userModal" :title="editingUser ? editingUser.name : t('users.add')" @close="userModal = false">
            <form id="user-form" @submit.prevent="saveUser">
                <div class="fgrid">
                    <Field :label="t('users.name')" :error="userForm.errors.name"><input v-model="userForm.name" required></Field>
                    <Field :label="t('users.jobTitle')" :error="userForm.errors.job_title"><input v-model="userForm.job_title"></Field>
                    <Field class="w" :label="t('users.email')" :error="userForm.errors.email"><input v-model="userForm.email" type="email" dir="ltr" required></Field>
                    <Field class="w" :label="t('users.mobile')" :error="userForm.errors.phone"><input v-model="userForm.phone" dir="ltr" inputmode="tel"></Field>
                    <template v-if="!editingUser">
                        <Field :label="t('users.language')">
                            <select v-model="userForm.language"><option value="ar">العربية</option><option value="en">English</option></select>
                        </Field>
                        <Field :label="t('users.startWith')">
                            <select v-model="userForm.copy_from_user_id">
                                <option value="">{{ t('users.nobody') }}</option>
                                <option v-for="u in users.filter((x) => !x.is_admin)" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </Field>
                    </template>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="userModal = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="user-form" class="btn btn-cu" :disabled="userForm.processing">
                    <AppIcon name="check" /> {{ editingUser ? t('common.save') : t('users.inviteBtn') }}
                </button>
            </template>
        </Modal>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? (confirming.is_active ? t('users.suspend') : t('users.reactivate')) : ''"
            :message="confirming ? t(confirming.is_active ? 'users.confirmSuspend' : 'users.confirmReactivate', { name: confirming.name }) : ''"
            :confirm-label="confirming?.is_active ? t('users.suspend') : t('users.reactivate')"
            :danger="confirming?.is_active"
            @confirm="doToggle" @cancel="confirming = null"
        />
    </PortalLayout>
</template>
