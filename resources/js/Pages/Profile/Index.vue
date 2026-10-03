<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — My profile ( /profile and /client/profile )
  Location: resources/js/Pages/Profile/Index.vue
  Name, mobile and password of the signed-in person
  (App\Http\Controllers\ProfileController).
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ profile: Object, portal: String });
const { t } = useI18n();
const r = (name) => route((props.portal === 'client' ? 'client.' : '') + name);

const details = useForm({ name: props.profile.name, phone: props.profile.phone ?? '', current_password: '' });
// Changing (or clearing) the mobile asks for the current password.
const phoneChanging = computed(() => (details.phone ?? '').trim() !== (props.profile.phone ?? ''));
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
</script>

<template>
    <PortalLayout :title="t('profile.title')">
        <PageHeader :title="t('profile.title')" :sub="t('profile.sub')" />
        <div class="g2">
            <form class="pn" @submit.prevent="details.patch(r('profile.update'), { preserveScroll: true, onSuccess: () => details.reset('current_password') })">
                <div class="pn-h"><div><h3><AppIcon name="person" />{{ t('profile.details') }}</h3></div></div>
                <Field :label="t('profile.email')"><input :value="profile.email" dir="ltr" disabled></Field>
                <Field :label="t('profile.name')" :error="details.errors.name"><input v-model="details.name" required></Field>
                <Field :label="t('profile.mobile')" :error="details.errors.phone"><input v-model="details.phone" dir="ltr" inputmode="tel"></Field>
                <Field v-if="phoneChanging" :label="t('profile.current')" :hint="t('profile.phoneNeedsPassword')" :error="details.errors.current_password"><input v-model="details.current_password" type="password" autocomplete="current-password" required></Field>
                <button class="btn btn-cu" style="margin-top:16px" :disabled="details.processing">{{ t('common.save') }}</button>
            </form>
            <form class="pn" @submit.prevent="password.put(r('profile.password'), { preserveScroll: true, onSuccess: () => password.reset() })">
                <div class="pn-h"><div><h3><AppIcon name="lock" />{{ t('profile.changePassword') }}</h3><div class="s">{{ t('profile.otherDevices') }}</div></div></div>
                <Field :label="t('profile.current')" :error="password.errors.current_password"><input v-model="password.current_password" type="password" autocomplete="current-password" required></Field>
                <Field :label="t('profile.newPassword')" :hint="t('auth.passwordRule')" :error="password.errors.password"><input v-model="password.password" type="password" autocomplete="new-password" required></Field>
                <Field :label="t('profile.repeat')"><input v-model="password.password_confirmation" type="password" autocomplete="new-password" required></Field>
                <button class="btn btn-cu" style="margin-top:16px" :disabled="password.processing">{{ t('profile.changePassword') }}</button>
            </form>
        </div>
    </PortalLayout>
</template>
