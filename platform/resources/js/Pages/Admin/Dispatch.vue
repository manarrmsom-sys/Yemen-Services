<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

/**
 * Concierge operations room.
 *  - Live KPIs (acceptance time, fulfilment, estimate accuracy).
 *  - A response timer on every order nobody accepted yet; past the SLA
 *    (10 min by default) the card turns crimson and jumps to "needs attention".
 *  - One-tap WhatsApp reminder, or re-route to another ready pro in the same
 *    district.
 * The board refreshes every 10 s; timers tick locally in between.
 */
const { t, locale } = useI18n();
const api = useApi();
const name = (row) => (row ? (locale.value === 'ar' ? row.name_ar : row.name_en || row.name_ar) : '');

const board = ref({});
const kpis = ref(null);
const sla = ref(10);
const failed = ref(false);
const now = ref(Date.now());
const alternates = reactive({}); // jobId -> { loading, list }
let poll;
let tick;

const columns = ['queued', 'sent', 'delivered', 'accepted'];

async function load() {
    try {
        const res = await api.get('/admin/dispatch/board');
        board.value = res.data || {};
        kpis.value = res.kpis;
        sla.value = res.sla_minutes;
        failed.value = false;
    } catch (e) {
        failed.value = true;
    }
}
onMounted(() => {
    load();
    poll = setInterval(load, 10000);
    tick = setInterval(() => { now.value = Date.now(); }, 15000);
});
onUnmounted(() => {
    clearInterval(poll);
    clearInterval(tick);
});

// Minutes waited, recomputed from the server timestamp so it stays live between polls.
const waited = (job) => (job.waiting_since ? Math.max(0, Math.floor((now.value - new Date(job.waiting_since).getTime()) / 60000)) : null);
const overdue = (job) => job.waiting && waited(job) >= sla.value;

const attention = computed(() => columns.slice(0, 3)
    .flatMap((c) => board.value[c] || [])
    .filter(overdue)
    .sort((a, b) => waited(b) - waited(a)));

const kpiCards = computed(() => {
    const k = kpis.value || {};
    return [
        { label: t('dispatch.kpi_acceptance'), value: k.avg_acceptance_minutes, unit: t('dispatch.minutes'), sample: k.accepted_orders, bad: k.avg_acceptance_minutes != null && k.avg_acceptance_minutes > sla.value },
        { label: t('dispatch.kpi_fulfilment'), value: k.fulfilment_rate, unit: '%', sample: k.ended_orders, bad: k.fulfilment_rate != null && k.fulfilment_rate < 80 },
        { label: t('dispatch.kpi_accuracy'), value: k.estimate_accuracy, unit: '%', sample: k.priced_orders, bad: k.estimate_accuracy != null && k.estimate_accuracy < 85 },
    ];
});

async function toggleAlternates(job) {
    if (alternates[job.id]) {
        delete alternates[job.id];
        return;
    }
    alternates[job.id] = { loading: true, list: [] };
    try {
        alternates[job.id].list = (await api.get(`/admin/dispatch/${job.id}/alternates`)).data;
    } finally {
        if (alternates[job.id]) alternates[job.id].loading = false;
    }
}

// The link opens WhatsApp; this records the intervention on the order timeline.
const nudge = (job, proId = null) => api.post(`/admin/dispatch/${job.id}/nudge`, { tradesperson_profile_id: proId }).catch(() => {});
</script>

