<script setup>
import { computed, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { watchDebounced } from '@vueuse/core';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

/**
 * Transparent price estimator for the hero. Talks to the public
 * POST /api/v1/estimate endpoint (no sign-in), the same pricing engine the
 * booking drawer uses, so the figure shown here is the one the order gets.
 */
const props = defineProps({
    governorates: { type: Array, default: () => [] },
});
const emit = defineEmits(['book']);

const { t, locale } = useI18n();
const { format } = useMoney();
const api = useApi();
const page = usePage();
const ar = computed(() => locale.value === 'ar');

const catalog = computed(() => (page.props.catalog || []).filter((c) => c.services?.length));
const services = computed(() => catalog.value.flatMap((c) => c.services));

const form = reactive({
    service_id: services.value[0]?.id ?? null,
    governorate_id: props.governorates[0]?.id ?? null,
    urgency: 'today',
    quantity: 1,
    hours: 2,
});

const service = computed(() => services.value.find((s) => s.id === form.service_id));
const quote = ref(null);
const loading = ref(false);
const failed = ref(false);
let request = 0;

const urgencies = ['flexible', 'today', 'urgent'];
const lineLabel = (line) => (ar.value ? line.label_ar : line.label_en);

watchDebounced(
    () => [form.service_id, form.governorate_id, form.urgency, form.quantity, form.hours],
    async () => {
        if (!form.service_id || !form.governorate_id) return;
        const id = ++request;
        loading.value = true;
        failed.value = false;
        try {
            const { data } = await api.post('/estimate', { ...form });
            if (id === request) quote.value = data;
        } catch (e) {
            if (id === request) {
                quote.value = null;
                failed.value = true;
            }
        } finally {
            if (id === request) loading.value = false;
        }
    },
    { debounce: 300, immediate: true },
);
</script>

<template>
    <div class="relative rounded-3xl border border-ink-200/80 bg-white p-5 shadow-lift sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-crimson">{{ ar ? 'قبل تسجيل الدخول' : 'No sign-in needed' }}</p>
                <h2 class="mt-1 text-lg font-black text-ink">{{ ar ? 'احسب تكلفة خدمتك' : 'Estimate your service' }}</h2>
            </div>
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-crimson/10 text-xl text-crimson" aria-hidden="true">﷼</span>
        </div>

        <div v-if="!services.length" class="mt-5 rounded-xl bg-ink-50 p-4 text-sm text-ink-500">
            {{ ar ? 'لا توجد خدمات متاحة حالياً.' : 'No services are available yet.' }}
        </div>

        <form v-else class="mt-5 space-y-3" @submit.prevent="emit('book', service?.slug)">
            <label class="block">
                <span class="mb-1 block text-xs font-bold text-ink-600">{{ t('booking.choose_service') }}</span>
                <select v-model="form.service_id" class="field">
                    <optgroup v-for="category in catalog" :key="category.id" :label="category.name">
                        <option v-for="s in category.services" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </optgroup>
                </select>
            </label>

            <div class="grid gap-3" :class="service?.pricing_model === 'per_unit' || service?.pricing_model === 'hourly' ? 'grid-cols-[1.4fr_1fr]' : 'grid-cols-1'">
                <label class="block">
                    <span class="mb-1 block text-xs font-bold text-ink-600">{{ t('booking.governorate') }}</span>
                    <select v-model="form.governorate_id" class="field">
                        <option v-for="g in governorates" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                </label>
                <label v-if="service?.pricing_model === 'per_unit'" class="block">
                    <span class="mb-1 block truncate text-xs font-bold text-ink-600">{{ t('booking.quantity') }}<template v-if="service.unit_label"> ({{ service.unit_label }})</template></span>
                    <input v-model.number="form.quantity" type="number" min="1" max="9999" inputmode="numeric" class="field" />
                </label>
                <label v-else-if="service?.pricing_model === 'hourly'" class="block">
                    <span class="mb-1 block text-xs font-bold text-ink-600">{{ t('booking.hours') }}</span>
                    <input v-model.number="form.hours" type="number" min="1" max="72" step="0.5" inputmode="decimal" class="field" />
                </label>
            </div>

            <fieldset>
                <legend class="mb-1 block text-xs font-bold text-ink-600">{{ t('booking.urgency') }}</legend>
                <div class="grid grid-cols-3 gap-1.5 rounded-xl bg-ink-100 p-1">
                    <label
                        v-for="u in urgencies" :key="u"
                        class="cursor-pointer rounded-lg px-2 py-2 text-center text-xs font-bold transition"
                        :class="form.urgency === u ? 'bg-white text-ink shadow-sm' : 'text-ink-500 hover:text-ink'"
                    >
                        <input v-model="form.urgency" type="radio" name="estimator-urgency" :value="u" class="sr-only" />
                        {{ t(`booking.urgency_${u}`).split(' (')[0] }}
                    </label>
                </div>
            </fieldset>

            <!-- result -->
            <div class="rounded-2xl bg-ink p-4 text-white" aria-live="polite">
                <div class="flex items-end justify-between gap-3">
                    <span class="text-xs font-semibold text-white/60">{{ t('estimate.total') }}</span>
                    <span v-if="loading && !quote" class="h-8 w-28 animate-pulse rounded-lg bg-white/10" />
                    <span v-else-if="quote && !quote.requires_quote" class="text-3xl font-black leading-none transition-opacity" :class="loading ? 'opacity-50' : ''">{{ format(quote.total) }}</span>
                    <span v-else class="text-lg font-black">—</span>
                </div>

                <ul v-if="quote && !quote.requires_quote" class="mt-3 space-y-1 border-t border-white/10 pt-3 text-xs text-white/70">
                    <li v-for="(line, i) in quote.lines" :key="i" class="flex justify-between gap-3">
                        <span>{{ lineLabel(line) }}</span>
                        <span class="tabular-nums" :class="line.amount < 0 ? 'text-emerald-300' : 'text-white'">{{ format(line.amount) }}</span>
                    </li>
                </ul>
                <p v-else-if="quote?.requires_quote" class="mt-2 text-xs leading-6 text-white/70">{{ t('estimate.requires_quote') }}</p>
                <p v-else-if="failed" class="mt-2 text-xs leading-6 text-white/70">{{ ar ? 'تعذّر حساب التكلفة الآن. تحقق من الاتصال وحاول مجدداً.' : 'Could not calculate right now. Check your connection and try again.' }}</p>
            </div>

            <button type="submit" class="btn-primary w-full !py-3.5 text-base">
                {{ ar ? 'احجز هذه الخدمة' : 'Book this service' }}
                <span aria-hidden="true" class="rtl:rotate-180">→</span>
            </button>
            <p class="text-center text-[11px] text-ink-400">{{ ar ? 'تقدير أولي — يُؤكَّد السعر النهائي قبل بدء العمل.' : 'Initial estimate. The final price is confirmed before work starts.' }}</p>
        </form>
    </div>
</template>
