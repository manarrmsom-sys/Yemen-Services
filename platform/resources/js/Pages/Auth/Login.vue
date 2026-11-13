<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({ redirect: { type: String, default: '/account' } });

const { t } = useI18n();
const page = usePage();
const csrfToken = page.props.csrf_token
    || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    || '';

// Only the channels the server has switched on are offered (OTP_CHANNEL_*_ENABLED).
const channels = computed(() => {
    const enabled = page.props.app?.otp?.channels || ['email'];
    return ['email', 'whatsapp', 'sms'].filter((c) => enabled.includes(c));
});
const initial = channels.value.includes(page.props.app?.otp?.default) ? page.props.app.otp.default : channels.value[0];

const phase = ref('identity');
const request = useForm({ _token: csrfToken, email: '', phone: '', channel: initial });
const verify = useForm({ _token: csrfToken, session_token: '', code: '', name: '', redirect: props.redirect });
const requestProcessing = ref(false);
const verifyProcessing = ref(false);
const usesEmail = computed(() => request.channel === 'email');

// The web OTP controller flashes { session_token, ... } back on success.
const flashedOtp = computed(() => page.props.flash?.otp);
watch(flashedOtp, (otp) => {
    if (otp?.session_token) {
        verify.session_token = otp.session_token;
        verify.code = '';
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
        channel: request.channel,
        // Send only the identity that matches the chosen channel.
        ...(usesEmail.value ? { email: request.email } : { phone: request.phone }),
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
    <Head :title="t('auth.login_heading')" />
    <PublicLayout>
        <div class="mx-auto max-w-sm px-4 py-12">
            <h1 class="text-2xl font-extrabold text-ink">{{ t('auth.login_heading') }}</h1>
            <p class="mt-2 text-sm text-ink-500">{{ t('auth.login_sub') }}</p>

            <form v-if="phase === 'identity'" class="mt-6 space-y-4" @submit.prevent="sendCode">
                <div v-if="channels.length > 1" class="grid gap-1.5 rounded-xl bg-ink-100 p-1" :style="{ gridTemplateColumns: `repeat(${channels.length}, minmax(0, 1fr))` }" role="tablist">
                    <button
                        v-for="c in channels" :key="c" type="button" role="tab" :aria-selected="request.channel === c"
                        class="rounded-lg px-2 py-2 text-sm font-bold transition"
                        :class="request.channel === c ? 'bg-white text-ink shadow-sm' : 'text-ink-500 hover:text-ink'"
                        @click="request.channel = c; request.clearErrors()"
                    >{{ t(`auth.channel_${c}`) }}</button>
                </div>

                <div v-if="usesEmail">
                    <label class="text-sm font-semibold" for="login-email">{{ t('auth.email_label') }}</label>
                    <input id="login-email" v-model="request.email" dir="ltr" type="email" autocomplete="email" class="field" placeholder="you@example.com" required />
                    <p v-if="request.errors.email" class="mt-1 text-xs text-crimson">{{ request.errors.email }}</p>
                </div>
                <div v-else>
                    <label class="text-sm font-semibold" for="login-phone">{{ t(request.channel === 'sms' ? 'auth.phone_label_sms' : 'auth.phone_label') }}</label>
                    <input id="login-phone" v-model="request.phone" dir="ltr" type="tel" inputmode="tel" autocomplete="tel" class="field" placeholder="+967 7XX XXX XXX" required />
                    <p v-if="request.errors.phone" class="mt-1 text-xs text-crimson">{{ request.errors.phone }}</p>
                </div>

                <button type="submit" class="btn-primary w-full" :disabled="requestProcessing">{{ t('auth.send_code') }}</button>
            </form>

            <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
                <p class="rounded-xl bg-ink-100 px-3 py-2 text-sm text-ink-600">{{ t(`auth.code_sent_${request.channel}`) }}</p>
                <div>
                    <label class="text-sm font-semibold" for="login-code">{{ t('auth.code_label') }}</label>
                    <input id="login-code" v-model="verify.code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" class="field text-center text-lg tracking-[0.4em]" required />
                    <p v-if="verify.errors.code" class="mt-1 text-xs text-crimson">{{ verify.errors.code }}</p>
                </div>
                <div>
                    <label class="text-sm font-semibold" for="login-name">{{ t('auth.name_optional') }}</label>
                    <input id="login-name" v-model="verify.name" autocomplete="name" class="field" />
                </div>
                <button type="submit" class="btn-primary w-full" :disabled="verifyProcessing">{{ t('auth.verify') }}</button>
                <button type="button" class="text-sm font-semibold text-crimson" @click="phase = 'identity'">{{ t('auth.resend') }}</button>
            </form>
        </div>
    </PublicLayout>
</template>
