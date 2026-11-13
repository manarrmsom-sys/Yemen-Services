<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

/**
 * Admin → Storefront appearance.
 * Edits the announcement bar, the home promo banners and the order of the
 * featured services. Saved through PUT /api/v1/admin/appearance; the public
 * site picks the change up on its next page load.
 */
const { t, locale } = useI18n();
const api = useApi();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);

const loading = ref(true);
const saving = ref(false);
const saved = ref(false);
const loadError = ref(false);
const errors = ref({});

const palette = ref([]);
const tones = ref([]);
const services = ref([]);

const form = reactive({
    announcement: { enabled: true, speed: 32, background: '#111315', color: '#FFFFFF', items: [] },
    banners: [],
    featured_service_ids: [],
});

function hydrate(data) {
    palette.value = data.palette;
    tones.value = data.tones;
    services.value = data.services;
    Object.assign(form.announcement, JSON.parse(JSON.stringify(data.appearance.announcement)));
    form.banners = JSON.parse(JSON.stringify(data.appearance.banners));
    form.featured_service_ids = [...data.appearance.featured_service_ids];
}

onMounted(async () => {
    try {
        const { data } = await api.get('/admin/appearance');
        hydrate(data);
    } catch (e) {
        loadError.value = true;
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;
    saved.value = false;
    errors.value = {};
    try {
        const { data } = await api.put('/admin/appearance', form);
        hydrate(data);
        saved.value = true;
        setTimeout(() => (saved.value = false), 4000);
    } catch (e) {
        errors.value = e.response?.data?.errors || { _: [e.response?.data?.message || L('تعذّر الحفظ.', 'Could not save.')] };
    } finally {
        saving.value = false;
    }
}

const errorList = computed(() => Object.values(errors.value).flat());
const err = (key) => errors.value[key]?.[0];

// ---- list helpers -----------------------------------------------------
function move(list, index, delta) {
    const to = index + delta;
    if (to < 0 || to >= list.length) return;
    list.splice(to, 0, list.splice(index, 1)[0]);
}

const addItem = () => form.announcement.items.push({ ar: '', en: '', href: null });
const addBanner = () => form.banners.push({
    title_ar: '', title_en: '', body_ar: '', body_en: '', cta_ar: '', cta_en: '',
    href: '/book', image_url: null, tone: 'ink', is_active: true,
});

// ---- featured services ------------------------------------------------
const serviceById = computed(() => Object.fromEntries(services.value.map((s) => [s.id, s])));
const featured = computed(() => form.featured_service_ids.map((id) => serviceById.value[id]).filter(Boolean));
const available = computed(() => services.value.filter((s) => !form.featured_service_ids.includes(s.id)));
const pick = ref('');
function addFeatured() {
    if (pick.value) form.featured_service_ids.push(Number(pick.value));
    pick.value = '';
}

// ---- presentation -----------------------------------------------------
const toneLabel = (tone) => ({ ink: L('أسود فحمي', 'Charcoal'), crimson: L('أحمر', 'Crimson'), ivory: L('أبيض', 'White') }[tone] || tone);
const toneClass = { ink: 'bg-ink text-white', crimson: 'bg-crimson text-white', ivory: 'bg-white text-ink ring-1 ring-ink-200' };
const previewItems = computed(() => form.announcement.items.map((i) => (locale.value === 'ar' ? i.ar : i.en || i.ar)).filter(Boolean));

/** Empty strings would fail the URL rules; send null instead. */
const nullable = (obj, key) => ({
    get: () => obj[key] ?? '',
    set: (v) => (obj[key] = v === '' ? null : v),
});
</script>

<template>
    <Head :title="t('admin.appearance')" />
    <AdminLayout>
        <template #title>{{ t('admin.appearance') }}</template>

        <p v-if="loading" class="p-6 text-sm text-ink-500">{{ t('common.loading') }}</p>
        <p v-else-if="loadError" class="rounded-xl border border-crimson-200 bg-crimson-50 p-4 text-sm font-semibold text-crimson-700">{{ L('تعذّر تحميل إعدادات المظهر. حدّث الصفحة وحاول مجدداً.', 'Could not load appearance settings. Reload and try again.') }}</p>

        <form v-else class="mx-auto max-w-4xl space-y-6 pb-24" @submit.prevent="save">
            <!-- ============ Announcement bar ============ -->
            <section class="card p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-extrabold text-ink">{{ L('الشريط الإعلاني المتحرك', 'Announcement bar') }}</h2>
                        <p class="mt-1 text-sm text-ink-500">{{ L('يظهر أعلى كل صفحات الموقع: عروض موسمية، أوقات الذروة، تنبيهات التغطية.', 'Shown at the top of every public page: seasonal offers, peak hours, coverage notices.') }}</p>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-bold">
                        <input v-model="form.announcement.enabled" type="checkbox" class="h-5 w-5 rounded border-ink-300 text-crimson focus:ring-crimson" />
                        {{ L('مفعّل', 'Enabled') }}
                    </label>
                </div>

                <!-- live preview -->
                <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-ink-200" :class="form.announcement.enabled ? '' : 'opacity-40'">
                    <p class="bg-ink-50 px-3 py-1 text-[11px] font-bold text-ink-500">{{ L('معاينة', 'Preview') }}</p>
                    <div class="flex gap-6 overflow-hidden whitespace-nowrap px-4 py-2 text-[13px] font-semibold" :style="{ backgroundColor: form.announcement.background, color: form.announcement.color }">
                        <template v-if="previewItems.length">
                            <span v-for="(text, i) in previewItems" :key="i">{{ text }} <span class="ms-6 opacity-50">◆</span></span>
                        </template>
                        <span v-else class="opacity-60">{{ L('أضف رسالة واحدة على الأقل', 'Add at least one message') }}</span>
                    </div>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <div v-for="field in [['background', L('لون الخلفية', 'Background')], ['color', L('لون النص', 'Text colour')]]" :key="field[0]">
                        <p class="mb-2 text-xs font-bold text-ink-600">{{ field[1] }}</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                v-for="hex in palette" :key="hex" type="button"
                                class="h-8 w-8 rounded-full ring-1 ring-ink-200 transition hover:scale-110"
                                :class="form.announcement[field[0]].toUpperCase() === hex ? 'outline outline-2 outline-offset-2 outline-crimson' : ''"
                                :style="{ backgroundColor: hex }" :title="hex" :aria-label="hex"
                                @click="form.announcement[field[0]] = hex"
                            />
                            <input v-model="form.announcement[field[0]]" type="color" class="h-8 w-10 cursor-pointer rounded border border-ink-200 bg-white p-0.5" :aria-label="field[1]" />
                        </div>
                        <p v-if="err(`announcement.${field[0]}`)" class="mt-1 text-xs text-crimson">{{ err(`announcement.${field[0]}`) }}</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-bold text-ink-600" for="bar-speed">{{ L('مدة الدورة', 'Loop duration') }}: {{ form.announcement.speed }}{{ L(' ث', 's') }}</label>
                        <input id="bar-speed" v-model.number="form.announcement.speed" type="range" min="10" max="120" step="2" class="w-full accent-crimson" />
                        <p class="mt-1 text-[11px] text-ink-400">{{ L('رقم أصغر = حركة أسرع', 'Lower is faster') }}</p>
                    </div>
                </div>

                <div class="mt-6 space-y-3">
                    <div v-for="(item, i) in form.announcement.items" :key="i" class="rounded-xl border border-ink-200 p-3">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="item.ar" class="field" dir="rtl" maxlength="160" :placeholder="L('النص بالعربية (مطلوب)', 'Arabic text (required)')" />
                            <input v-model="item.en" class="field" dir="ltr" maxlength="160" :placeholder="L('النص بالإنجليزية (اختياري)', 'English text (optional)')" />
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <input :value="nullable(item, 'href').get()" class="field" dir="ltr" maxlength="255" :placeholder="L('رابط اختياري: /book أو https://...', 'Optional link: /book or https://...')" @input="nullable(item, 'href').set($event.target.value)" />
                            <button type="button" class="chip bg-ink-100" :disabled="i === 0" :aria-label="L('تحريك لأعلى', 'Move up')" @click="move(form.announcement.items, i, -1)">↑</button>
                            <button type="button" class="chip bg-ink-100" :disabled="i === form.announcement.items.length - 1" :aria-label="L('تحريك لأسفل', 'Move down')" @click="move(form.announcement.items, i, 1)">↓</button>
                            <button type="button" class="chip bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Remove')" @click="form.announcement.items.splice(i, 1)">✕</button>
                        </div>
                        <p v-if="err(`announcement.items.${i}.ar`) || err(`announcement.items.${i}.href`)" class="mt-1 text-xs text-crimson">{{ err(`announcement.items.${i}.ar`) || err(`announcement.items.${i}.href`) }}</p>
                    </div>
                    <button v-if="form.announcement.items.length < 8" type="button" class="btn-ghost w-full" @click="addItem">+ {{ L('إضافة رسالة', 'Add message') }}</button>
                </div>
            </section>

            <!-- ============ Banners ============ -->
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-extrabold text-ink">{{ L('البانرات الترويجية في الرئيسية', 'Home promo banners') }}</h2>
                <p class="mt-1 text-sm text-ink-500">{{ L('تظهر تحت القسم الرئيسي مباشرة. حتى ٦ بانرات.', 'Shown right below the hero. Up to 6 banners.') }}</p>

                <div class="mt-5 space-y-4">
                    <div v-for="(banner, i) in form.banners" :key="i" class="rounded-xl border border-ink-200 p-4" :class="banner.is_active ? '' : 'bg-ink-50'">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <span class="chip" :class="toneClass[banner.tone]">{{ L('بانر', 'Banner') }} {{ i + 1 }}</span>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center gap-1.5 text-xs font-bold"><input v-model="banner.is_active" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('ظاهر', 'Visible') }}</label>
                                <button type="button" class="chip bg-ink-100" :disabled="i === 0" :aria-label="L('تحريك لأعلى', 'Move up')" @click="move(form.banners, i, -1)">↑</button>
                                <button type="button" class="chip bg-ink-100" :disabled="i === form.banners.length - 1" :aria-label="L('تحريك لأسفل', 'Move down')" @click="move(form.banners, i, 1)">↓</button>
                                <button type="button" class="chip bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Remove')" @click="form.banners.splice(i, 1)">✕</button>
                            </div>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="banner.title_ar" class="field" dir="rtl" maxlength="80" :placeholder="L('العنوان بالعربية (مطلوب)', 'Arabic title (required)')" />
                            <input v-model="banner.title_en" class="field" dir="ltr" maxlength="80" :placeholder="L('العنوان بالإنجليزية', 'English title')" />
                            <input v-model="banner.body_ar" class="field" dir="rtl" maxlength="200" :placeholder="L('الوصف بالعربية', 'Arabic description')" />
                            <input v-model="banner.body_en" class="field" dir="ltr" maxlength="200" :placeholder="L('الوصف بالإنجليزية', 'English description')" />
                            <input v-model="banner.cta_ar" class="field" dir="rtl" maxlength="40" :placeholder="L('نص الزر بالعربية', 'Arabic button label')" />
                            <input v-model="banner.cta_en" class="field" dir="ltr" maxlength="40" :placeholder="L('نص الزر بالإنجليزية', 'English button label')" />
                            <input :value="nullable(banner, 'href').get()" class="field" dir="ltr" maxlength="255" :placeholder="L('رابط الزر: /book أو https://...', 'Button link: /book or https://...')" @input="nullable(banner, 'href').set($event.target.value)" />
                            <input :value="nullable(banner, 'image_url').get()" class="field" dir="ltr" maxlength="500" :placeholder="L('رابط صورة الخلفية (https، اختياري)', 'Background image URL (https, optional)')" @input="nullable(banner, 'image_url').set($event.target.value)" />
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-xs font-bold text-ink-600">{{ L('اللون', 'Tone') }}:</span>
                            <button v-for="tone in tones" :key="tone" type="button" class="chip transition" :class="[toneClass[tone], banner.tone === tone ? 'outline outline-2 outline-offset-2 outline-crimson' : 'opacity-70']" @click="banner.tone = tone">{{ toneLabel(tone) }}</button>
                        </div>
                        <p v-for="key in ['title_ar', 'href', 'image_url']" v-show="err(`banners.${i}.${key}`)" :key="key" class="mt-1 text-xs text-crimson">{{ err(`banners.${i}.${key}`) }}</p>
                    </div>
                    <button v-if="form.banners.length < 6" type="button" class="btn-ghost w-full" @click="addBanner">+ {{ L('إضافة بانر', 'Add banner') }}</button>
                </div>
            </section>

            <!-- ============ Featured services ============ -->
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-extrabold text-ink">{{ L('ترتيب الخدمات المميزة', 'Featured services order') }}</h2>
                <p class="mt-1 text-sm text-ink-500">{{ L('تظهر في قسم «الأكثر طلباً» بهذا الترتيب. اتركها فارغة لعرض الخدمات الأكثر طلباً تلقائياً.', 'Shown in “Most requested” in this order. Leave empty to show the most-booked services automatically.') }}</p>

                <ol class="mt-5 space-y-2">
                    <li v-for="(service, i) in featured" :key="service.id" class="flex items-center gap-3 rounded-xl border border-ink-200 px-3 py-2.5">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ink text-xs font-black text-white">{{ i + 1 }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold text-ink">{{ L(service.name_ar, service.name_en) }}</span>
                            <span class="block truncate text-xs text-ink-500">{{ L(service.category_ar, service.category_en) }}</span>
                        </span>
                        <button type="button" class="chip bg-ink-100" :disabled="i === 0" :aria-label="L('تحريك لأعلى', 'Move up')" @click="move(form.featured_service_ids, i, -1)">↑</button>
                        <button type="button" class="chip bg-ink-100" :disabled="i === featured.length - 1" :aria-label="L('تحريك لأسفل', 'Move down')" @click="move(form.featured_service_ids, i, 1)">↓</button>
                        <button type="button" class="chip bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Remove')" @click="form.featured_service_ids.splice(i, 1)">✕</button>
                    </li>
                    <li v-if="!featured.length" class="rounded-xl border border-dashed border-ink-200 p-4 text-center text-sm text-ink-500">{{ L('الترتيب تلقائي حالياً (الأكثر طلباً).', 'Currently automatic (most booked).') }}</li>
                </ol>

                <div v-if="form.featured_service_ids.length < 12 && available.length" class="mt-3 flex gap-2">
                    <select v-model="pick" class="field">
                        <option value="">{{ L('اختر خدمة لإضافتها…', 'Choose a service to add…') }}</option>
                        <option v-for="s in available" :key="s.id" :value="s.id">{{ L(s.category_ar, s.category_en) }} — {{ L(s.name_ar, s.name_en) }}</option>
                    </select>
                    <button type="button" class="btn-ghost shrink-0" :disabled="!pick" @click="addFeatured">{{ L('إضافة', 'Add') }}</button>
                </div>
                <p v-if="!services.length" class="mt-3 text-xs text-ink-500">{{ L('لا توجد خدمات مفعّلة بعد.', 'No active services yet.') }}</p>
            </section>

            <!-- ============ Save bar ============ -->
            <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-ink-200 bg-white/95 p-3 shadow-lift backdrop-blur">
                <div class="min-w-0 text-sm" aria-live="polite">
                    <p v-if="saved" class="font-bold text-emerald-600">✓ {{ L('تم الحفظ. يظهر التغيير في الموقع عند تحميل الصفحة التالية.', 'Saved. The site shows it on the next page load.') }}</p>
                    <ul v-else-if="errorList.length" class="text-xs font-semibold text-crimson"><li v-for="(message, i) in errorList.slice(0, 3)" :key="i">{{ message }}</li></ul>
                    <p v-else class="text-ink-500">{{ L('التغييرات لا تُحفظ حتى تضغط «حفظ».', 'Changes are not saved until you press Save.') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <a :href="route('home')" target="_blank" rel="noopener" class="btn-ghost">{{ L('فتح الموقع', 'Open site') }}</a>
                    <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? L('جارٍ الحفظ…', 'Saving…') : L('حفظ التغييرات', 'Save changes') }}</button>
                </div>
            </div>
        </form>
    </AdminLayout>
</template>
