<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

/**
 * Admin → Tradespeople, with the go-live readiness indicator:
 * KYC verified + prepaid float ≥ minimum + a district assigned.
 */
const { t, locale } = useI18n();
const api = useApi();
const { format } = useMoney();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);
const name = (row) => (row ? (locale.value === 'ar' ? row.name_ar : row.name_en || row.name_ar) : null);

const pros = ref([]);
const loading = ref(true);
const failed = ref(false);
const searchQuery = ref('');
const filter = ref('all'); // all | ready | blocked

async function loadPros() {
    loading.value = true;
    try {
        pros.value = (await api.get('/admin/tradespeople', { params: { per_page: 100 } })).data || [];
    } catch (e) {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(loadPros);

const filteredPros = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return pros.value.filter((p) => (filter.value === 'all' || (filter.value === 'ready') === p.readiness.ready)
        && (!q || [p.display_name, p.headline_ar, p.headline_en, p.user?.phone, p.user?.email].some((v) => v && String(v).toLowerCase().includes(q))));
});
const readyCount = computed(() => pros.value.filter((p) => p.readiness.ready).length);

const checks = (p) => [
    { ok: p.readiness.kyc, label: L('توثيق KYC مكتمل', 'KYC verified'), hint: L(`الحالة: ${p.kyc_status} · ${p.kyc_documents_count} مستندات`, `Status: ${p.kyc_status} · ${p.kyc_documents_count} documents`) },
    { ok: p.readiness.wallet, label: L('رصيد محفظة كافٍ', 'Wallet funded'), hint: `${format(p.readiness.balance)} / ${format(p.readiness.min_float)}` },
    { ok: p.readiness.district, label: L('حي محدد', 'District set'), hint: [name(p.governorate), name(p.district)].filter(Boolean).join(' — ') || L('لم يُحدَّد', 'Not set') },
];
</script>

<template>
    <Head :title="t('admin.pros')" />
    <AdminLayout>
        <template #title>{{ t('admin.pros') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-1.5 rounded-xl bg-ink-100 p-1 text-xs font-bold">
                <button v-for="[key, label] in [['all', L('الكل', 'All')], ['ready', L('جاهزون', 'Ready')], ['blocked', L('غير جاهزين', 'Not ready')]]" :key="key" type="button" class="rounded-lg px-3 py-1.5 transition" :class="filter === key ? 'bg-white text-ink shadow-sm' : 'text-ink-500'" @click="filter = key">{{ label }}</button>
            </div>
            <p class="text-sm text-ink-500">{{ L(`${readyCount} جاهز من ${pros.length}`, `${readyCount} of ${pros.length} ready`) }}</p>
            <input v-model="searchQuery" type="search" :placeholder="t('common.search')" class="field !w-64 !bg-white !py-2" />
        </div>

        <p v-if="loading" class="p-8 text-center text-sm text-ink-500">{{ t('common.loading') }}</p>
        <p v-else-if="failed" class="rounded-xl border border-crimson-200 bg-crimson-50 p-4 text-sm font-semibold text-crimson-700">{{ L('تعذّر تحميل الفنيين.', 'Could not load tradespeople.') }}</p>
        <p v-else-if="!filteredPros.length" class="rounded-xl border border-dashed border-ink-200 bg-white p-8 text-center text-sm text-ink-500">{{ L('لا يوجد فنيون مطابقون.', 'No matching tradespeople.') }}</p>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="pro in filteredPros" :key="pro.id" class="card flex flex-col p-4">
                <div class="flex items-start gap-3">
                    <img v-if="pro.image_url" :src="pro.image_url" :alt="pro.display_name" class="h-12 w-12 shrink-0 rounded-full object-cover" loading="lazy" />
                    <div v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-ink text-lg font-black text-white">{{ pro.display_name?.charAt(0) }}</div>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-extrabold text-ink">{{ pro.display_name }}</h2>
                        <p class="truncate text-xs text-ink-500">{{ name(pro.category) }}<template v-if="pro.user?.phone"> · <span dir="ltr">{{ pro.user.phone }}</span></template></p>
                    </div>
                    <span class="chip shrink-0" :class="pro.readiness.ready ? 'bg-emerald-50 text-emerald-700' : 'bg-crimson-50 text-crimson-700'">
                        {{ pro.readiness.ready ? L('جاهز', 'Ready') : L('غير جاهز', 'Not ready') }}
                    </span>
                </div>

                <!-- readiness indicator -->
                <div class="mt-4 rounded-xl bg-ink-50 p-3">
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-ink-500">{{ L('جاهزية الفني', 'Readiness') }}</p>
                    <ul class="space-y-2">
                        <li v-for="check in checks(pro)" :key="check.label" class="flex items-start gap-2 text-sm">
                            <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-black text-white" :class="check.ok ? 'bg-emerald-600' : 'bg-crimson'" aria-hidden="true">{{ check.ok ? '✓' : '✕' }}</span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-ink">{{ check.label }}<span class="sr-only">: {{ check.ok ? L('مكتمل', 'done') : L('ناقص', 'missing') }}</span></span>
                                <span class="block truncate text-xs text-ink-500">{{ check.hint }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="mt-3 flex items-center justify-between text-xs text-ink-500">
                    <span class="font-bold text-ink"><span class="text-gold" aria-hidden="true">★</span> {{ Number(pro.rating_avg || 0).toFixed(1) }}</span>
                    <span>{{ pro.completed_orders || 0 }} {{ L('طلب مكتمل', 'completed') }}</span>
                    <span>{{ pro.is_accepting_jobs ? L('يستقبل الطلبات', 'Accepting jobs') : L('متوقف', 'Paused') }}</span>
                </div>

                <div class="mt-3 flex gap-2 border-t border-ink-100 pt-3">
                    <Link :href="route('admin.kyc')" class="btn-ghost flex-1 !py-2 text-xs">{{ L('مراجعة التوثيق', 'Review KYC') }}</Link>
                    <Link :href="route('admin.wallets')" class="btn-ghost flex-1 !py-2 text-xs">{{ L('المحفظة', 'Wallet') }}</Link>
                </div>
            </article>
        </div>
    </AdminLayout>
</template>
