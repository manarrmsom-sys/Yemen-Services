<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

const { t, locale } = useI18n();
const api = useApi();
const queue = ref({ data: [] });
const detail = ref(null);
const reason = ref('');

async function load() { queue.value = await api.get('/admin/kyc/queue'); }
async function open(id) { detail.value = (await api.get(`/admin/kyc/${id}`)).data; reason.value = ''; }
async function reviewDoc(docId, approved) {
    await api.post(`/admin/kyc/documents/${docId}/review`, { approved });
    await open(detail.value.id);
}
async function decide(verified) {
    await api.post(`/admin/kyc/${detail.value.id}/decide`, { verified, reason: reason.value, background_checked: verified });
    detail.value = null; await load();
}
onMounted(load);
</script>

<template>
    <Head :title="t('admin.kyc')" />
    <AdminLayout>
        <template #title>{{ t('admin.kyc') }}</template>

        <div class="grid gap-4 lg:grid-cols-[1fr_1.3fr]">
            <div class="card divide-y divide-obsidian-100">
                <button
                    v-for="p in queue.data" :key="p.id"
                    class="flex w-full items-center justify-between p-4 text-start hover:bg-offwhite"
                    @click="open(p.id)"
                >
                    <div>
                        <p class="font-bold">{{ p.display_name }}</p>
                        <p class="text-xs text-obsidian-900/50">{{ p.user?.phone }} · {{ p.kyc_documents_count }} docs</p>
                    </div>
                    <span class="chip bg-amber-50 text-amber-700">{{ p.kyc_status }}</span>
                </button>
                <p v-if="!queue.data.length" class="p-4 text-sm text-obsidian-900/40">—</p>
            </div>

            <div v-if="detail" class="card p-4">
                <p class="font-bold">{{ detail.display_name }}</p>
                <p class="text-sm text-obsidian-900/60">
                    {{ locale === 'ar' ? detail.primary_category?.name_ar : detail.primary_category?.name_en }} ·
                    {{ detail.years_experience }} yrs
                </p>

                <div class="mt-3 space-y-2">
                    <div v-for="d in detail.kyc_documents" :key="d.id" class="flex items-center justify-between rounded-xl border border-obsidian-100 p-3 text-sm">
                        <div>
                            <p class="font-semibold">{{ d.type }}</p>
                            <a :href="`/api/v1/admin/kyc/documents/${d.id}/file`" target="_blank" class="text-xs text-crimson">view file →</a>
                        </div>
                        <div class="flex gap-1">
                            <button class="chip bg-emerald-50 text-emerald-700" @click="reviewDoc(d.id, true)">✓</button>
                            <button class="chip bg-crimson-50 text-crimson-700" @click="reviewDoc(d.id, false)">✕</button>
                            <span class="chip bg-obsidian-100">{{ d.review_status }}</span>
                        </div>
                    </div>
                </div>

                <textarea v-model="reason" rows="2" class="field mt-3" placeholder="Rejection reason (if rejecting)" />
                <div class="mt-3 flex gap-2">
                    <button class="btn-primary flex-1" @click="decide(true)">Verify</button>
                    <button class="btn-ghost flex-1" @click="decide(false)">Reject</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
