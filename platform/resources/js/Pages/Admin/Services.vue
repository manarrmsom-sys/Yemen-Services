<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useCatalogAdmin } from '@/composables/useCatalogAdmin';
import { useMoney } from '@/composables/useMoney';

/**
 * Admin → Services: add, edit, switch on/off and update estimate prices.
 * Saved through /api/v1/admin/catalog/services; the price shown here is the
 * one the public estimator and booking use.
 */
const { t, locale } = useI18n();
const { format } = useMoney();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);
const { categories, loading, error, saving, load, run, fieldError } = useCatalogAdmin();
onMounted(load);

const filter = ref('all');
const query = ref('');
const services = computed(() => categories.value
    .flatMap((c) => c.services.map((s) => ({ ...s, category: c })))
    .filter((s) => (filter.value === 'all' || s.service_category_id === filter.value)
        && (!query.value.trim() || `${s.name_ar} ${s.name_en} ${s.slug}`.toLowerCase().includes(query.value.trim().toLowerCase()))));

const models = computed(() => ({
    fixed: { label: L('سعر ثابت', 'Fixed price'), field: 'base_price', price: L('السعر', 'Price') },
    hourly: { label: L('بالساعة', 'Hourly'), field: 'hourly_rate', price: L('سعر الساعة', 'Hourly rate') },
    per_unit: { label: L('بالوحدة', 'Per unit'), field: 'unit_price', price: L('سعر الوحدة', 'Unit price') },
    quote: { label: L('بعد المعاينة', 'Quote after visit'), field: null, price: '' },
}));
const priceOf = (s) => (models.value[s.pricing_model].field ? s[models.value[s.pricing_model].field] : null);

const blank = () => ({
    service_category_id: '', name_ar: '', name_en: '', slug: '', pricing_model: 'fixed',
    base_price: 0, hourly_rate: 0, unit_price: 0, callout_fee: 0, unit_label_ar: '', unit_label_en: '', is_active: true,
});
const form = reactive(blank());
const editing = ref(null);

function open(service = null) {
    Object.assign(form, blank(), service ? Object.fromEntries(Object.keys(blank()).map((k) => [k, service[k] ?? blank()[k]])) : {
        service_category_id: filter.value === 'all' ? (categories.value[0]?.id ?? '') : filter.value,
    });
    editing.value = service ? service.id : 'new';
    error.value = '';
}

async function save() {
    const payload = { ...form, slug: form.slug || null, unit_label_ar: form.unit_label_ar || null, unit_label_en: form.unit_label_en || null };
    for (const key of ['base_price', 'hourly_rate', 'unit_price', 'callout_fee']) payload[key] = Number(payload[key]) || 0;
    const ok = await run((api) => (editing.value === 'new'
        ? api.post('/admin/catalog/services', payload)
        : api.patch(`/admin/catalog/services/${editing.value}`, payload)));
    if (ok) editing.value = null;
}

const toggle = (s) => run((api) => api.patch(`/admin/catalog/services/${s.id}`, { is_active: !s.is_active }));
const remove = (s) => window.confirm(L(`حذف خدمة «${s.name_ar}»؟`, `Delete “${s.name_en}”?`)) && run((api) => api.delete(`/admin/catalog/services/${s.id}`));

// Quick price edit straight from the table.
const savePrice = (s, value) => {
    const field = models.value[s.pricing_model].field;
    const amount = Number(value);
    if (!field || !Number.isFinite(amount) || amount === s[field]) return;
    run((api) => api.patch(`/admin/catalog/services/${s.id}`, { [field]: Math.round(amount) }));
};
</script>

