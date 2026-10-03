<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — My company users ( /client/team )
  Location: resources/js/Pages/Client/Team.vue
  Scope §7: the account admin adds colleagues (activation e-mail) and
  can suspend / re-activate them; other users can request and track
  but not manage users. Server: App\Http\Controllers\Client\TeamController.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { ago } from '@/Utils/format';

defineProps({ users: Array, canManage: Boolean });
const { t, locale } = useI18n();

const open = ref(false);
const form = useForm({ name: '', email: '', phone: '', job_title: '' });
const add = () => form.post(route('client.team.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
const toggle = (u) => router.post(route('client.team.toggle', u.id), {}, { preserveScroll: true });
</script>

<template>
    <PortalLayout :title="t('nav.clientUsers')">
        <PageHeader :title="t('nav.clientUsers')" :sub="t('cp.teamSub')">
            <button v-if="canManage" class="btn btn-cu" @click="open = true"><AppIcon name="plus" /> {{ t('cp.addUser') }}</button>
        </PageHeader>

        <div class="tw">
            <table>
                <thead><tr><th>{{ t('cp.tm.name') }}</th><th>{{ t('cp.tm.contact') }}</th><th>{{ t('cp.tm.access') }}</th><th>{{ t('cp.tm.last') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id">
                        <td><b class="sm">{{ u.name }}</b> <span v-if="u.me" class="bd nodot pl">{{ t('cp.tm.you') }}</span><div class="xs mu">{{ u.job_title }}</div></td>
                        <td class="xs"><span dir="ltr">{{ u.email }}</span><div v-if="u.phone" class="num mu" dir="ltr">{{ u.phone }}</div></td>
                        <td>
                            <span class="bd nodot" :class="u.is_account_admin ? 'cu' : 'pl'">{{ u.is_account_admin ? t('cp.tm.admin') : t('cp.tm.member') }}</span>
                            <span class="bd nodot" :class="!u.is_active ? 'rd' : u.activated ? 'gn' : 'am'" style="margin-inline-start:4px">{{ !u.is_active ? t('cp.tm.suspended') : u.activated ? t('cp.tm.active') : t('cp.tm.invited') }}</span>
                        </td>
                        <td class="xs mu">{{ u.last_login_at ? ago(u.last_login_at, locale) : t('common.never') }}</td>
                        <td class="e"><button v-if="canManage && !u.me && !u.is_account_admin" class="btn btn-ln btn-sm" @click="toggle(u)">{{ u.is_active ? t('cp.tm.suspend') : t('cp.tm.reactivate') }}</button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="open" :title="t('cp.addUser')" @close="open = false">
            <form id="team-form" @submit.prevent="add">
                <Field :label="t('cp.tm.name')" :error="form.errors.name"><input v-model="form.name" maxlength="120" required></Field>
                <Field label="Email" :error="form.errors.email"><input v-model="form.email" type="email" dir="ltr" maxlength="150" required></Field>
                <Field :label="t('cp.tm.phone')" :hint="t('common.optional')" :error="form.errors.phone"><input v-model="form.phone" dir="ltr" maxlength="20"></Field>
                <Field :label="t('cp.tm.title')" :hint="t('common.optional')" :error="form.errors.job_title"><input v-model="form.job_title" maxlength="100"></Field>
                <p class="xs mu">{{ t('cp.tm.inviteNote') }}</p>
            </form>
            <template #footer>
                <button type="button" class="btn btn-ln" @click="open = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="team-form" class="btn btn-cu" :disabled="form.processing">{{ t('cp.tm.invite') }}</button>
            </template>
        </Modal>
    </PortalLayout>
</template>
