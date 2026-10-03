<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Choose a password ( /reset-password/… and /activate/… )
  Location: resources/js/Pages/Auth/ResetPassword.vue
  mode 'activate' → first password of a new account (activation link)
  mode 'reset'    → forgotten password
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Field from '@/Components/Field.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ email: String, token: String, mode: String, for: String });
const { t } = useI18n();

const form = useForm({ token: props.token, email: props.email ?? '', mode: props.mode, for: props.for, password: '', password_confirmation: '' });

const submit = () => form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <AuthLayout :title="mode === 'activate' ? t('auth.activateTitle') : t('auth.resetTitle')">
        <h1>{{ mode === 'activate' ? t('auth.activateTitle') : t('auth.resetTitle') }}</h1>
        <p class="mu sm" style="margin:0">{{ mode === 'activate' ? t('auth.activateSub') : t('auth.passwordRule') }}</p>
        <form @submit.prevent="submit">
            <Field :label="t('auth.email')" :error="form.errors.email">
                <input v-model="form.email" type="email" dir="ltr" required readonly>
            </Field>
            <Field :label="t('auth.newPassword')" :hint="mode === 'activate' ? t('auth.passwordRule') : ''" :error="form.errors.password">
                <input v-model="form.password" type="password" autocomplete="new-password" required autofocus>
            </Field>
            <Field :label="t('auth.confirmPassword')">
                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required>
            </Field>
            <button class="btn btn-cu" style="width:100%;justify-content:center;margin-top:16px;padding:12px" :disabled="form.processing">{{ t('auth.savePassword') }}</button>
        </form>
    </AuthLayout>
</template>