<template>
    <Head :title="t('admin.services')" />
    <AdminLayout>
        <template #title>{{ t('admin.services') }}</template>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select v-model="filter" class="field !w-auto !bg-white !py-2 text-sm" :aria-label="t('admin.categories')">
                <option value="all">{{ L('كل الأقسام', 'All categories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ L(c.name_ar, c.name_en) }}</option>
            </select>
            <input v-model="query" type="search" class="field !w-56 !bg-white !py-2 text-sm" :placeholder="t('common.search')" />
            <span class="flex-1" />
            <button type="button" class="btn-primary" :disabled="!categories.length" @click="open()">+ {{ L('إضافة خدمة', 'Add service') }}</button>
        </div>

        <p v-if="error && !editing" class="mb-3 rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ error }}</p>
        <p v-if="loading" class="p-6 text-sm text-ink-500">{{ t('common.loading') }}</p>
        <p v-else-if="!services.length" class="rounded-xl border border-dashed border-ink-200 bg-white p-8 text-center text-sm text-ink-500">{{ L('لا توجد خدمات مطابقة.', 'No matching services.') }}</p>

        <div v-else class="overflow-x-auto rounded-2xl bg-white ring-1 ring-ink-200">
            <table class="w-full text-sm">
                <thead class="text-xs text-ink-500">
                    <tr class="border-b border-ink-200">
                        <th class="p-3 text-start">{{ L('الخدمة', 'Service') }}</th>
                        <th class="p-3 text-start">{{ L('القسم', 'Category') }}</th>
                        <th class="p-3 text-start">{{ L('التسعير', 'Pricing') }}</th>
                        <th class="p-3 text-start">{{ L('السعر التقديري (ريال)', 'Estimate price (YER)') }}</th>
                        <th class="p-3 text-start">{{ L('رسوم الانتقال', 'Call-out') }}</th>
                        <th class="p-3 text-start">{{ L('الحالة', 'Status') }}</th>
                        <th class="p-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in services" :key="s.id" class="border-b border-ink-100 last:border-0" :class="s.is_active ? '' : 'opacity-60'">
                        <td class="p-3"><span class="block font-bold text-ink">{{ L(s.name_ar, s.name_en) }}</span><span class="block text-xs text-ink-400" dir="ltr">{{ s.slug }}</span></td>
                        <td class="p-3 text-ink-600">{{ L(s.category.name_ar, s.category.name_en) }}</td>
                        <td class="p-3"><span class="chip bg-ink-100 text-ink-600">{{ models[s.pricing_model].label }}</span></td>
                        <td class="p-3">
                            <input
                                v-if="models[s.pricing_model].field" type="number" min="1" step="100" inputmode="numeric"
                                :value="priceOf(s)" :aria-label="models[s.pricing_model].price" :disabled="saving"
                                class="w-28 rounded-lg border-ink-200 py-1.5 text-sm font-bold tabular-nums focus:border-crimson focus:ring-crimson/20"
                                @change="savePrice(s, $event.target.value)"
                            />
                            <span v-else class="text-ink-400">—</span>
                        </td>
                        <td class="p-3 tabular-nums text-ink-600">{{ s.callout_fee ? format(s.callout_fee) : '—' }}</td>
                        <td class="p-3"><button type="button" class="chip" :class="s.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-ink-100 text-ink-500'" :disabled="saving" @click="toggle(s)">{{ s.is_active ? L('مفعّلة', 'Active') : L('موقوفة', 'Off') }}</button></td>
                        <td class="whitespace-nowrap p-3 text-end">
                            <button type="button" class="chip bg-ink-100 text-ink-600" @click="open(s)">{{ L('تعديل', 'Edit') }}</button>
                            <button type="button" class="chip ms-1 bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Delete')" :disabled="saving" @click="remove(s)">✕</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- editor -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-ink/50 p-4" @click.self="editing = null">
            <form class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-5 shadow-lift" role="dialog" aria-modal="true" @submit.prevent="save">
                <h2 class="text-lg font-extrabold text-ink">{{ editing === 'new' ? L('إضافة خدمة', 'Add service') : L('تعديل الخدمة', 'Edit service') }}</h2>

                <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('القسم', 'Category') }} *</span>
                    <select v-model="form.service_category_id" class="field" required>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ L(c.name_ar, c.name_en) }}</option>
                    </select></label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('الاسم بالعربية', 'Arabic name') }} *</span>
                        <input v-model="form.name_ar" class="field" dir="rtl" maxlength="120" required /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('الاسم بالإنجليزية', 'English name') }} *</span>
                        <input v-model="form.name_en" class="field" dir="ltr" maxlength="120" required /></label>
                </div>

                <fieldset>
                    <legend class="mb-1 block text-sm font-semibold">{{ L('نموذج التسعير', 'Pricing model') }}</legend>
                    <div class="grid grid-cols-2 gap-1.5">
                        <label v-for="(model, key) in models" :key="key" class="cursor-pointer rounded-xl border px-3 py-2 text-sm font-semibold transition" :class="form.pricing_model === key ? 'border-crimson bg-crimson-50 text-crimson-700' : 'border-ink-200 text-ink-600'">
                            <input v-model="form.pricing_model" type="radio" name="pricing-model" :value="key" class="sr-only" />{{ model.label }}
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label v-if="models[form.pricing_model].field" class="block"><span class="mb-1 block text-sm font-semibold">{{ models[form.pricing_model].price }} ({{ L('ريال', 'YER') }}) *</span>
                        <input v-model.number="form[models[form.pricing_model].field]" type="number" min="1" step="100" inputmode="numeric" class="field" required />
                        <span v-if="fieldError(models[form.pricing_model].field)" class="mt-1 block text-xs text-crimson">{{ fieldError(models[form.pricing_model].field) }}</span></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('رسوم الانتقال (ريال)', 'Call-out fee (YER)') }}</span>
                        <input v-model.number="form.callout_fee" type="number" min="0" step="100" inputmode="numeric" class="field" /></label>
                    <template v-if="form.pricing_model === 'per_unit'">
                        <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('اسم الوحدة بالعربية', 'Unit label (Arabic)') }}</span>
                            <input v-model="form.unit_label_ar" class="field" dir="rtl" maxlength="40" :placeholder="L('متر، قطعة…', 'متر')" /></label>
                        <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('اسم الوحدة بالإنجليزية', 'Unit label (English)') }}</span>
                            <input v-model="form.unit_label_en" class="field" dir="ltr" maxlength="40" placeholder="m², unit…" /></label>
                    </template>
                </div>

                <label class="flex items-center gap-2 rounded-xl bg-ink-50 p-3 text-sm font-semibold"><input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('مفعّلة وتظهر للعملاء', 'Active and visible to customers') }}</label>

                <p v-if="error" class="text-sm font-semibold text-crimson" role="alert">{{ error }}</p>
                <div class="flex gap-2">
                    <button type="button" class="btn-ghost flex-1" @click="editing = null">{{ L('إلغاء', 'Cancel') }}</button>
                    <button type="submit" class="btn-primary flex-[2]" :disabled="saving">{{ saving ? t('booking.sending') : L('حفظ', 'Save') }}</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
