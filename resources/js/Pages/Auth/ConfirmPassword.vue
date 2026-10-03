<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Confirm password ( /confirm-password )
  Location: resources/js/Pages/Auth/ConfirmPassword.vue
  Asked before sensitive actions (e.g. re-opening a closed month,
  Step 6). Valid for 3 hours once confirmed.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Field from '@/Components/Field.vue';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();
const form = useForm({ password: '' });
</script>

<template>
    <AuthLayout :title="t('auth.confirmTitle')">
        <h1>{{ t('auth.confirmTitle') }}</h1>
        <p class="mu sm" style="margin:0">{{ t('auth.confirmSub') }}</p>
        <form @submit.prevent="form.post(route('password.confirm'), { onFinish: () => form.reset() })">
            <Field :label="t('auth.password')" :error="form.errors.password">
                <input v-model="form.password" type="password" autocomplete="current-password" required autofocus>
            </Field>
            <button class="btn btn-cu" style="width:100%;justify-content:center;margin-top:16px;padding:12px" :disabled="form.processing">{{ t('auth.confirm') }}</button>
        </form>
    </AuthLayout>
</template>
