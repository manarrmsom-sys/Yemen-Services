<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useWindowScroll } from '@vueuse/core';
import LanguageToggle from '@/Components/LanguageToggle.vue';
import AnnouncementBar from '@/Components/AnnouncementBar.vue';
import SmoothScroll from '@/Components/SmoothScroll.vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth?.user);
const canAccessAdmin = computed(() => ['admin', 'dispatcher'].includes(user.value?.role));

// Support number comes from WHATSAPP_BUSINESS_NUMBER (digits only).
const support = computed(() => page.props.app?.support_whatsapp || '');
const supportPretty = computed(() => support.value.replace(/^(\d{3})(\d{3})(\d{3})(\d{3})$/, '+$1 $2 $3 $4') || `+${support.value}`);

const { y } = useWindowScroll();
const scrolled = computed(() => y.value > 8);

const links = [
    ['home', 'nav.home'],
    ['pros', 'nav.pros'],
    ['join', 'nav.join'],
    ['track', 'nav.track'],
];
const tabs = computed(() => [
    ['home', 'nav.home', '⌂'],
    ['pros', 'nav.pros', '✦'],
    ['track', 'nav.track', '◎'],
    [user.value ? 'account' : 'login', 'nav.account', '☺'],
]);
</script>

<template>
    <SmoothScroll class="min-h-screen bg-ivory text-ink">
        <AnnouncementBar />

        <header
            class="sticky top-0 z-40 border-b bg-white/85 backdrop-blur-md transition-shadow duration-300"
            :class="scrolled ? 'border-ink-200 shadow-card' : 'border-transparent'"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                <Link :href="route('home')" class="flex items-center gap-2 font-extrabold text-ink">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-crimson text-white shadow-glow">خ</span>
                    <span class="hidden sm:block">{{ t('app.name') }}</span>
                </Link>

                <nav class="hidden items-center gap-1 text-sm font-semibold md:flex" :aria-label="t('nav.home')">
                    <Link
                        v-for="[name, label] in links" :key="name" :href="route(name)"
                        class="relative rounded-lg px-3 py-2 text-ink-600 transition hover:text-ink"
                        :class="route().current(name) ? 'text-ink after:absolute after:inset-x-3 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-crimson' : ''"
                    >{{ t(label) }}</Link>
                </nav>

                <div class="flex items-center gap-2">
                    <LanguageToggle />
                    <Link v-if="canAccessAdmin" :href="route('admin.home')" class="chip hidden bg-ink text-white sm:inline-flex">{{ t('nav.admin') }}</Link>
                    <Link v-if="user" :href="route('account')" class="hidden px-2 py-2 text-sm font-medium text-ink-600 hover:text-ink sm:block">{{ t('nav.account') }}</Link>
                    <Link v-else :href="route('login')" class="hidden px-2 py-2 text-sm font-medium text-ink-600 hover:text-ink sm:block">{{ t('nav.login') }}</Link>
                    <Link :href="route('book')" class="btn-primary !px-4 !py-2 text-xs">{{ t('home.cta_book') }}</Link>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="bg-ink text-white/60">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 text-sm md:grid-cols-[1.4fr_1fr_1fr_1fr]">
                <div>
                    <p class="flex items-center gap-2 font-extrabold text-white">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-crimson text-white">خ</span>
                        {{ t('app.name') }}
                    </p>
                    <p class="mt-3 max-w-xs leading-7">{{ t('app.tagline') }} · صنعاء — عدن — تعز — الحديدة — المكلا</p>
                    <div class="mt-5 flex h-1 w-16 overflow-hidden rounded-full" aria-hidden="true">
                        <span class="flex-1 bg-crimson" /><span class="flex-1 bg-white" /><span class="flex-1 bg-ink-600" />
                    </div>
                </div>
                <div>
                    <p class="font-bold text-white">{{ t('footer.legal_title') }}</p>
                    <div class="mt-3 space-y-2"><a href="#" class="block transition hover:text-white">{{ t('footer.terms') }}</a><a href="#" class="block transition hover:text-white">{{ t('footer.privacy') }}</a><a href="#" class="block transition hover:text-white">{{ t('footer.customer_rights') }}</a></div>
                </div>
                <div>
                    <p class="font-bold text-white">{{ t('footer.contact_title') }}</p>
                    <div v-if="support" class="mt-3 space-y-2"><a :href="`tel:+${support}`" class="block transition hover:text-white">{{ t('footer.customer_service') }}: <span dir="ltr">{{ supportPretty }}</span></a><a :href="`https://wa.me/${support}`" target="_blank" rel="noopener noreferrer" class="block transition hover:text-white">{{ t('footer.whatsapp_support') }}</a></div>
                </div>
                <div>
                    <p class="font-bold text-white">{{ t('footer.trust_title') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full border border-white/15 px-3 py-1 text-xs">{{ t('footer.local_payments') }}</span><span class="rounded-full border border-white/15 px-3 py-1 text-xs">{{ t('footer.secure_platform') }}</span></div>
                </div>
            </div>
            <div class="border-t border-white/10 pb-24 pt-5 text-center text-xs md:pb-5">© {{ new Date().getFullYear() }} — {{ t('app.name') }}</div>
        </footer>

        <!-- Mobile bottom nav (app-like) -->
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-ink-200 bg-white/95 pb-safe text-center text-[11px] font-semibold backdrop-blur md:hidden">
            <Link
                v-for="[name, label, icon] in tabs" :key="name" :href="route(name)"
                class="flex flex-col items-center gap-0.5 pt-2 transition"
                :class="route().current(name) ? 'text-crimson' : 'text-ink-500'"
            >
                <span class="text-base leading-none" aria-hidden="true">{{ icon }}</span>
                {{ t(label) }}
            </Link>
        </nav>
    </SmoothScroll>
</template>
