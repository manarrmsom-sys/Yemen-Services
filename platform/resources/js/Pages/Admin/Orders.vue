<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

const { t, locale } = useI18n();
const { format } = useMoney();
const api = useApi();

const page = ref({ data: [] });
const filters = reactive({ status: '', q: '', page: 1 });
const selected = ref(null);

async function load() {
    page.value = await api.get('/admin/orders', { params: filters });
}
async function open(ref_) {
    selected.value = (await api.get(`/admin/orders/${ref_}`)).data;
}
async function dispatch(ref_) {
    await api.post(`/admin/orders/${ref_}/dispatch`, { mode: 'broadcast' });
    await open(ref_);
}
async function transition(ref_, status) {
    await api.post(`/admin/orders/${ref_}/transition`, { status });
    await open(ref_); await load();
}

onMounted(load);
</script>

<template>
    <Head :title="t('admin.orders')" />
    <AdminLayout>
        <template #title>{{ t('admin.orders') }}</template>

        <div class="flex flex-wrap gap-2">
            <input v-model="filters.q" class="field max-w-xs" :placeholder="t('common.search')" @keyup.enter="load" />
            <select v-model="filters.status" class="field max-w-[12rem]" @change="load">
                <option value="">{{ t('common.all') }}</option>
                <option v-for="s in ['pending_dispatch','dispatched','accepted','en_route','in_progress','completed','closed','cancelled']" :key="s" :value="s">
                    {{ t(`order.status.${s}`) }}
                </option>
            </select>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-offwhite text-xs text-obsidian-900/50">
                        <tr><th class="p-3 text-start">Ref</th><th class="p-3 text-start">Service</th><th class="p-3 text-start">Total</th><th class="p-3 text-start">Status</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in page.data" :key="o.id" class="cursor-pointer border-t border-obsidian-100 hover:bg-offwhite" @click="open(o.reference)">
                            <td class="p-3 font-semibold">{{ o.reference }}</td>
                            <td class="p-3">{{ locale === 'ar' ? o.service?.name_ar : o.service?.name_en }}</td>
                            <td class="p-3">{{ format(o.total_estimate) }}</td>
                            <td class="p-3"><span class="chip bg-crimson-50 text-crimson-700">{{ t(`order.status.${o.status}`) }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="selected" class="card p-4">
                <div class="flex items-center justify-between">
                    <p class="font-bold">{{ selected.reference }}</p>
                    <span class="chip bg-obsidian-100">{{ t(`order.status.${selected.status}`) }}</span>
                </div>
                <p class="mt-2 text-sm text-obsidian-900/60">{{ selected.contact_name }} · {{ selected.contact_phone }}</p>
                <p class="text-sm">{{ format(selected.total_estimate) }} · {{ selected.payment_method }}</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn-primary !py-2 !px-3 text-xs" @click="dispatch(selected.reference)">Re-dispatch</button>
                    <button class="btn-ghost !py-2 !px-3 text-xs" @click="transition(selected.reference, 'in_progress')">Mark in progress</button>
                    <button class="btn-ghost !py-2 !px-3 text-xs" @click="transition(selected.reference, 'completed')">Complete</button>
                    <button class="btn-ghost !py-2 !px-3 text-xs" @click="transition(selected.reference, 'cancelled')">Cancel</button>
                </div>

                <a v-if="selected.dispatch_jobs?.length" :href="selected.dispatch_jobs.at(-1).wa_deeplink" target="_blank" class="btn-whatsapp mt-3 w-full text-xs">
                    Open dispatch WhatsApp link
                </a>

                <ol class="mt-4 space-y-2 border-s border-obsidian-100 ps-3 text-xs">
                    <li v-for="e in selected.events" :key="e.id">
                        <strong>{{ e.to_status || e.type }}</strong> · {{ new Date(e.created_at).toLocaleString() }}
                        <span v-if="e.message" class="text-obsidian-900/60"> — {{ e.message }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </AdminLayout>
</template>
