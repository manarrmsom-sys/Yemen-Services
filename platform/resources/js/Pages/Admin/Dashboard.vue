<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

const { t } = useI18n();
const { format } = useMoney();
const api = useApi();

const m = ref(null);
const live = ref([]);
let timer;

async function refresh() {
    m.value = (await api.get('/admin/dashboard/metrics')).data;
    live.value = (await api.get('/admin/dashboard/live')).data;
}

onMounted(() => { refresh(); timer = setInterval(refresh, 15000); });
onUnmounted(() => clearInterval(timer));

const cards = () => [
    ['admin.orders_today', m.value?.orders_today, false],
    ['admin.open_orders', m.value?.orders_open, false],
    ['admin.pending_dispatch', m.value?.orders_pending_dispatch, false],
    ['admin.gmv_today', m.value?.gmv_today, true],
    ['admin.commission_month', m.value?.commission_accrued_month, true],
    ['admin.kyc_pending', m.value?.kyc_pending, false],
    ['admin.topups_pending', m.value?.topups_pending, false],
    ['admin.receivable', m.value?.commission_receivable, true],
];
</script>

<template>
    <Head :title="t('admin.dashboard')" />
    <AdminLayout>
        <template #title>{{ t('admin.dashboard') }}</template>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="[label, val, money] in cards()" :key="label" class="card p-4">
                <p class="text-xs text-obsidian-900/50">{{ t(label) }}</p>
                <p class="mt-1 text-xl font-extrabold text-obsidian-900">
                    {{ val == null ? '…' : money ? format(val) : val }}
                </p>
            </div>
        </div>

        <h2 class="mt-8 text-lg font-bold">{{ t('admin.dispatch') }} — live</h2>
        <div class="card mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-offwhite text-start text-xs text-obsidian-900/50">
                    <tr>
                        <th class="p-3 text-start">Ref</th>
                        <th class="p-3 text-start">Service</th>
                        <th class="p-3 text-start">Gov.</th>
                        <th class="p-3 text-start">Pro</th>
                        <th class="p-3 text-start">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in live" :key="o.id" class="border-t border-obsidian-100">
                        <td class="p-3 font-semibold">{{ o.reference }}</td>
                        <td class="p-3">{{ o.service?.name_en }}</td>
                        <td class="p-3">{{ o.governorate?.code }}</td>
                        <td class="p-3">{{ o.tradesperson?.display_name || '—' }}</td>
                        <td class="p-3"><span class="chip bg-crimson-50 text-crimson-700">{{ t(`order.status.${o.status}`) }}</span></td>
                    </tr>
                    <tr v-if="!live.length"><td colspan="5" class="p-4 text-center text-obsidian-900/40">—</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
