<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import BookingDrawer from '@/Components/Booking/BookingDrawer.vue';
import Badge from '@/Components/Badge.vue';
import PriceEstimator from '@/Components/Home/PriceEstimator.vue';
import HeroBackdrop from '@/Components/ui/HeroBackdrop.vue';
import NumberTicker from '@/Components/ui/NumberTicker.vue';
import SpotlightCard from '@/Components/ui/SpotlightCard.vue';
import B2bRequestModal from '@/Components/Home/B2bRequestModal.vue';
import { useScrollReveal } from '@/composables/useScrollReveal';
import { scrollToTarget, setScrollLocked } from '@/composables/useSmoothScroll';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    governorates: { type: Array, default: () => [] },
    featuredPros: { type: Array, default: () => [] },
    featuredServices: { type: Array, default: () => [] },
    banners: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    openBooking: { type: Boolean, default: false },
    preselectServiceSlug: { type: String, default: null },
});

const { t, locale } = useI18n();
const { format } = useMoney();
const page = usePage();
const ar = computed(() => locale.value === 'ar');
const catalog = computed(() => page.props.catalog || []);

// ---- booking drawer ---------------------------------------------------
const drawer = ref(props.openBooking);
const bookingSlug = ref(props.preselectServiceSlug);

function book(slug = null) {
    bookingSlug.value = slug || null;
    drawer.value = true;
}
// The drawer owns the scroll while it is open.
// ---- B2B maintenance contracts ------------------------------------------
const b2bOpen = ref(false);
const b2bSectors = computed(() => [
    { icon: '🏨', label: ar.value ? 'الفنادق' : 'Hotels' },
    { icon: '🍽️', label: ar.value ? 'المطاعم' : 'Restaurants' },
    { icon: '🏥', label: ar.value ? 'العيادات' : 'Clinics' },
    { icon: '🏘️', label: ar.value ? 'المجمعات السكنية' : 'Residential compounds' },
]);

// A modal owns the scroll while it is open.
watch([drawer, b2bOpen], ([booking, b2b]) => setScrollLocked(booking || b2b));

// ---- category search --------------------------------------------------
const query = ref('');
const filteredCatalog = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();
    if (!term) return catalog.value;

    return catalog.value.filter((category) => [
        category.name,
        category.slug,
        ...(category.services || []).map((s) => `${s.name} ${s.slug}`),
    ].join(' ').toLocaleLowerCase().includes(term));
});

const fromPrice = (category) => {
    const prices = (category.services || [])
        .map((s) => ({ hourly: s.hourly_rate, per_unit: s.unit_price, quote: null }[s.pricing_model] ?? s.base_price))
        .filter((p) => Number(p) > 0);
    return prices.length ? Math.min(...prices) : null;
};

// ---- static copy ------------------------------------------------------
const steps = computed(() => [
    { icon: '①', title: t('booking.step_service'), body: ar.value ? 'اختر الخدمة وشاهد السعر التقديري فوراً.' : 'Pick a service and see the estimate instantly.' },
    { icon: '②', title: t('booking.step_where'), body: ar.value ? 'حدّد المحافظة والحي والموعد المناسب.' : 'Set the governorate, district and time.' },
    { icon: '③', title: t('booking.step_contact'), body: ar.value ? 'أكّد برقمك — والدفع نقداً عند الإنجاز.' : 'Confirm with your number. Pay cash on completion.' },
]);

const statItems = computed(() => [
    { value: props.stats.verified_pros, label: ar.value ? 'فني موثّق' : 'verified pros' },
    { value: props.stats.services, label: ar.value ? 'خدمة متاحة' : 'services' },
    { value: props.stats.governorates, label: ar.value ? 'محافظة مغطّاة' : 'governorates covered' },
    { value: props.stats.completed_orders, label: ar.value ? 'طلب مُنجز' : 'completed orders' },
].filter((s) => Number(s.value) > 0));

const priceUnit = (service) => ({
    hourly: ar.value ? '/ ساعة' : '/ hour',
    per_unit: service.unit_label ? `/ ${service.unit_label}` : '',
}[service.pricing_model] || '');

