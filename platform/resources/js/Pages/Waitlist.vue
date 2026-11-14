<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ZoneWaitlist from '@/Components/Booking/ZoneWaitlist.vue';
import NumberTicker from '@/Components/ui/NumberTicker.vue';

/**
 * /waitlist — public "vote for your city" page, the landing page for the
 * expansion campaign. /waitlist?city=aden preselects a governorate.
 * Shows vote counts only; who voted is never exposed.
 */
const props = defineProps({
    zones: { type: Array, default: () => [] },
    city: { type: String, default: null },
});

const { t, locale } = useI18n();
const ar = computed(() => locale.value === 'ar');

// Local copy so a vote cast here shows up at once.
const tree = ref(props.zones.map((g) => ({ ...g })));
function onJoined({ governorate_id: id, already }) {
    if (already) return;
    const g = tree.value.find((x) => x.id === id);
    if (g) g.votes += 1;
}

const board = computed(() => tree.value.filter((g) => g.status !== 'active' && g.votes > 0).sort((a, b) => b.votes - a.votes).slice(0, 8));
const top = computed(() => board.value[0]?.votes || 1);
const total = computed(() => tree.value.reduce((n, g) => n + g.votes, 0));
const live = computed(() => tree.value.filter((g) => g.status === 'active'));
</script>

<template>
    <Head>
        <title>{{ ar ? 'صوّت لمدينتك | خدمات اليمن' : 'Vote for your city | Yemen Services' }}</title>
        <meta name="description" :content="t('booking.vote_sub')" />
        <meta property="og:title" :content="t('booking.vote_title')" />
        <meta property="og:description" :content="t('booking.vote_sub')" />
        <meta property="og:image" content="/icons/icon-512.png" />
    </Head>

    <PublicLayout>
        <section class="relative overflow-hidden bg-ink text-white">
            <div class="pointer-events-none absolute -top-24 end-0 h-80 w-80 rounded-full bg-crimson/25 blur-3xl" aria-hidden="true" />
            <div class="relative mx-auto max-w-5xl px-4 py-12 sm:py-16">
                <p class="text-sm font-bold text-crimson-300">{{ ar ? 'خطة التوسع' : 'Expansion plan' }}</p>
                <h1 class="mt-3 max-w-2xl text-3xl font-black leading-tight sm:text-5xl">{{ t('booking.vote_title') }}</h1>
                <p class="mt-4 max-w-xl text-base leading-8 text-white/70">{{ t('booking.vote_sub') }}</p>
                <p v-if="total" class="mt-5 text-sm font-semibold text-white/80"><span class="text-2xl font-black text-white"><NumberTicker :value="total" /></span> {{ t('booking.vote_count') }}</p>
            </div>
        </section>

        <section class="mx-auto grid max-w-5xl gap-6 px-4 py-10 lg:grid-cols-[1fr_0.9fr] lg:items-start">
            <div class="card p-5 sm:p-6">
                <ZoneWaitlist standalone :zones="tree" :city-code="city" @joined="onJoined" />
            </div>

            <div class="space-y-4">
                <div class="card p-5">
                    <h2 class="font-extrabold text-ink">{{ t('booking.vote_board') }}</h2>
                    <ol v-if="board.length" class="mt-4 space-y-3">
                        <li v-for="(g, i) in board" :key="g.id">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="font-bold text-ink">{{ i + 1 }}. {{ g.name }}</span>
                                <span class="tabular-nums text-ink-500">{{ g.votes }} {{ t('booking.vote_count') }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full bg-crimson transition-all duration-700" :style="{ width: `${Math.max(6, (g.votes / top) * 100)}%` }" />
                            </div>
                        </li>
                    </ol>
                    <p v-else class="mt-3 text-sm text-ink-500">{{ ar ? 'كن أول من يصوّت لمدينته.' : 'Be the first to vote for your city.' }}</p>
                </div>

                <div v-if="live.length" class="card p-5">
                    <h2 class="font-extrabold text-ink">{{ t('booking.vote_live') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li v-for="g in live" :key="g.id">
                            <span class="font-bold text-ink">{{ g.name }}</span>
                            <span class="text-ink-500"> — {{ g.districts.filter((d) => d.status === 'active').map((d) => d.name).join('، ') || (ar ? 'كل الأحياء' : 'all districts') }}</span>
                        </li>
                    </ul>
                    <Link :href="route('book')" class="btn-ghost mt-4 w-full">{{ t('home.cta_book') }}</Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
