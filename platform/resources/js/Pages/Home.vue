<script setup>
import { ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import BookingDrawer from '@/Components/Booking/BookingDrawer.vue';
import Badge from '@/Components/Badge.vue';

const props = defineProps({
    governorates: { type: Array, default: () => [] },
    featuredPros: { type: Array, default: () => [] },
    openBooking: { type: Boolean, default: false },
    preselectServiceSlug: { type: String, default: null },
});

const { t, locale } = useI18n();
const page = usePage();
const drawer = ref(props.openBooking);
const catalog = () => page.props.catalog || [];
</script>

<template>
    <Head :title="t('app.tagline')" />
    <PublicLayout>
        <!-- Hero -->
        <section class="relative overflow-hidden bg-obsidian-900 text-white">
            <div class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
                <p class="chip bg-white/10 text-white">🇾🇪 {{ t('app.tagline') }}</p>
                <h1 class="mt-4 max-w-2xl text-3xl font-extrabold leading-tight sm:text-5xl">
                    {{ t('home.hero_title') }}
                </h1>
                <p class="mt-4 max-w-xl text-white/70">{{ t('home.hero_sub') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <button class="btn-primary" @click="drawer = true">{{ t('home.cta_book') }}</button>
                    <button class="btn bg-white/10 text-white hover:bg-white/20" @click="drawer = true">
                        {{ t('home.cta_estimate') }}
                    </button>
                </div>
                <div class="mt-8 flex flex-wrap gap-4 text-sm text-white/70">
                    <span>✅ {{ t('home.why_verified') }}</span>
                    <span>💰 {{ t('home.why_price') }}</span>
                    <span>⚡ {{ t('home.why_fast') }}</span>
                </div>
            </div>
            <div class="pointer-events-none absolute -end-24 -top-24 h-72 w-72 rounded-full bg-crimson/40 blur-3xl" />
        </section>

        <!-- Categories -->
        <section class="mx-auto max-w-6xl px-4 py-12">
            <h2 class="text-xl font-extrabold text-obsidian-900">{{ t('home.categories_title') }}</h2>
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <button
                    v-for="c in catalog()"
                    :key="c.id"
                    class="card p-4 text-start transition hover:-translate-y-0.5"
                    @click="drawer = true"
                >
                    <span class="grid h-10 w-10 place-items-center rounded-xl text-lg" :style="{ background: (c.accent || '#CE1126') + '1a', color: c.accent || '#CE1126' }">
                        {{ c.icon || '🛠️' }}
                    </span>
                    <p class="mt-3 font-bold text-obsidian-900">{{ c.name }}</p>
                    <p class="text-xs text-obsidian-900/50">{{ (c.services || []).length }} {{ locale === 'ar' ? 'خدمة' : 'services' }}</p>
                </button>
            </div>
        </section>

        <!-- Featured pros -->
        <section v-if="featuredPros.length" class="mx-auto max-w-6xl px-4 pb-16">
            <h2 class="text-xl font-extrabold text-obsidian-900">{{ t('nav.pros') }}</h2>
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="p in featuredPros" :key="p.id" class="card p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-bold text-obsidian-900">{{ p.display_name }}</p>
                        <span class="text-sm">⭐ {{ Number(p.rating_avg).toFixed(1) }} <span class="text-obsidian-900/40">({{ p.rating_count }})</span></span>
                    </div>
                    <p class="mt-1 text-sm text-obsidian-900/60">{{ locale === 'ar' ? p.headline_ar : p.headline_en }}</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <Badge v-for="b in (p.badges || [])" :key="b" :type="b" />
                    </div>
                </article>
            </div>
        </section>

        <BookingDrawer
            :open="drawer"
            :governorates="governorates"
            :preselect-service-slug="preselectServiceSlug"
            @close="drawer = false"
        />

        <!-- floating CTA -->
        <button class="btn-primary fixed bottom-20 end-4 z-40 shadow-xl md:bottom-8" @click="drawer = true">
            {{ t('home.cta_book') }}
        </button>
    </PublicLayout>
</template>
