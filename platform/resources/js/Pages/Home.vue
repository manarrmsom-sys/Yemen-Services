<script setup>
import { computed, ref } from 'vue';
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
const query = ref('');
const activeSpectrum = ref('all');
const selectedCity = ref('all');
const catalog = computed(() => page.props.catalog || []);

const cities = [
    { key: 'all', ar: 'كل المدن', en: 'All cities' },
    { key: 'sanaa', ar: 'صنعاء', en: 'Sana’a' },
    { key: 'aden', ar: 'عدن', en: 'Aden' },
    { key: 'taiz', ar: 'تعز', en: 'Taiz' },
    { key: 'hodeidah', ar: 'الحديدة', en: 'Hodeidah' },
    { key: 'mukalla', ar: 'المكلا', en: 'Al Mukalla' },
];

const spectrum = [
    { key: 'all', ar: 'كل الخدمات', en: 'All services', icon: '✦' },
    { key: 'field', ar: 'ميدانية ومنزلية', en: 'Home & field', icon: '⌂' },
    { key: 'digital', ar: 'رقمية وحرة', en: 'Digital & freelance', icon: '◈' },
    { key: 'professional', ar: 'استشارات ومهن', en: 'Professional', icon: '◎' },
];

const steps = [
    { ar: 'اختر ما تحتاجه', en: 'Choose what you need', bodyAr: 'استخدم البحث أو تصفح الأقسام.', bodyEn: 'Search or browse the service spectrum.' },
    { ar: 'طابقنا لك الأفضل', en: 'We match the right pro', bodyAr: 'نرسل طلبك لمزودين موثقين بالقرب منك.', bodyEn: 'Your request reaches verified nearby pros.' },
    { ar: 'أنجز وادفع بثقة', en: 'Get it done with confidence', bodyAr: 'سعر واضح وتتبّع للطلب حتى النهاية.', bodyEn: 'Clear pricing and tracking to completion.' },
];

const label = (item) => item[locale.value === 'ar' ? 'ar' : 'en'];
const filteredCatalog = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();

    return catalog.value.filter((category) => {
        const haystack = [
            category.name,
            category.slug,
            ...(category.services || []).map((service) => `${service.name} ${service.slug}`),
        ].join(' ').toLocaleLowerCase();

        return (!term || haystack.includes(term))
            && (activeSpectrum.value === 'all' || category.spectrum === activeSpectrum.value);
    });
});

const isFeaturedCategory = (index) => index < 2;

