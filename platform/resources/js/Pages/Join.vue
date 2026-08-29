<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({ categories: { type: Array, default: () => [] }, governorates: { type: Array, default: () => [] } });
const { t, locale } = useI18n();
</script>

<template>
    <Head :title="t('nav.join')" />
    <PublicLayout>
        <div class="mx-auto max-w-2xl px-4 py-12">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('nav.join') }}</h1>
            <p class="mt-2 text-obsidian-900/60">
                {{ locale === 'ar'
                    ? 'سجّل الدخول برقم جوالك، أكمل ملفك، وارفع مستنداتك للتوثيق. بعد المراجعة تحصل على شارة "موثّق" وتبدأ باستقبال الطلبات.'
                    : 'Sign in with your phone, complete your profile, and upload your documents for verification. Once reviewed you get the “Verified” badge and start receiving jobs.' }}
            </p>
            <ol class="mt-6 space-y-3 text-sm">
                <li class="card p-4">1 — {{ locale === 'ar' ? 'دخول سريع عبر رمز واتساب/‏SMS' : 'Fast OTP sign-in (WhatsApp / SMS)' }}</li>
                <li class="card p-4">2 — {{ locale === 'ar' ? 'إنشاء ملف الفني وتحديد المحافظات' : 'Create your pro profile & pick governorates' }}</li>
                <li class="card p-4">3 — {{ locale === 'ar' ? 'رفع الهوية + شهادة المهنة (KYC)' : 'Upload ID + trade certificate (KYC)' }}</li>
                <li class="card p-4">4 — {{ locale === 'ar' ? 'شحن رصيد العمولة والبدء' : 'Top up your commission balance & go live' }}</li>
            </ol>
            <Link :href="route('login', { redirect: '/trade' })" class="btn-primary mt-6 w-full">
                {{ t('nav.login') }}
            </Link>
        </div>
    </PublicLayout>
</template>