const bannerTone = {
    ink: 'bg-ink text-white',
    crimson: 'bg-crimson text-white',
    ivory: 'bg-white text-ink ring-1 ring-ink-200',
};
const bannerCta = {
    ink: 'bg-white text-ink hover:bg-ink-100',
    crimson: 'bg-white text-crimson hover:bg-crimson-50',
    ivory: 'bg-crimson text-white hover:bg-crimson-600',
};
const isInternal = (href) => typeof href === 'string' && href.startsWith('/');

// ---- scroll animations ------------------------------------------------
const root = ref(null);
const { refresh } = useScrollReveal(root);
watch(filteredCatalog, refresh);

// ---- SEO --------------------------------------------------------------
const schema = computed(() => JSON.stringify({
    '@context': 'https://schema.org',
    '@graph': [{
        '@type': 'LocalBusiness',
        '@id': `${window.location.origin}/#platform`,
        name: ar.value ? 'خدمات اليمن' : 'Yemen Services',
        description: ar.value ? 'سوق الخدمات الموثوقة في المدن اليمنية.' : 'A trusted services marketplace across Yemen.',
        url: window.location.origin,
        areaServed: props.governorates.map((g) => g.name),
    }, ...props.featuredPros.slice(0, 6).map((pro) => ({
        '@type': 'Person',
        name: pro.display_name,
        jobTitle: ar.value ? pro.headline_ar : pro.headline_en,
        ...(pro.rating_count ? {
            aggregateRating: {
                '@type': 'AggregateRating',
                ratingValue: Number(pro.rating_avg).toFixed(1),
                ratingCount: pro.rating_count,
            },
        } : {}),
    }))],
}));
</script>

