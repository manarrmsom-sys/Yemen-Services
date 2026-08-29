<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const props = defineProps({ redirect: { type: String, default: '/account' } });

const { t } = useI18n();
const page = usePage();
const phase = ref('phone');

const request = useForm({ phone: '', channel: 'whatsapp' });
const verify = useForm({ session_token: '', code: '', name: '', redirect: props.redirect });

// The web OTP controller flashes { session_token, ... } back on success.
const flashedOtp = computed(() => page.props.flash?.otp);
watch(flashedOtp, (otp) => {
    if (otp?.session_token) {
        verify.session_token = otp.session_token;
        phase.value = 'code';
    }
});

const sendCode = () => request.post(route('otp.request'), { preserveScroll: true });
const submit = () => verify.post(route('otp.verify'));
</script>

<template>
    <Head :title="t('auth.login_title')" />
    <PublicLayout>
        <div class="mx-auto max-w-sm px-4 py-12">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('auth.login_title') }}</h1>

            <div v-if="phase === 'phone'" class="mt-6 space-y-4">
                <div>
                    <label class="text-sm font-semibold">{{ t('auth.phone_label') }}</label>
                    <input v-model="request.phone" dir="ltr" inputmode="tel" class="field" placeholder="+9677XXXXXXXX" />
                    <p v-if="request.errors.phone" class="mt-1 text-xs text-crimson">{{ request.errors.phone }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        v-for="c in ['whatsapp', 'sms']" :key="c" type="button"
                        class="chip flex-1 justify-center py-2"
                        :class="request.channel === c ? 'bg-crimson text-white' : 'bg-obsidian-100'"
                        @click="request.channel = c"
                    >{{ t(`auth.channel_${c}`) }}</button>
                </div>
                <button class="btn-primary w-full" :disabled="request.processing" @click="sendCode">
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
                <button class="btn-primary w-full" :disabled="verify.processing" @click="submit">{{ t('auth.verify') }}</button>
                <button type="button" class="text-sm text-crimson" @click="phase = 'phone'">{{ t('auth.resend') }}</button>
            </div>
        </div>
    </PublicLayout>
</template>
