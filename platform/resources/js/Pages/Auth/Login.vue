<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({ redirect: { type: String, default: '/account' } });

const { t } = useI18n();
const page = usePage();
const phase = ref('email');
const csrfToken = page.props.csrf_token
    || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    || '';

const request = useForm({ _token: csrfToken, email: '', channel: 'email' });
const verify = useForm({ _token: csrfToken, session_token: '', code: '', name: '', redirect: props.redirect });
const requestProcessing = ref(false);
const verifyProcessing = ref(false);

// The web OTP controller flashes { session_token, ... } back on success.
const flashedOtp = computed(() => page.props.flash?.otp);
watch(flashedOtp, (otp) => {
    if (otp?.session_token) {
        verify.session_token = otp.session_token;
        phase.value = 'code';
    }
});

const initializeCsrf = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true });
const sendCode = async () => {
    await initializeCsrf();
    requestProcessing.value = true;
    request.clearErrors();
    router.post(route('otp.request'), {
        _token: csrfToken,
        email: request.email,
        channel: request.channel,
    }, {
        preserveScroll: true,
        onError: (errors) => request.setError(errors),
        onFinish: () => { requestProcessing.value = false; },
    });
};
const submit = async () => {
    await initializeCsrf();
    verifyProcessing.value = true;
    verify.clearErrors();
    router.post(route('otp.verify'), {
        _token: csrfToken,
        session_token: verify.session_token,
        code: verify.code,
        name: verify.name,
        redirect: verify.redirect,
    }, {
        onError: (errors) => verify.setError(errors),
        onFinish: () => { verifyProcessing.value = false; },
    });
};
</script>

<template>
    <Head :title="t('auth.login_title')" />
    <PublicLayout>
        <div class="mx-auto max-w-sm px-4 py-12">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('auth.login_title') }}</h1>

            <div v-if="phase === 'email'" class="mt-6 space-y-4">
                <div>
                    <label class="text-sm font-semibold">{{ t('auth.email_label') }}</label>
                    <input v-model="request.email" dir="ltr" type="email" autocomplete="email" class="field" placeholder="you@example.com" />
                    <p v-if="request.errors.email" class="mt-1 text-xs text-crimson">{{ request.errors.email }}</p>
                </div>
                <button class="btn-primary w-full" :disabled="requestProcessing" @click="sendCode">
                    {{ t('auth.send_code') }}
                </button>
            </div>

            <div v-else class="mt-6 space-y-4">
                <div>
                    <label class="text-sm font-semibold">{{ t('auth.code_label') }}</label>
                    <input v-model="verify.code" dir="ltr" inputmode="numeric" class="field text-center text-lg tracking-[0.4em]" />
                    <p v-if="verify.errors.code" class="mt-1 text-xs text-crimson">{{ verify.errors.code }}</p>
                </div>
                <div>
                    <label class="text-sm font-semibold">{{ t('auth.name_optional') }}</label>
                    <input v-model="verify.name" class="field" />
                </div>
                <button class="btn-primary w-full" :disabled="verifyProcessing" @click="submit">{{ t('auth.verify') }}</button>
                <button type="button" class="text-sm text-crimson" @click="phase = 'email'">{{ t('auth.resend') }}</button>
            </div>
        </div>
    </PublicLayout>
</template>
