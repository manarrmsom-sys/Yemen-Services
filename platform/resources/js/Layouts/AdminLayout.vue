<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LanguageToggle from '@/Components/LanguageToggle.vue';

const { t } = useI18n();
const page = usePage();

const nav = [
    ['admin.home', 'admin.dashboard', '📊'],
    ['admin.orders', 'admin.orders', '📦'],
    ['admin.dispatch', 'admin.dispatch', '🛰️'],
    ['admin.kyc', 'admin.kyc', '🪪'],
    ['admin.wallets', 'admin.wallets', '💳'],
    ['admin.categories', 'admin.categories', '🗂️'],
    ['admin.services', 'admin.services', '🛠️'],
    ['admin.pros', 'admin.pros', '👥'],
    ['admin.zones', 'admin.zones', '📍'],
    ['admin.b2b', 'admin.b2b', '🏢'],
    ['admin.appearance', 'admin.appearance', '🎨'],
    ['admin.integrations', 'admin.integrations', '🔌'],
];
</script>

<template>
    <div class="flex min-h-screen bg-offwhite">
        <aside class="hidden w-60 shrink-0 flex-col border-e border-obsidian-100 bg-white p-4 md:flex">
            <Link :href="route('home')" class="mb-6 flex items-center gap-2 font-extrabold">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-crimson text-white">خ</span>
                {{ t('app.name') }}
            </Link>
            <nav class="space-y-1 text-sm font-semibold">
                <Link
                    v-for="[name, label, icon] in nav" :key="name" :href="route(name)"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 hover:bg-obsidian-100"
                    :class="route().current(name) ? 'bg-crimson-50 text-crimson-700' : ''"
                >
                    <span>{{ icon }}</span> {{ t(label) }}
                </Link>
            </nav>
        </aside>

        <div class="min-w-0 flex-1">
            <header class="flex items-center justify-between border-b border-obsidian-100 bg-white px-4 py-3">
                <p class="font-bold text-obsidian-900">{{ $slots.title ? '' : '' }}<slot name="title" /></p>
                <div class="flex items-center gap-2">
                    <LanguageToggle />
                    <span class="text-sm text-obsidian-900/60">{{ page.props.auth?.user?.name || page.props.auth?.user?.phone }}</span>
                    <Link href="/logout" method="post" as="button" class="chip bg-obsidian-100">{{ t('nav.logout') }}</Link>
                </div>
            </header>
            <main class="p-4 sm:p-6"><slot /></main>
        </div>
    </div>
</template>
