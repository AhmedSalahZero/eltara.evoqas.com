<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — "Forgot your password?" ( /forgot-password )
  Location: resources/js/Pages/Auth/ForgotPassword.vue
  Sends a reset link. The answer is the same whether or not the
  email exists, so nobody can test which emails have accounts.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ status: String });
const { t } = useI18n();
const form = useForm({ email: '' });
</script>

<template>
    <AuthLayout :title="t('auth.forgotTitle')">
        <h1>{{ t('auth.forgotTitle') }}</h1>
        <p class="mu sm" style="margin:0">{{ t('auth.forgotSub') }}</p>
        <div v-if="status" class="note gn" style="margin-top:14px"><AppIcon name="check" /><div>{{ status }}</div></div>
        <form @submit.prevent="form.post(route('password.email'))">
            <Field :label="t('auth.email')" :error="form.errors.email">
                <input v-model="form.email" type="email" dir="ltr" required autofocus>
            </Field>
            <button class="btn btn-cu" style="width:100%;justify-content:center;margin-top:16px;padding:12px" :disabled="form.processing">{{ t('auth.sendLink') }}</button>
        </form>
        <Link :href="route('login')" class="back" style="margin-top:18px"><AppIcon name="back" :size="14" /> {{ t('auth.backToLogin') }}</Link>
    </AuthLayout>
</template>
