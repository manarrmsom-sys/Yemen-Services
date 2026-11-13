<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageToggle from '@/Components/LanguageToggle.vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth?.user);
const canAccessAdmin = computed(() => ['admin', 'dispatcher'].includes(user.value?.role));
</script>

<template>
    <div class="min-h-screen bg-slate-50">
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
                    <Link :href="route('book')" class="btn-primary !px-3 !py-2 text-xs font-bold">{{ t('home.cta_book') }}</Link>
                    <Link v-if="canAccessAdmin" :href="route('admin.categories')" class="chip bg-obsidian-900 text-white">
                        {{ t('nav.admin') }}
                    </Link>
                    <Link v-if="user" :href="route('account')" class="px-2 py-2 text-sm font-medium text-obsidian-900/65 hover:text-obsidian-900">{{ t('nav.account') }}</Link>
                    <Link v-else :href="route('login')" class="px-2 py-2 text-sm font-medium text-obsidian-900/65 hover:text-obsidian-900">{{ t('nav.login') }}</Link>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 text-sm text-obsidian-900/60 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
                <div>
                    <p class="font-bold text-obsidian-900">{{ t('app.name') }}</p>
                    <p class="mt-2 max-w-xs leading-7">{{ t('app.tagline') }} · صنعاء — عدن — تعز — الحديدة — المكلا</p>
                    <p class="mt-5 text-xs">© {{ new Date().getFullYear() }} — {{ t('app.name') }}</p>
                </div>
                <div>
                    <p class="font-bold text-obsidian-900">{{ t('footer.legal_title') }}</p>
                    <div class="mt-3 space-y-2"><a href="#" class="block hover:text-crimson">{{ t('footer.terms') }}</a><a href="#" class="block hover:text-crimson">{{ t('footer.privacy') }}</a><a href="#" class="block hover:text-crimson">{{ t('footer.customer_rights') }}</a></div>
                </div>
                <div>
                    <p class="font-bold text-obsidian-900">{{ t('footer.contact_title') }}</p>
                    <div class="mt-3 space-y-2"><a href="tel:+967700000000" class="block hover:text-crimson">{{ t('footer.customer_service') }}: +967 700 000 000</a><a href="https://wa.me/967700000000" class="block hover:text-crimson">{{ t('footer.whatsapp_support') }}</a></div>
                </div>
                <div>
                    <p class="font-bold text-obsidian-900">{{ t('footer.trust_title') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2"><span class="border border-slate-200 px-2.5 py-1 text-xs">{{ t('footer.local_payments') }}</span><span class="border border-slate-200 px-2.5 py-1 text-xs">{{ t('footer.secure_platform') }}</span></div>
                </div>
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
