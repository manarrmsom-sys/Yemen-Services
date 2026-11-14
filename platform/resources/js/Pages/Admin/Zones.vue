<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

/**
 * Admin → Cities & districts.
 * Controls the narrow launch: which governorates are live and, inside each,
 * which districts are bookable ("active") or collecting demand ("coming soon").
 * Each row shows supply (verified / active pros) next to demand (waitlist).
 */
const { t, locale } = useI18n();
const api = useApi();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);
const name = (row) => (locale.value === 'ar' ? row.name_ar : row.name_en || row.name_ar);

const governorates = ref([]);
const loading = ref(true);
const error = ref('');
const open = ref(null);
const onlyActive = ref(false);
const draft = reactive({ name_ar: '', name_en: '', status: 'coming_soon' });
const waitlist = ref(null); // { title, rows }

const visible = computed(() => governorates.value.filter((g) => !onlyActive.value || g.is_active));
const totals = computed(() => ({
    cities: governorates.value.filter((g) => g.is_active).length,
    districts: governorates.value.flatMap((g) => g.districts).filter((d) => d.status === 'active').length,
    waitlist: governorates.value.reduce((n, g) => n + g.waitlist_count, 0),
}));

async function load() {
    try {
        governorates.value = (await api.get('/admin/zones')).data;
        if (open.value === null) open.value = governorates.value.find((g) => g.districts.length)?.id ?? null;
    } catch (e) {
        error.value = L('تعذّر تحميل المناطق.', 'Could not load zones.');
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function run(action) {
    error.value = '';
    try {
        await action();
        await load();
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || t('common.error');
    }
}

const toggleCity = (g) => run(() => api.patch(`/admin/zones/governorates/${g.id}`, { is_active: !g.is_active }));
const setStatus = (d, status) => run(() => api.patch(`/admin/zones/districts/${d.id}`, { status }));
const remove = (d) => window.confirm(L(`حذف حي «${d.name_ar}»؟`, `Delete district “${name(d)}”?`)) && run(() => api.delete(`/admin/zones/districts/${d.id}`));
const add = (g) => run(async () => {
    await api.post('/admin/zones/districts', { governorate_id: g.id, ...draft });
    Object.assign(draft, { name_ar: '', name_en: '' });
});

async function showWaitlist(g, d = null) {
    const params = d ? { district_id: d.id } : { governorate_id: g.id };
    const res = await api.get('/admin/zones/waitlist', { params });
    waitlist.value = { title: d ? `${name(g)} — ${name(d)}` : name(g), rows: res.data };
}

// A live district with nobody to serve it is the thing to notice.
const supplyClass = (d) => (d.status === 'active' && d.active_pros === 0 ? 'bg-crimson-50 text-crimson-700' : 'bg-ink-100 text-ink-600');
</script>

<template>
    <Head :title="t('admin.zones')" />
    <AdminLayout>
        <template #title>{{ t('admin.zones') }}</template>

        <div class="mx-auto max-w-5xl space-y-4">
            <div class="grid grid-cols-3 gap-3">
                <div class="card p-4"><p class="text-2xl font-black text-ink">{{ totals.cities }}</p><p class="text-xs text-ink-500">{{ L('مدينة مفعّلة', 'live cities') }}</p></div>
                <div class="card p-4"><p class="text-2xl font-black text-ink">{{ totals.districts }}</p><p class="text-xs text-ink-500">{{ L('حي مفعّل', 'live districts') }}</p></div>
                <div class="card p-4"><p class="text-2xl font-black text-ink">{{ totals.waitlist }}</p><p class="text-xs text-ink-500">{{ L('في قائمة الانتظار', 'on the waitlist') }}</p></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-ink-500">{{ L('المدينة التي لا تحتوي أحياء تُخدَم كاملة. أضف أحياء لتقييد الخدمة بالمفعّل منها فقط.', 'A city with no districts is served as a whole. Add districts to restrict service to the active ones.') }}</p>
                <label class="inline-flex items-center gap-2 text-sm font-semibold"><input v-model="onlyActive" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-crimson focus:ring-crimson" />{{ L('المفعّلة فقط', 'Live only') }}</label>
            </div>

            <p v-if="error" class="rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ error }}</p>
            <p v-if="loading" class="p-6 text-sm text-ink-500">{{ t('common.loading') }}</p>

            <section v-for="g in visible" :key="g.id" class="card overflow-hidden">
                <header class="flex flex-wrap items-center gap-3 p-4">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-start" :aria-expanded="open === g.id" @click="open = open === g.id ? null : g.id">
                        <span class="text-ink-400 transition" :class="open === g.id ? 'rotate-90' : 'rtl:rotate-180'" aria-hidden="true">›</span>
                        <span class="truncate font-extrabold text-ink">{{ name(g) }}</span>
                        <span class="chip" :class="g.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-ink-100 text-ink-500'">{{ g.is_active ? L('مفعّلة', 'Live') : t('booking.coming_soon') }}</span>
                    </button>
                    <span class="chip bg-ink-100 text-ink-600" :title="L('موثّقون / نشطون', 'verified / active')">👷 {{ g.verified_pros }} / {{ g.active_pros }}</span>
                    <button v-if="g.waitlist_count" type="button" class="chip bg-gold-50 text-gold-700" @click="showWaitlist(g)">⏳ {{ g.waitlist_count }}</button>
                    <span class="text-xs text-ink-500">{{ g.districts.length }} {{ L('حي', 'districts') }}</span>
                    <button type="button" class="btn-ghost !px-3 !py-1.5 text-xs" @click="toggleCity(g)">{{ g.is_active ? L('إيقاف', 'Pause') : L('تفعيل', 'Go live') }}</button>
                </header>

                <div v-if="open === g.id" class="border-t border-ink-200 bg-ink-50 p-4">
                    <div v-if="g.districts.length" class="overflow-x-auto rounded-xl bg-white ring-1 ring-ink-200">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-ink-500">
                                <tr class="border-b border-ink-200">
                                    <th class="p-3 text-start">{{ L('الحي', 'District') }}</th>
                                    <th class="p-3 text-start">{{ L('الحالة', 'Status') }}</th>
                                    <th class="p-3 text-start">{{ L('فنيون موثّقون', 'Verified pros') }}</th>
                                    <th class="p-3 text-start">{{ L('نشطون الآن', 'Active now') }}</th>
                                    <th class="p-3 text-start">{{ L('انتظار', 'Waitlist') }}</th>
                                    <th class="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in g.districts" :key="d.id" class="border-b border-ink-100 last:border-0">
                                    <td class="p-3 font-semibold text-ink">{{ name(d) }}</td>
                                    <td class="p-3">
                                        <select :value="d.status" class="rounded-lg border-ink-200 py-1 pe-8 ps-2 text-xs font-bold" :class="d.status === 'active' ? 'text-emerald-700' : 'text-ink-500'" :aria-label="L('حالة الحي', 'District status')" @change="setStatus(d, $event.target.value)">
                                            <option value="active">{{ L('مفعّل', 'Active') }}</option>
                                            <option value="coming_soon">{{ t('booking.coming_soon') }}</option>
                                        </select>
                                    </td>
                                    <td class="p-3 tabular-nums">{{ d.verified_pros }}</td>
                                    <td class="p-3"><span class="chip tabular-nums" :class="supplyClass(d)">{{ d.active_pros }}</span></td>
                                    <td class="p-3">
                                        <button v-if="d.waitlist_count" type="button" class="chip bg-gold-50 tabular-nums text-gold-700" @click="showWaitlist(g, d)">{{ d.waitlist_count }}</button>
                                        <span v-else class="text-ink-400">0</span>
                                    </td>
                                    <td class="p-3 text-end"><button type="button" class="chip bg-crimson-50 text-crimson-700" :aria-label="L('حذف', 'Delete')" @click="remove(d)">✕</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-ink-200 bg-white p-4 text-center text-sm text-ink-500">{{ L('لا توجد أحياء: المدينة تُخدَم كاملة عند تفعيلها.', 'No districts: the whole city is served when live.') }}</p>

                    <form class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto_auto]" @submit.prevent="add(g)">
                        <input v-model="draft.name_ar" class="field !bg-white" dir="rtl" maxlength="80" required :placeholder="L('اسم الحي بالعربية', 'Arabic name')" />
                        <input v-model="draft.name_en" class="field !bg-white" dir="ltr" maxlength="80" :placeholder="L('الاسم بالإنجليزية (اختياري)', 'English name (optional)')" />
                        <select v-model="draft.status" class="field !bg-white" :aria-label="L('الحالة', 'Status')">
                            <option value="coming_soon">{{ t('booking.coming_soon') }}</option>
                            <option value="active">{{ L('مفعّل', 'Active') }}</option>
                        </select>
                        <button type="submit" class="btn-primary">+ {{ L('إضافة حي', 'Add district') }}</button>
                    </form>
                </div>
            </section>
        </div>

        <!-- waitlist entries -->
        <div v-if="waitlist" class="fixed inset-0 z-50 grid place-items-center bg-ink/50 p-4" @click.self="waitlist = null">
            <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-lift" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-extrabold text-ink">⏳ {{ waitlist.title }}</h2>
                    <button type="button" class="chip bg-ink-100" :aria-label="t('common.close')" @click="waitlist = null">✕</button>
                </div>
                <ul class="mt-4 divide-y divide-ink-100 text-sm">
                    <li v-for="row in waitlist.rows" :key="row.id" class="flex items-center justify-between gap-3 py-2.5">
                        <span>
                            <span class="block font-bold text-ink" dir="ltr">{{ row.phone }}</span>
                            <span class="block text-xs text-ink-500">{{ row.district ? name(row.district) : row.district_name || '—' }}<template v-if="row.category"> · {{ name(row.category) }}</template></span>
                        </span>
                        <span class="text-xs text-ink-400">{{ new Date(row.created_at).toLocaleDateString(locale === 'ar' ? 'ar-YE' : 'en-GB') }}</span>
                    </li>
                    <li v-if="!waitlist.rows.length" class="py-6 text-center text-ink-500">{{ L('لا يوجد مسجّلون.', 'Nobody yet.') }}</li>
                </ul>
            </div>
        </div>
    </AdminLayout>
</template>