<template>
    <Head>
        <title>{{ ar ? 'خدمات اليمن | سوق الخدمات الموثوقة' : 'Yemen Services | Trusted services marketplace' }}</title>
        <meta name="description" :content="ar ? 'اطلب خدمات منزلية وتجارية ورقمية — سباكة وكهرباء وتصميم وبرمجة ومونتاج — من محترفين موثّقين في اليمن، بسعر واضح قبل التأكيد.' : 'Book verified home, commercial and digital services across Yemen — plumbing, electrical, design, development, video editing — with a clear price before you confirm.'" />
        <meta name="robots" content="index,follow,max-image-preview:large" />
        <meta property="og:type" content="website" />
        <meta property="og:site_name" content="خدمات اليمن | Yemen Services" />
        <meta property="og:title" :content="ar ? 'خدمات اليمن | كل خدمة تبدأ بثقة' : 'Yemen Services | Every service starts with trust'" />
        <meta property="og:description" :content="ar ? 'فني موثوق، سعر واضح، وحجز في ثلاث خطوات.' : 'A trusted pro, a clear price, booked in three steps.'" />
        <meta property="og:url" :content="page.url" />
        <meta property="og:image" content="/icons/icon-512.png" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="خدمات اليمن | Yemen Services" />
        <meta name="twitter:image" content="/icons/icon-512.png" />
        <link rel="canonical" :href="page.url" />
        <script type="application/ld+json">{{ schema }}</script>
    </Head>

    <PublicLayout>
        <div ref="root">
            <!-- ============ HERO ============ -->
            <section class="relative overflow-hidden bg-ivory">
                <HeroBackdrop />

                <div class="relative mx-auto grid max-w-6xl gap-10 px-4 pb-14 pt-10 sm:pt-16 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:gap-14 lg:pb-20">
                    <div>
                        <p class="inline-flex animate-rise-in items-center gap-2 rounded-full border border-ink-200 bg-white px-3 py-1.5 text-xs font-bold text-ink-600 shadow-sm">
                            <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-crimson opacity-60" /><span class="relative inline-flex h-2 w-2 rounded-full bg-crimson" /></span>
                            {{ t('app.tagline') }}
                        </p>

                        <h1 class="mt-5 animate-rise-in text-[2.5rem] font-black leading-[1.15] text-ink [animation-delay:80ms] sm:text-6xl sm:leading-[1.1]">
                            <template v-if="ar">فني <span class="relative whitespace-nowrap text-crimson">موثوق<svg class="absolute -bottom-2 start-0 h-3 w-full text-crimson/30" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true"><path d="M2 9 Q 50 1 100 6 T 198 4" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" /></svg></span> يصل إليك خلال دقائق</template>
                            <template v-else>A <span class="relative whitespace-nowrap text-crimson">trusted<svg class="absolute -bottom-2 start-0 h-3 w-full text-crimson/30" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true"><path d="M2 9 Q 50 1 100 6 T 198 4" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" /></svg></span> pro at your door in minutes</template>
                        </h1>

                        <p class="mt-5 max-w-xl animate-rise-in text-base leading-8 text-ink-600 [animation-delay:160ms] sm:text-lg">{{ t('home.hero_sub') }}</p>

                        <div class="mt-7 flex animate-rise-in flex-wrap items-center gap-3 [animation-delay:240ms]">
                            <button type="button" class="btn-primary !px-7 !py-4 text-base" @click="book()">
                                {{ ar ? 'ابدأ الحجز السريع' : 'Start quick booking' }}
                                <span aria-hidden="true" class="rtl:rotate-180">→</span>
                            </button>
                            <button type="button" class="btn border border-ink-200 bg-white !px-6 !py-4 text-base text-ink hover:border-ink lg:hidden" @click="scrollToTarget('#estimator')">{{ t('home.cta_estimate') }}</button>
                            <Link :href="route('pros')" class="btn hidden border border-ink-200 bg-white !px-6 !py-4 text-base text-ink hover:border-ink lg:inline-flex">{{ ar ? 'تصفّح الفنيين' : 'Browse pros' }}</Link>
                        </div>

                        <!-- the 3 booking steps, inline -->
                        <ol class="mt-8 grid animate-rise-in grid-cols-3 gap-2 [animation-delay:320ms] sm:max-w-lg">
                            <li v-for="(step, i) in steps" :key="i" class="relative rounded-2xl border border-ink-200/80 bg-white/80 p-3 backdrop-blur">
                                <span class="grid h-7 w-7 place-items-center rounded-full bg-ink text-xs font-black text-white">{{ i + 1 }}</span>
                                <p class="mt-2 text-xs font-extrabold text-ink sm:text-sm">{{ step.title }}</p>
                            </li>
                        </ol>

                        <ul class="mt-6 flex animate-rise-in flex-wrap gap-x-5 gap-y-2 text-sm font-semibold text-ink-600 [animation-delay:400ms]">
                            <li v-for="key in ['why_verified', 'why_price', 'why_fast']" :key="key" class="flex items-center gap-1.5">
                                <span class="grid h-4 w-4 place-items-center rounded-full bg-crimson/10 text-[10px] text-crimson" aria-hidden="true">✓</span>{{ t(`home.${key}`) }}
                            </li>
                        </ul>
                    </div>

                    <div id="estimator" class="animate-rise-in scroll-mt-24 [animation-delay:200ms]">
                        <PriceEstimator :governorates="governorates" @book="book" />
                    </div>
                </div>

                <!-- real platform numbers -->
                <div v-if="statItems.length" class="relative border-y border-ink-200 bg-white">
                    <dl class="mx-auto grid max-w-6xl grid-cols-2 divide-ink-200 px-4 sm:grid-cols-4 sm:divide-x sm:rtl:divide-x-reverse">
                        <div v-for="stat in statItems" :key="stat.label" class="px-2 py-5 text-center">
                            <dd class="text-3xl font-black text-ink"><NumberTicker :value="Number(stat.value)" /></dd>
                            <dt class="mt-1 text-xs font-semibold text-ink-500">{{ stat.label }}</dt>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- ============ VOTE FOR YOUR CITY ============ -->
            <section class="mx-auto max-w-6xl px-4 pt-10">
                <Link :href="route('waitlist')" class="group flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-ink-200 bg-white px-5 py-4 transition hover:border-crimson/40 hover:shadow-lift" data-reveal>
                    <span class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-crimson/10 text-xl" aria-hidden="true">📍</span>
                        <span>
                            <span class="block font-extrabold text-ink">{{ t('booking.vote_cta') }}</span>
                            <span class="block text-sm text-ink-500">{{ t('booking.vote_cta_sub') }}</span>
                        </span>
                    </span>
                    <span class="btn-primary !px-5 !py-2.5">{{ t('booking.vote_submit') }} <span aria-hidden="true" class="rtl:rotate-180">→</span></span>
                </Link>
            </section>

            <!-- ============ PROMO BANNERS (Admin → Appearance) ============ -->
            <section v-if="banners.length" class="mx-auto max-w-6xl px-4 pt-12 sm:pt-16">
                <div class="grid gap-4" :class="banners.length > 1 ? 'md:grid-cols-2' : ''" data-reveal-group>
                    <article v-for="(banner, i) in banners" :key="i" class="relative isolate flex min-h-44 flex-col justify-between overflow-hidden rounded-3xl p-6 sm:p-8" :class="bannerTone[banner.tone]">
                        <img v-if="banner.image_url" :src="banner.image_url" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-25" loading="lazy" decoding="async" />
                        <span class="absolute -end-10 -top-10 -z-10 h-40 w-40 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
                        <div>
                            <h3 class="text-xl font-black sm:text-2xl">{{ banner.title }}</h3>
                            <p v-if="banner.body" class="mt-2 max-w-md text-sm leading-7 opacity-80">{{ banner.body }}</p>
                        </div>
                        <component
                            :is="isInternal(banner.href) ? Link : 'a'"
                            v-if="banner.cta && banner.href"
                            :href="banner.href"
                            :rel="isInternal(banner.href) ? undefined : 'noopener noreferrer'"
                            class="btn mt-5 self-start !px-5 !py-2.5"
                            :class="bannerCta[banner.tone]"
                        >{{ banner.cta }} <span aria-hidden="true" class="rtl:rotate-180">→</span></component>
                    </article>
                </div>
            </section>

            <!-- ============ CATEGORIES ============ -->
            <section class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end" data-reveal>
                    <div>
                        <p class="text-sm font-bold text-crimson">01 / {{ ar ? 'التصنيفات' : 'Categories' }}</p>
                        <h2 class="mt-2 text-3xl font-black text-ink sm:text-4xl">{{ t('home.categories_title') }}</h2>
                    </div>
                    <label class="relative block w-full sm:max-w-xs">
                        <span class="sr-only">{{ t('common.search') }}</span>
                        <span class="pointer-events-none absolute inset-y-0 start-3 grid place-items-center text-ink-400" aria-hidden="true">⌕</span>
                        <input v-model="query" type="search" class="field !bg-white !ps-9" :placeholder="ar ? 'ابحث: سباكة، تصميم، برمجة، مونتاج...' : 'Search: plumbing, design, development, editing...'" />
                    </label>
                </div>

                <div v-if="filteredCatalog.length" class="mt-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-reveal-group>
                    <SpotlightCard
                        v-for="(category, index) in filteredCatalog" :key="category.id"
                        as="button" type="button"
                        class="flex min-h-40 flex-col justify-between p-4 text-start sm:min-h-44 sm:p-5"
                        @click="book(category.services?.[0]?.slug)"
                    >
                        <img v-if="category.image_url" :src="category.image_url" alt="" class="absolute inset-0 h-full w-full object-cover opacity-[0.08] transition duration-500 group-hover:scale-105 group-hover:opacity-[0.16]" loading="lazy" decoding="async" />
                        <span class="relative grid h-11 w-11 place-items-center rounded-xl bg-ink text-xl text-white transition duration-300 group-hover:bg-crimson">{{ category.icon || ['⌂', '◈', '◎', '✦'][index % 4] }}</span>
                        <span class="relative mt-6 block">
                            <span class="block font-extrabold text-ink">{{ category.name }}</span>
                            <span class="mt-1 block text-xs text-ink-500">{{ category.services?.length || 0 }} {{ ar ? 'خدمة' : 'services' }}<template v-if="category.is_remote"> · {{ ar ? 'عن بُعد' : 'remote' }}</template></span>
                            <span v-if="fromPrice(category)" class="mt-2 block text-xs font-bold text-crimson">{{ ar ? 'يبدأ من' : 'From' }} {{ format(fromPrice(category)) }}</span>
                        </span>
                    </SpotlightCard>
                </div>
                <p v-else class="mt-8 rounded-2xl border border-dashed border-ink-200 bg-white p-8 text-center text-sm text-ink-500">
                    {{ ar ? 'لا توجد نتائج مطابقة. جرّب كلمة أخرى.' : 'No matching categories. Try another word.' }}
                </p>
            </section>

            <!-- ============ MOST REQUESTED SERVICES ============ -->
            <section v-if="featuredServices.length" class="border-y border-ink-200 bg-white">
                <div class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
                    <div class="max-w-2xl" data-reveal>
                        <p class="text-sm font-bold text-crimson">02 / {{ ar ? 'الأكثر طلباً' : 'Most requested' }}</p>
                        <h2 class="mt-2 text-3xl font-black text-ink sm:text-4xl">{{ ar ? 'خدمات يطلبها الناس كل يوم' : 'Services people book every day' }}</h2>
                        <p class="mt-3 text-sm leading-7 text-ink-500">{{ ar ? 'السعر المعروض تقديري ويشمل تفاصيله قبل التأكيد.' : 'Prices shown are estimates, itemised before you confirm.' }}</p>
                    </div>

                    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
                        <SpotlightCard v-for="service in featuredServices" :key="service.id" as="article" class="flex flex-col p-5">
                            <div class="relative flex items-start justify-between gap-3">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-ink-100 text-2xl transition duration-300 group-hover:bg-crimson/10">{{ service.category.icon || '✦' }}</span>
                                <span class="rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-bold text-ink-600">{{ service.category.name }}</span>
                            </div>

                            <h3 class="relative mt-4 text-lg font-extrabold text-ink">{{ service.name }}</h3>
                            <p v-if="service.description" class="relative mt-1 line-clamp-2 text-sm leading-6 text-ink-500">{{ service.description }}</p>

                            <div class="relative mt-4 flex items-baseline gap-1.5">
                                <template v-if="service.from_price">
                                    <span class="text-xs font-semibold text-ink-500">{{ ar ? 'يبدأ من' : 'From' }}</span>
                                    <span class="text-2xl font-black text-ink">{{ format(service.from_price) }}</span>
                                    <span class="text-xs text-ink-500">{{ priceUnit(service) }}</span>
                                </template>
                                <span v-else class="text-sm font-bold text-ink">{{ ar ? 'السعر بعد المعاينة' : 'Price after inspection' }}</span>
                            </div>

                            <ul class="relative mt-4 flex flex-wrap gap-1.5">
                                <li class="badge-kyc"><span aria-hidden="true">✓</span>{{ ar ? 'فنيون موثّقون KYC' : 'KYC-verified pros' }}</li>
                                <li class="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-bold text-ink-600"><span aria-hidden="true">🛡</span>{{ t('footer.customer_rights') }}</li>
                                <li class="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-bold text-ink-600">{{ service.category.is_remote ? (ar ? 'تنفيذ عن بُعد' : 'Delivered online') : t('booking.payment_cod') }}</li>
                            </ul>

                            <button type="button" class="relative mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-ink px-4 py-3 text-sm font-bold text-ink transition hover:border-crimson hover:bg-crimson hover:text-white" @click="book(service.slug)">
                                {{ ar ? 'احجز الآن' : 'Book now' }} <span aria-hidden="true" class="rtl:rotate-180">→</span>
                            </button>
                        </SpotlightCard>
                    </div>
                </div>
            </section>

            <!-- ============ HOW IT WORKS (the 30% ink block) ============ -->
            <section class="relative overflow-hidden bg-ink text-white">
                <div class="pointer-events-none absolute -top-24 end-0 h-80 w-80 rounded-full bg-crimson/20 blur-3xl" data-parallax="-25" aria-hidden="true" />
                <div class="relative mx-auto max-w-6xl px-4 py-16 sm:py-24">
                    <div class="max-w-xl" data-reveal>
                        <p class="text-sm font-bold text-crimson-300">03 / {{ ar ? 'كيف تعمل المنصة' : 'How it works' }}</p>
                        <h2 class="mt-2 text-3xl font-black sm:text-4xl">{{ ar ? 'من الطلب إلى الإنجاز في ثلاث خطوات' : 'From request to done, in three steps' }}</h2>
                    </div>

                    <ol class="mt-10 grid gap-4 md:grid-cols-3" data-reveal-group>
                        <li v-for="(step, i) in steps" :key="i" class="relative rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                            <span class="absolute end-5 top-3 text-7xl font-black leading-none text-white/[0.06]" aria-hidden="true">{{ i + 1 }}</span>
                            <span class="grid h-11 w-11 place-items-center rounded-full bg-crimson text-base font-black text-white shadow-glow">{{ i + 1 }}</span>
                            <h3 class="mt-5 text-lg font-extrabold">{{ step.title }}</h3>
                            <p class="mt-2 text-sm leading-7 text-white/65">{{ step.body }}</p>
                        </li>
                    </ol>

                    <button type="button" class="btn-primary mt-10 !px-7 !py-4 text-base" data-reveal @click="book()">{{ ar ? 'جرّب الآن — أقل من دقيقة' : 'Try it now, under a minute' }}</button>
                </div>
            </section>

            <!-- ============ VERIFIED PROS ============ -->
            <section v-if="featuredPros.length" class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
                <div class="flex items-end justify-between gap-4" data-reveal>
                    <div>
                        <p class="text-sm font-bold text-crimson">04 / {{ ar ? 'شبكة موثوقة' : 'Trusted network' }}</p>
                        <h2 class="mt-2 text-3xl font-black text-ink sm:text-4xl">{{ ar ? 'محترفون تم التحقق منهم' : 'Professionals you can trust' }}</h2>
                    </div>
                    <Link class="hidden shrink-0 text-sm font-bold text-crimson hover:text-crimson-600 sm:block" :href="route('pros')">{{ ar ? 'عرض الكل' : 'View all' }} <span aria-hidden="true" class="inline-block rtl:rotate-180">→</span></Link>
                </div>

                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
                    <SpotlightCard v-for="pro in featuredPros.slice(0, 6)" :key="pro.id" as="article" class="p-5">
                        <div class="relative flex items-start gap-3">
                            <img v-if="pro.image_url" :src="pro.image_url" :alt="pro.display_name" class="h-12 w-12 shrink-0 rounded-full object-cover ring-2 ring-gold/60" loading="lazy" decoding="async" />
                            <div v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-ink text-lg font-black text-white ring-2 ring-gold/60">{{ pro.display_name?.charAt(0) }}</div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-extrabold text-ink">{{ pro.display_name }}</h3>
                                    <span class="badge-kyc"><span aria-hidden="true">✓</span>{{ ar ? 'موثّق KYC' : 'KYC verified' }}</span>
                                </div>
                                <p class="mt-1 truncate text-xs text-ink-500">{{ ar ? pro.headline_ar : pro.headline_en }}</p>
                            </div>
                        </div>

                        <div class="relative mt-5 flex items-center justify-between rounded-xl bg-ink-50 px-3 py-2.5">
                            <span class="flex items-center gap-1 font-black text-ink"><span class="text-gold" aria-hidden="true">★</span>{{ Number(pro.rating_avg).toFixed(1) }}</span>
                            <span class="text-xs text-ink-500">{{ pro.rating_count }} {{ ar ? 'تقييماً' : 'reviews' }}</span>
                            <span class="text-xs text-ink-500">{{ pro.completed_orders }} {{ ar ? 'طلباً منجزاً' : 'jobs done' }}</span>
                        </div>

                        <div v-if="(pro.badges || []).length" class="relative mt-4 flex flex-wrap gap-1.5"><Badge v-for="badge in pro.badges" :key="badge" :type="badge" /></div>

                        <button type="button" class="relative mt-5 w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm font-bold text-ink transition hover:border-crimson hover:text-crimson" @click="book()">{{ ar ? `اطلب من ${pro.display_name}` : `Request ${pro.display_name}` }}</button>
                    </SpotlightCard>
                </div>
            </section>

            <!-- ============ B2B MAINTENANCE CONTRACTS ============ -->
            <section id="business" class="scroll-mt-24 border-y border-ink-200 bg-white">
                <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:py-20 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
                    <div data-reveal>
                        <p class="text-sm font-bold text-crimson">05 / {{ ar ? 'للشركات والمنشآت' : 'For businesses' }}</p>
                        <h2 class="mt-2 text-3xl font-black text-ink sm:text-4xl">{{ t('b2b.title') }}</h2>
                        <p class="mt-3 max-w-xl text-base leading-8 text-ink-600">{{ t('b2b.sub') }}</p>
                        <ul class="mt-5 grid max-w-xl gap-2 text-sm font-semibold text-ink-600 sm:grid-cols-2">
                            <li v-for="point in (ar ? ['زيارات صيانة وقائية مجدولة', 'أولوية في الطوارئ', 'فنيون موثّقون لكل التخصصات', 'تقرير وفاتورة شهرية واحدة'] : ['Scheduled preventive visits', 'Priority for emergencies', 'Verified pros across trades', 'One monthly report and invoice'])" :key="point" class="flex items-center gap-2">
                                <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full bg-crimson/10 text-[10px] text-crimson" aria-hidden="true">✓</span>{{ point }}
                            </li>
                        </ul>
                        <button type="button" class="btn-primary mt-7 !px-7 !py-4 text-base" @click="b2bOpen = true">
                            {{ t('b2b.cta') }} <span aria-hidden="true" class="rtl:rotate-180">→</span>
                        </button>
                    </div>

                    <ul class="grid grid-cols-2 gap-3" data-reveal-group>
                        <li v-for="sector in b2bSectors" :key="sector.label">
                            <button type="button" class="group flex w-full flex-col items-start gap-3 rounded-2xl border border-ink-200 bg-ivory p-5 text-start transition hover:-translate-y-1 hover:border-crimson/40 hover:shadow-lift" @click="b2bOpen = true">
                                <span class="grid h-12 w-12 place-items-center rounded-2xl bg-ink text-2xl transition group-hover:bg-crimson" aria-hidden="true">{{ sector.icon }}</span>
                                <span class="font-extrabold text-ink">{{ sector.label }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- ============ FINAL CTA ============ -->
            <section class="px-4 pb-16 pt-14 sm:pb-24 sm:pt-20">
                <div class="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-crimson px-6 py-14 text-center text-white sm:py-20" data-reveal="scale">
                    <div class="pointer-events-none absolute inset-0 opacity-20 [background-image:radial-gradient(circle_at_1px_1px,#fff_1px,transparent_0)] [background-size:22px_22px]" aria-hidden="true" />
                    <div class="pointer-events-none absolute -bottom-24 start-1/2 h-64 w-[36rem] -translate-x-1/2 rounded-full bg-ink/30 blur-3xl rtl:translate-x-1/2" aria-hidden="true" />
                    <h2 class="relative mx-auto max-w-3xl text-3xl font-black sm:text-5xl sm:leading-tight">{{ ar ? 'تحتاج خدمة الآن؟ احجز في ثلاث خطوات.' : 'Need a service now? Book in three steps.' }}</h2>
                    <p class="relative mx-auto mt-4 max-w-xl text-base leading-8 text-white/80">{{ t('home.hero_sub') }}</p>
                    <button type="button" class="btn relative mt-8 bg-white !px-8 !py-4 text-base text-crimson shadow-lift hover:bg-ink hover:text-white" @click="book()">{{ t('home.cta_book') }}</button>
                </div>
            </section>
        </div>

        <BookingDrawer :open="drawer" :governorates="governorates" :preselect-service-slug="bookingSlug" @close="drawer = false" />
        <B2bRequestModal :open="b2bOpen" @close="b2bOpen = false" />
    </PublicLayout>
</template>
