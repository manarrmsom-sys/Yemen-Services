<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useCatalogAdmin } from '@/composables/useCatalogAdmin';

/** Admin → Categories: add, edit, switch on/off. Saved through /api/v1/admin/catalog/categories. */
const { t, locale } = useI18n();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);
const { categories, loading, error, saving, load, run, fieldError } = useCatalogAdmin();
onMounted(load);

const blank = () => ({ name_ar: '', name_en: '', slug: '', icon: '', image_url: '', is_remote: false, requires_kyc: true, is_active: true });
const form = reactive(blank());
const editing = ref(null); // category id, or 'new'

function open(category = null) {
    Object.assign(form, blank(), category ? {
        name_ar: category.name_ar, name_en: category.name_en, slug: category.slug, icon: category.icon || '',
        image_url: category.image_url || '', is_remote: category.is_remote, requires_kyc: category.requires_kyc, is_active: category.is_active,
    } : {});
    editing.value = category ? category.id : 'new';
    error.value = '';
}

async function save() {
    const payload = { ...form, slug: form.slug || null, icon: form.icon || null, image_url: form.image_url || null };
    const ok = await run((api) => (editing.value === 'new'
        ? api.post('/admin/catalog/categories', payload)
        : api.patch(`/admin/catalog/categories/${editing.value}`, payload)));
    if (ok) editing.value = null;
}

const toggle = (category) => run((api) => api.patch(`/admin/catalog/categories/${category.id}`, { is_active: !category.is_active }));
const remove = (category) => window.confirm(L(`حذف قسم «${category.name_ar}» وكل خدماته؟`, `Delete “${category.name_en}” and all its services?`))
    && run((api) => api.delete(`/admin/catalog/categories/${category.id}`));
</script>

<template>
    <Head :title="t('admin.categories')" />
    <AdminLayout>
        <template #title>{{ t('admin.categories') }}</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-sm text-ink-500">{{ L('القسم الموقوف يختفي من الموقع والحجز، وتبقى طلباته السابقة محفوظة.', 'An inactive category is hidden from the site and booking; its past orders are kept.') }}</p>
            <button type="button" class="btn-primary shrink-0" @click="open()">+ {{ L('إضافة قسم', 'Add category') }}</button>
        </div>

        <p v-if="error && !editing" class="mb-3 rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ error }}</p>
        <p v-if="loading" class="p-6 text-sm text-ink-500">{{ t('common.loading') }}</p>
        <p v-else-if="!categories.length" class="rounded-xl border border-dashed border-ink-200 bg-white p-8 text-center text-sm text-ink-500">{{ L('لا توجد أقسام بعد.', 'No categories yet.') }}</p>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="category in categories" :key="category.id" class="card flex flex-col p-4" :class="category.is_active ? '' : 'opacity-60'">
                <div class="flex items-start gap-3">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-ink text-2xl text-white">{{ category.icon || '✦' }}</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-extrabold text-ink">{{ L(category.name_ar, category.name_en) }}</h2>
                        <p class="truncate text-xs text-ink-500" dir="ltr">{{ category.slug }}</p>
                    </div>
                    <span class="chip shrink-0" :class="category.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-ink-100 text-ink-500'">{{ category.is_active ? L('مفعّل', 'Active') : L('موقوف', 'Off') }}</span>
                </div>
                <p class="mt-3 text-xs text-ink-500">
                    {{ category.services.length }} {{ L('خدمة', 'services') }}
                    <template v-if="category.is_remote"> · {{ L('عن بُعد', 'remote') }}</template>
                    <template v-if="category.requires_kyc"> · KYC</template>
                </p>
                <div class="mt-4 flex gap-2 border-t border-ink-100 pt-3">
                    <button type="button" class="btn-ghost flex-1 !py-2 text-xs" @click="open(category)">{{ L('تعديل', 'Edit') }}</button>
                    <button type="button" class="btn-ghost flex-1 !py-2 text-xs" :disabled="saving" @click="toggle(category)">{{ category.is_active ? L('إيقاف', 'Deactivate') : L('تفعيل', 'Activate') }}</button>
                    <button type="button" class="chip bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Delete')" :disabled="saving" @click="remove(category)">✕</button>
                </div>
            </article>
        </div>

        <!-- editor -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-ink/50 p-4" @click.self="editing = null">
            <form class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-5 shadow-lift" role="dialog" aria-modal="true" @submit.prevent="save">
                <h2 class="text-lg font-extrabold text-ink">{{ editing === 'new' ? L('إضافة قسم', 'Add category') : L('تعديل القسم', 'Edit category') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('الاسم بالعربية', 'Arabic name') }} *</span>
                        <input v-model="form.name_ar" class="field" dir="rtl" maxlength="80" required /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('الاسم بالإنجليزية', 'English name') }} *</span>
                        <input v-model="form.name_en" class="field" dir="ltr" maxlength="80" required /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('المعرّف في الرابط (اختياري)', 'URL slug (optional)') }}</span>
                        <input v-model="form.slug" class="field" dir="ltr" maxlength="80" placeholder="plumbing" />
                        <span v-if="fieldError('slug')" class="mt-1 block text-xs text-crimson">{{ fieldError('slug') }}</span></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('أيقونة (إيموجي)', 'Icon (emoji)') }}</span>
                        <input v-model="form.icon" class="field" maxlength="16" placeholder="🔧" /></label>
                </div>
                <label class="block"><span class="mb-1 block text-sm font-semibold">{{ L('رابط الصورة (https، اختياري)', 'Image URL (https, optional)') }}</span>
                    <input v-model="form.image_url" class="field" dir="ltr" maxlength="1024" placeholder="https://…" />
                    <span v-if="fieldError('image_url')" class="mt-1 block text-xs text-crimson">{{ fieldError('image_url') }}</span></label>

                <div class="space-y-2 rounded-xl bg-ink-50 p-3 text-sm">
                    <label class="flex items-center gap-2 font-semibold"><input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('مفعّل ويظهر للعملاء', 'Active and visible to customers') }}</label>
                    <label class="flex items-center gap-2 font-semibold"><input v-model="form.is_remote" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('خدمات رقمية تُنفَّذ عن بُعد (بلا رسوم محافظة ولا عنوان)', 'Digital, delivered online (no governorate fee, no address)') }}</label>
                    <label class="flex items-center gap-2 font-semibold"><input v-model="form.requires_kyc" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('يتطلب توثيق الفني (KYC)', 'Requires technician verification (KYC)') }}</label>
                </div>

                <p v-if="error" class="text-sm font-semibold text-crimson" role="alert">{{ error }}</p>
                <div class="flex gap-2">
                    <button type="button" class="btn-ghost flex-1" @click="editing = null">{{ L('إلغاء', 'Cancel') }}</button>
                    <button type="submit" class="btn-primary flex-[2]" :disabled="saving">{{ saving ? t('booking.sending') : L('حفظ', 'Save') }}</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