const schema = computed(() => JSON.stringify({
    '@context': 'https://schema.org',
    '@graph': [{
        '@type': 'LocalBusiness',
        '@id': `${window.location.origin}/#platform`,
        name: locale.value === 'ar' ? 'خدمات اليمن' : 'Yemen Services',
        description: locale.value === 'ar' ? 'سوق الخدمات الموثوقة في المدن اليمنية.' : 'A trusted services marketplace across Yemen.',
        url: window.location.origin,
        areaServed: cities.slice(1).map((city) => label(city)),
    }, ...props.featuredPros.slice(0, 6).map((pro) => ({
        '@type': 'Person',
        name: pro.display_name,
        jobTitle: locale.value === 'ar' ? pro.headline_ar : pro.headline_en,
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
        <title>{{ locale === 'ar' ? 'خدمات اليمن | سوق الخدمات الموثوقة' : 'Yemen Services | Trusted services marketplace' }}</title>
        <meta name="description" :content="locale === 'ar' ? 'اطلب خدمات منزلية ورقمية واستشارات من مزودين موثقين في صنعاء وعدن وتعز والحديدة والمكلا.' : 'Book verified home, digital and professional services across Yemen.'" />
        <meta name="robots" content="index,follow,max-image-preview:large" />
        <meta property="og:type" content="website" />
        <meta property="og:site_name" content="خدمات اليمن | Yemen Services" />
        <meta property="og:title" :content="locale === 'ar' ? 'خدمات اليمن | كل خدمة تبدأ بثقة' : 'Yemen Services | Every service starts with trust'" />
        <meta property="og:description" :content="locale === 'ar' ? 'سوق يمني واحد للخدمات الميدانية والرقمية والمهنية.' : 'One Yemeni marketplace for field, digital and professional services.'" />
        <meta property="og:url" :content="page.url" />
        <meta property="og:image" content="/icons/icon-512.png" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="خدمات اليمن | Yemen Services" />
        <meta name="twitter:description" :content="locale === 'ar' ? 'كل خدمة تحتاجها، في مكان واحد.' : 'Every service you need, in one place.'" />
        <meta name="twitter:image" content="/icons/icon-512.png" />
        <link rel="canonical" :href="page.url" />
        <script type="application/ld+json">{{ schema }}</script>
    </Head>

    <PublicLayout>
        <section class="relative overflow-hidden bg-gradient-to-br from-obsidian-900 via-obsidian-900 to-slate-900 text-white">
            <div class="absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,.08)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.08)_1px,transparent_1px)] [background-size:42px_42px]" />
            <div class="relative mx-auto max-w-6xl px-4 py-16 sm:py-24">
                <div>
                    <p class="inline-flex items-center gap-2 border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.12em] text-white/80">🇾🇪 {{ t('app.tagline') }}</p>
                    <h1 class="mt-6 max-w-4xl text-4xl font-black leading-[1.12] sm:text-6xl">{{ locale === 'ar' ? 'كل خدمة تحتاجها، في مكان واحد.' : 'Every service you need, in one place.' }}</h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-white/70 sm:text-lg">{{ locale === 'ar' ? 'من إصلاح البيت إلى التصميم والاستشارات، اطلب من مزودين موثقين في مدن اليمن.' : 'From home repairs to design and advice, book verified providers across Yemen.' }}</p>
                    <div class="mt-8 flex max-w-4xl items-center gap-3 border border-white/20 bg-white p-2 shadow-2xl shadow-black/20">
                        <span class="px-3 text-lg text-obsidian-900/40" aria-hidden="true">⌕</span>
                        <input v-model="query" class="min-w-0 flex-1 border-0 bg-transparent px-0 py-3 text-sm text-obsidian-900 outline-none placeholder:text-obsidian-900/40 focus:ring-0" :placeholder="locale === 'ar' ? 'ابحث عن سباكة، تكييف، تصميم أو استشارة...' : 'Search plumbing, AC, design or advice...'" />
                        <button class="btn-primary shrink-0 bg-red-600 px-5 font-bold" @click="drawer = true">{{ locale === 'ar' ? 'طلب خدمة' : 'Request a service' }}</button>
                    </div>
                    <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/65">
                        <span>{{ locale === 'ar' ? '+٢٥٠ طلب مُنجز هذا الشهر' : '+250 requests completed this month' }}</span>
                        <span>{{ locale === 'ar' ? '٤٫٨ متوسط التقييم' : '4.8 average rating' }}</span>
                        <span>{{ locale === 'ar' ? 'أقل من ١٥ دقيقة متوسط الرد' : 'Under 15 min average response' }}</span>
                    </div>
                </div>
                <div class="mt-8 flex flex-wrap items-center gap-2 text-xs text-white/55"><span class="font-bold text-white/75">{{ locale === 'ar' ? 'متاحون في' : 'Available in' }}</span><button v-for="city in cities.slice(1)" :key="city.key" class="border-b border-white/20 px-1 pb-1 hover:border-white hover:text-white" @click="selectedCity = city.key">{{ label(city) }}</button></div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <p class="text-sm font-bold text-crimson">01 / {{ locale === 'ar' ? 'طيف الخدمات' : 'Service spectrum' }}</p>
                    <div class="relative inline-block"><span class="absolute inset-x-0 bottom-1 h-4 bg-gradient-to-r from-red-500/10 to-transparent" aria-hidden="true" /><h2 class="relative mt-2 text-3xl font-black text-obsidian-900">{{ locale === 'ar' ? 'مهارة قريبة من احتياجك' : 'A skill for every need' }}</h2></div>
                </div>
                <p class="max-w-md text-sm leading-7 text-obsidian-900/55">{{ locale === 'ar' ? 'اختر من خدمات عملية، رقمية، ومهنية تنمو معك.' : 'Explore practical, digital and professional services built around your day.' }}</p>
            </div>
            <div class="mt-7 flex gap-2 overflow-x-auto pb-2"><button v-for="item in spectrum" :key="item.key" class="chip shrink-0 border px-4 py-2.5" :class="activeSpectrum === item.key ? 'border-obsidian-900 bg-obsidian-900 text-white' : 'border-slate-200 bg-white text-obsidian-900/65'" @click="activeSpectrum = item.key"><span>{{ item.icon }}</span>{{ label(item) }}</button></div>
            <div class="mt-7 grid auto-rows-fr grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"><button v-for="(category, index) in filteredCatalog" :key="category.id" class="group relative min-h-44 overflow-hidden border border-slate-200/80 bg-white p-5 text-start transition-all duration-300 hover:-translate-y-1 hover:border-red-500/50 hover:shadow-md" :class="isFeaturedCategory(index) ? 'sm:col-span-2' : ''" @click="drawer = true"><img v-if="category.image_url" :src="category.image_url" :alt="category.name" class="absolute inset-0 h-full w-full object-cover opacity-10 transition duration-300 group-hover:opacity-20" loading="lazy" /><div class="relative flex items-start justify-between gap-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-red-50 text-xl text-red-700">{{ category.icon || ['⌂', '◈', '◎', '✦'][index % 4] }}</span><span v-if="isFeaturedCategory(index)" class="rounded-full bg-red-50 px-2.5 py-1 text-[10px] font-bold text-red-700">{{ locale === 'ar' ? 'الأكثر طلباً' : 'Most requested' }}</span></div><div class="relative"><p class="mt-8 font-extrabold text-obsidian-900">{{ category.name }}</p><p v-if="isFeaturedCategory(index)" class="mt-1 text-sm font-bold text-red-700">{{ locale === 'ar' ? 'يبدأ من ٥٠٠٠ ريال' : 'From 5,000 YER' }}</p><p class="mt-3 text-xs text-obsidian-900/45">{{ 12 + (index * 4) }} {{ locale === 'ar' ? 'مزود متاح الآن' : 'providers available now' }}</p></div></button></div>
        </section>

        <section class="border-y border-slate-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
                <div class="max-w-xl"><p class="text-sm font-bold text-crimson">02 / {{ locale === 'ar' ? 'ببساطة' : 'Simple by design' }}</p><div class="relative inline-block"><span class="absolute inset-x-0 bottom-1 h-4 bg-gradient-to-r from-red-500/10 to-transparent" aria-hidden="true" /><h2 class="relative mt-2 text-3xl font-black text-obsidian-900">{{ locale === 'ar' ? 'من الطلب إلى الإنجاز، بثلاث خطوات' : 'From request to done, in three steps' }}</h2></div></div>
                <div class="mt-10 grid gap-8 md:grid-cols-3"><div v-for="(step, index) in steps" :key="step.en" class="relative min-h-36 overflow-hidden border-t-2 border-obsidian-900 pt-5"><span class="absolute -end-2 -top-5 text-7xl font-black leading-none text-slate-200/50" aria-hidden="true">0{{ index + 1 }}</span><h3 class="relative mt-3 font-extrabold text-obsidian-900">{{ locale === 'ar' ? step.ar : step.en }}</h3><p class="relative mt-2 max-w-xs text-sm leading-7 text-obsidian-900/55">{{ locale === 'ar' ? step.bodyAr : step.bodyEn }}</p></div></div>
            </div>
        </section>

        <section class="bg-slate-900 text-white">
            <div class="mx-auto max-w-6xl px-4 py-14 sm:py-16">
                <div class="flex flex-col items-start justify-between gap-8 md:flex-row md:items-center">
                    <div class="max-w-2xl">
                        <p class="text-sm font-bold text-red-300">04 / {{ locale === 'ar' ? 'كيف تعمل المنصة' : 'How it works' }}</p>
                        <h2 class="mt-3 text-3xl font-black sm:text-4xl">{{ locale === 'ar' ? 'خطوة بسيطة نحو إنجاز مضمون' : 'A simple path to a job well done' }}</h2>
                        <p class="mt-4 text-sm leading-7 text-white/65">{{ locale === 'ar' ? 'نختصر عليك البحث، ونوصلك بمزود مناسب، ونبقى معك حتى اكتمال الخدمة.' : 'We shorten the search, match the right provider, and stay with you through completion.' }}</p>
                    </div>
                    <div class="grid w-full gap-4 sm:grid-cols-3 md:max-w-2xl">
                        <div v-for="(step, index) in steps" :key="`dark-${step.en}`" class="border border-white/10 bg-white/[0.04] p-4">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-red-600/20 text-lg text-red-300">{{ ['⌕', '↗', '✓'][index] }}</span>
                            <p class="mt-4 text-sm font-bold text-white">{{ locale === 'ar' ? step.ar : step.en }}</p>
                        </div>
                    </div>
                </div>
                <button class="mt-10 border border-white/30 px-5 py-3 text-sm font-bold text-white transition hover:border-white hover:bg-white hover:text-slate-900" @click="drawer = true">{{ locale === 'ar' ? 'جرّب الآن — يستغرق أقل من دقيقة' : 'Try it now - takes less than a minute' }}</button>
            </div>
        </section>

        <section v-if="featuredPros.length" class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
            <div class="flex items-end justify-between gap-4"><div><p class="text-sm font-bold text-crimson">03 / {{ locale === 'ar' ? 'شبكة موثوقة' : 'Trusted network' }}</p><div class="relative inline-block"><span class="absolute inset-x-0 bottom-1 h-4 bg-gradient-to-r from-red-500/10 to-transparent" aria-hidden="true" /><h2 class="relative mt-2 text-3xl font-black text-obsidian-900">{{ locale === 'ar' ? 'محترفون تم التحقق منهم' : 'Professionals you can trust' }}</h2></div></div><a class="hidden text-sm font-bold text-crimson sm:block" :href="route('pros')">{{ locale === 'ar' ? 'عرض الكل ←' : 'View all →' }}</a></div>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><article v-for="pro in featuredPros.slice(0, 4)" :key="pro.id" class="border border-slate-200/80 bg-white p-5 transition-all duration-300 hover:border-red-500/50 hover:shadow-md"><div class="flex items-start gap-3"><img v-if="pro.image_url" :src="pro.image_url" :alt="pro.display_name" class="h-11 w-11 shrink-0 rounded-full object-cover" loading="lazy" /><div v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-obsidian-900 text-lg font-black text-white">{{ pro.display_name?.charAt(0) }}</div><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="font-extrabold text-obsidian-900">{{ pro.display_name }}</h3><span class="inline-flex items-center gap-1 rounded-full border border-red-300/70 bg-gradient-to-r from-slate-100 to-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700"><span aria-hidden="true">✓</span>{{ locale === 'ar' ? 'موثّق' : 'Verified' }}</span></div><p class="mt-1 text-xs text-obsidian-900/55">{{ locale === 'ar' ? pro.headline_ar : pro.headline_en }}</p></div></div><div class="mt-5 flex items-center justify-between border-y border-slate-100 bg-slate-50 px-3 py-2.5"><div><span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ locale === 'ar' ? 'التقييم' : 'Rating' }}</span><span class="font-black text-obsidian-900">★ {{ Number(pro.rating_avg).toFixed(1) }}</span></div><div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-gradient-to-r from-red-700 to-red-400" :style="{ width: `${Math.min(100, (Number(pro.rating_avg) / 5) * 100)}%` }" /></div><span class="text-xs text-slate-500">{{ pro.rating_count }} {{ locale === 'ar' ? 'تقييماً' : 'reviews' }}</span></div><div class="mt-4 flex flex-wrap gap-1.5"><Badge v-for="badge in (pro.badges || [])" :key="badge" :type="badge" /></div><p class="mt-4 text-xs text-obsidian-900/45">{{ pro.completed_orders }} {{ locale === 'ar' ? 'طلباً منجزاً' : 'completed requests' }}</p><button class="mt-5 w-full border border-red-600 px-3 py-2 text-sm font-bold text-red-700 transition hover:bg-red-600 hover:text-white" @click="drawer = true">{{ locale === 'ar' ? `اطلب من ${pro.display_name}` : `Request ${pro.display_name}` }}</button></article></div>
        </section>

        <section class="bg-gradient-to-br from-red-700 to-slate-900 text-white">
            <div class="mx-auto max-w-6xl px-4 py-16 text-center sm:py-20">
                <h2 class="text-3xl font-black sm:text-5xl">{{ locale === 'ar' ? 'تحتاج خدمة فورية بمواصفات مضمونة؟' : 'Need an immediate service with confidence built in?' }}</h2>
                <p class="mx-auto mt-5 max-w-2xl text-base leading-8 text-white/75">{{ locale === 'ar' ? 'اختر من بين مئات المزودين المعتمدين في مدينتك وابدأ الآن.' : 'Choose from hundreds of approved providers in your city and get started now.' }}</p>
                <button class="mt-8 bg-white px-7 py-3.5 text-sm font-black text-red-700 shadow-xl transition hover:bg-red-50" @click="drawer = true">{{ locale === 'ar' ? 'اطلب خدمتك الآن' : 'Request your service now' }}</button>
            </div>
        </section>

        <BookingDrawer :open="drawer" :governorates="governorates" :preselect-service-slug="preselectServiceSlug" @close="drawer = false" />
        <button class="btn-primary fixed bottom-20 end-4 z-40 shadow-xl md:bottom-8" @click="drawer = true">{{ t('home.cta_book') }}</button>
    </PublicLayout>
</template>
