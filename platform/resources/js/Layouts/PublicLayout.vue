<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageToggle from '@/Components/LanguageToggle.vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="min-h-screen bg-offwhite">
        <header class="sticky top-0 z-40 border-b border-obsidian-100 bg-white/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <Link :href="route('home')" class="flex items-center gap-2 font-extrabold text-obsidian-900">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-crimson text-white">خ</span>
                    <span class="hidden sm:block">{{ t('app.name') }}</span>
                </Link>

                <nav class="hidden items-center gap-1 text-sm font-semibold md:flex">
                    <Link :href="route('home')" class="rounded-lg px-3 py-2 hover:bg-obsidian-100">{{ t('nav.home') }}</Link>
                    <Link :href="route('pros')" class="rounded-lg px-3 py-2 hover:bg-obsidian-100">{{ t('nav.pros') }}</Link>
                    <Link :href="route('join')" class="rounded-lg px-3 py-2 hover:bg-obsidian-100">{{ t('nav.join') }}</Link>
                    <Link :href="route('track')" class="rounded-lg px-3 py-2 hover:bg-obsidian-100">{{ t('nav.track') }}</Link>
                </nav>

                <div class="flex items-center gap-2">
                    <LanguageToggle />
                    <Link v-if="user && user.is_staff" :href="route('admin.home')" class="chip bg-obsidian-900 text-white">
                        {{ t('nav.admin') }}
                    </Link>
                    <Link v-if="user" :href="route('account')" class="btn-ghost !py-2 !px-3 text-xs">{{ t('nav.account') }}</Link>
                    <Link v-else :href="route('login')" class="btn-primary !py-2 !px-3 text-xs">{{ t('nav.login') }}</Link>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="mt-16 border-t border-obsidian-100 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-obsidian-900/60">
                <p class="font-bold text-obsidian-900">{{ t('app.name') }}</p>
                <p>{{ t('app.tagline') }} · صنعاء — عدن — تعز — الحديدة</p>
                <p class="mt-2">© {{ new Date().getFullYear() }} — {{ t('app.name') }}</p>
            </div>
        </footer>

        <!-- Mobile bottom nav (app-like) -->
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-obsidian-100 bg-white pb-safe text-center text-xs md:hidden">
            <Link :href="route('home')" class="py-2">{{ t('nav.home') }}</Link>
            <Link :href="route('pros')" class="py-2">{{ t('nav.pros') }}</Link>
            <Link :href="route('track')" class="py-2">{{ t('nav.track') }}</Link>
            <Link :href="user ? route('account') : route('login')" class="py-2">{{ t('nav.account') }}</Link>
        </nav>
    </div>
</template>
