<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
const { locale } = useI18n();
</script>

<template>
    <Head title="Offline" />
    <div class="grid min-h-screen place-items-center bg-obsidian-900 p-6 text-center text-white">
        <div>
            <p class="text-5xl">📶</p>
            <h1 class="mt-4 text-xl font-extrabold">
                {{ locale === 'ar' ? 'لا يوجد اتصال بالإنترنت' : 'You are offline' }}
            </h1>
            <p class="mt-2 text-white/60">
                {{ locale === 'ar' ? 'سيعمل التطبيق تلقائياً عند عودة الاتصال.' : 'The app will resume when your connection is back.' }}
            </p>
            <button class="btn-primary mt-6" @click="$inertia.visit('/')">
                {{ locale === 'ar' ? 'إعادة المحاولة' : 'Retry' }}
            </button>
        </div>
    </div>
</template>