<template>
    <Head :title="t('admin.dispatch')" />
    <AdminLayout>
        <template #title>{{ t('admin.dispatch') }}</template>

        <!-- Live KPIs -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="card in kpiCards" :key="card.label" class="card p-4">
                <p class="text-xs font-bold text-ink-500">{{ card.label }}</p>
                <p class="mt-1 text-3xl font-black tabular-nums" :class="card.bad ? 'text-crimson' : 'text-ink'">
                    <template v-if="card.value != null">{{ card.value }}<span class="ms-1 text-sm font-bold text-ink-500">{{ card.unit }}</span></template>
                    <span v-else class="text-lg text-ink-400">{{ t('dispatch.no_data') }}</span>
                </p>
                <p class="mt-1 text-[11px] text-ink-400">{{ t('dispatch.window', { days: kpis?.window_days ?? 30 }) }} · {{ card.sample ?? 0 }}</p>
            </div>
        </div>

        <p v-if="failed" class="mt-3 rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ t('common.error') }}</p>

        <!-- Needs attention -->
        <section class="mt-4 rounded-2xl border-2 p-4" :class="attention.length ? 'border-crimson bg-crimson-50' : 'border-ink-200 bg-white'" aria-live="polite">
            <h2 class="flex items-center gap-2 text-sm font-extrabold" :class="attention.length ? 'text-crimson-700' : 'text-ink'">
                <span v-if="attention.length" class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-crimson opacity-60" /><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-crimson" /></span>
                {{ t('dispatch.attention') }} ({{ attention.length }})
            </h2>
            <p v-if="!attention.length" class="mt-1 text-sm text-ink-500">{{ t('dispatch.all_clear') }}</p>
            <ul v-else class="mt-3 flex flex-wrap gap-2">
                <li v-for="job in attention" :key="job.id" class="rounded-xl bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-crimson/30">
                    <span class="font-bold text-ink">{{ job.order?.reference }}</span>
                    <span class="ms-2 font-black tabular-nums text-crimson">{{ waited(job) }} {{ t('dispatch.min_short') }}</span>
                    <span class="ms-2 text-xs text-ink-500">{{ job.order?.district || name(job.order?.governorate) }}</span>
                </li>
            </ul>
        </section>

        <!-- Board -->
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div v-for="col in columns" :key="col" class="rounded-2xl bg-white p-3 ring-1 ring-ink-200">
                <p class="mb-2 text-xs font-bold uppercase text-ink-500">{{ t(`dispatch.col_${col}`) }} ({{ (board[col] || []).length }})</p>
                <p v-if="!(board[col] || []).length" class="py-6 text-center text-xs text-ink-400">{{ t('dispatch.empty') }}</p>

                <div class="space-y-2">
                    <article
                        v-for="job in (board[col] || [])" :key="job.id"
                        class="rounded-xl border p-3 text-sm transition"
                        :class="overdue(job) ? 'border-crimson bg-crimson-50' : 'border-ink-200'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-bold text-ink">{{ job.order?.reference }}</p>
                            <!-- response timer -->
                            <span
                                v-if="job.waiting" class="chip shrink-0 tabular-nums"
                                :class="overdue(job) ? 'bg-crimson text-white' : 'bg-ink-100 text-ink-600'"
                                :title="overdue(job) ? t('dispatch.overdue', { n: sla }) : t('dispatch.on_time')"
                            >⏱ {{ waited(job) }} {{ t('dispatch.min_short') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-600">{{ name(job.order?.service) }}</p>
                        <p class="text-xs text-ink-500">{{ [name(job.order?.governorate), job.order?.district].filter(Boolean).join(' — ') }}</p>
                        <p v-if="job.tradesperson" class="mt-1 text-xs text-ink-500">👷 {{ job.tradesperson.display_name }}</p>
                        <p v-if="overdue(job)" class="mt-2 text-xs font-bold text-crimson-700">{{ t('dispatch.overdue', { n: sla }) }}</p>

                        <div v-if="job.waiting" class="mt-2 flex flex-wrap gap-1.5">
                            <a v-if="job.wa_deeplink" :href="job.wa_deeplink" target="_blank" rel="noopener noreferrer" class="chip bg-whatsapp text-white" @click="nudge(job)">{{ t('dispatch.remind') }}</a>
                            <button type="button" class="chip bg-ink text-white" :aria-expanded="!!alternates[job.id]" @click="toggleAlternates(job)">{{ t('dispatch.reroute') }}</button>
                        </div>

                        <div v-if="alternates[job.id]" class="mt-2 rounded-lg bg-white p-2 ring-1 ring-ink-200">
                            <p v-if="alternates[job.id].loading" class="text-xs text-ink-500">{{ t('common.loading') }}</p>
                            <p v-else-if="!alternates[job.id].list.length" class="text-xs text-ink-500">{{ job.order?.district_id ? t('dispatch.alternates_none') : t('dispatch.alternates_no_district') }}</p>
                            <ul v-else class="space-y-1.5">
                                <li v-for="pro in alternates[job.id].list" :key="pro.id" class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 truncate text-xs font-semibold text-ink">{{ pro.display_name }} <span class="font-normal text-ink-500">★ {{ Number(pro.rating_avg || 0).toFixed(1) }}</span></span>
                                    <a :href="pro.wa_deeplink" target="_blank" rel="noopener noreferrer" class="chip shrink-0 bg-whatsapp text-white" @click="nudge(job, pro.id)">{{ t('dispatch.open_whatsapp') }}</a>
                                </li>
                            </ul>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
