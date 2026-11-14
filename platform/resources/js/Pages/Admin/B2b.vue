<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

/** Admin → Business contracts (B2B): maintenance-contract leads and their pipeline. */
const { t, locale } = useI18n();
const api = useApi();
const L = (ar, en) => (locale.value === 'ar' ? ar : en);
const name = (row) => (row ? (locale.value === 'ar' ? row.name_ar : row.name_en || row.name_ar) : '');

const statuses = ['new', 'contacted', 'quoted', 'won', 'lost'];
const tab = ref('new');
const leads = ref([]);
const counts = ref({});
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    try {
        const res = await api.get('/admin/b2b-requests', { params: tab.value === 'all' ? {} : { status: tab.value } });
        leads.value = res.data;
        counts.value = res.counts || {};
        error.value = '';
    } catch (e) {
        error.value = t('common.error');
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(tab, load);

const total = computed(() => Object.values(counts.value).reduce((n, v) => n + Number(v), 0));

async function save(lead, patch) {
    try {
        Object.assign(lead, (await api.patch(`/admin/b2b-requests/${lead.id}`, patch)).data);
        if (patch.status) await load();
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {}).flat()[0] || t('common.error');
    }
}

const tone = { new: 'bg-crimson text-white', contacted: 'bg-ink-100 text-ink-600', quoted: 'bg-ink text-white', won: 'bg-emerald-50 text-emerald-700', lost: 'bg-ink-100 text-ink-400' };
const date = (iso) => new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-YE' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head :title="t('admin.b2b')" />
    <AdminLayout>
        <template #title>{{ t('admin.b2b') }}</template>

        <div class="mb-4 flex flex-wrap gap-1.5" role="tablist">
            <button
                v-for="s in [...statuses, 'all']" :key="s" type="button" role="tab" :aria-selected="tab === s"
                class="rounded-lg px-3 py-2 text-xs font-bold transition"
                :class="tab === s ? 'bg-ink text-white' : 'bg-white text-ink-600 ring-1 ring-ink-200 hover:text-ink'"
                @click="tab = s"
            >
                {{ s === 'all' ? L('الكل', 'All') : t(`b2b.status.${s}`) }}
                <span class="ms-1 tabular-nums opacity-70">{{ s === 'all' ? total : (counts[s] || 0) }}</span>
            </button>
        </div>

        <p v-if="error" class="mb-3 rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ error }}</p>
        <p v-if="loading" class="p-6 text-sm text-ink-500">{{ t('common.loading') }}</p>
        <p v-else-if="!leads.length" class="rounded-xl border border-dashed border-ink-200 bg-white p-8 text-center text-sm text-ink-500">{{ L('لا توجد طلبات في هذا التبويب.', 'No requests in this tab.') }}</p>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <article v-for="lead in leads" :key="lead.id" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-extrabold text-ink">{{ lead.business_name }}</h2>
                        <p class="text-xs text-ink-500">{{ lead.reference }} · {{ date(lead.created_at) }}</p>
                    </div>
                    <span class="chip shrink-0" :class="tone[lead.status]">{{ t(`b2b.status.${lead.status}`) }}</span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-ink-500">{{ t('b2b.business_type') }}</dt><dd class="font-semibold text-ink">{{ t(`b2b.types.${lead.business_type}`) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ t('b2b.district') }}</dt><dd class="font-semibold text-ink">{{ [name(lead.governorate), name(lead.district) || lead.district_name].filter(Boolean).join(' — ') || '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-xs text-ink-500">{{ t('b2b.facility_size') }}</dt><dd class="font-semibold text-ink">{{ lead.facility_size }}</dd></div>
                    <div v-if="lead.notes" class="col-span-2"><dt class="text-xs text-ink-500">{{ t('b2b.notes') }}</dt><dd class="whitespace-pre-line text-ink-600">{{ lead.notes }}</dd></div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <a :href="`https://wa.me/${lead.phone.replace(/\D/g, '')}`" target="_blank" rel="noopener noreferrer" class="chip bg-whatsapp text-white">WhatsApp <span dir="ltr">{{ lead.phone }}</span></a>
                    <span v-if="lead.contact_name" class="text-xs text-ink-500">{{ lead.contact_name }}</span>
                </div>

                <div class="mt-4 grid gap-2 border-t border-ink-100 pt-4 sm:grid-cols-[10rem_1fr]">
                    <select :value="lead.status" class="field !py-2 text-sm" :aria-label="L('الحالة', 'Status')" @change="save(lead, { status: $event.target.value })">
                        <option v-for="s in statuses" :key="s" :value="s">{{ t(`b2b.status.${s}`) }}</option>
                    </select>
                    <input :value="lead.admin_note" class="field !py-2 text-sm" maxlength="2000" :placeholder="L('ملاحظة داخلية (تُحفظ عند الخروج من الحقل)', 'Internal note (saved on blur)')" @change="save(lead, { admin_note: $event.target.value })" />
                </div>
                <p v-if="lead.handler" class="mt-2 text-[11px] text-ink-400">{{ L('آخر تحديث بواسطة', 'Last updated by') }} {{ lead.handler.name }}</p>
            </article>
        </div>
    </AdminLayout>
</template>
