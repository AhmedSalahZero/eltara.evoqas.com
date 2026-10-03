<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Sign-in screen ( /login )
  Location: resources/js/Pages/Auth/Login.vue

  For office staff, the Super Admin and client-portal users: email or
  mobile + password (checks: App\Http\Requests\Auth\LoginRequest).
  Shows why someone was signed out (e.g. company suspended) and a
  link for drivers to their own app.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Field from '@/Components/Field.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ status: String, reason: String });
const { t } = useI18n();

const form = useForm({ login: '', password: '', remember: true });

const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <AuthLayout :title="t('auth.signIn')">
        <h1>{{ t('auth.signIn') }}</h1>
        <p class="mu sm" style="margin:0">{{ t('auth.signInSub') }}</p>

        <div v-if="reason" class="note rd" style="margin-top:14px"><AppIcon name="alert" /><div>{{ reason }}</div></div>
        <div v-if="status" class="note gn" style="margin-top:14px"><AppIcon name="check" /><div>{{ status }}</div></div>

        <form @submit.prevent="submit">
            <Field :label="t('auth.login')" :error="form.errors.login">
                <input v-model="form.login" type="text" autocomplete="username" dir="ltr" required autofocus>
            </Field>
            <label class="fld">
                <span class="fl">{{ t('auth.password') }}
                    <Link :href="route('password.request')" class="xs" style="color:var(--cu)">{{ t('auth.forgot') }}</Link>
                </span>
                <input v-model="form.password" type="password" autocomplete="current-password" required>
                <span v-if="form.errors.password" class="err">{{ form.errors.password }}</span>
            </label>
            <label class="sm mu" style="display:flex;gap:8px;align-items:center;margin-top:12px">
                <input v-model="form.remember" type="checkbox" class="cb"> {{ t('auth.remember') }}
            </label>
            <button class="btn btn-cu" style="width:100%;justify-content:center;margin-top:16px;padding:12px" :disabled="form.processing">
                {{ t('auth.signIn') }}
            </button>
        </form>

        <a href="/driver" class="p-row" style="margin-top:22px;text-decoration:none;color:inherit">
            <span class="ic2" style="--a:var(--gn);--a-dim:var(--gn-dim)"><AppIcon name="phone" /></span>
            <span class="tx"><b>{{ t('auth.driverLink') }}</b><span>{{ t('driver.firstSignIn') }}</span></span>
            <AppIcon name="arrow" />
        </a>
    </AuthLayout>
</template>
