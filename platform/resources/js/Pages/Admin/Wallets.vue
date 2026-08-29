<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

const { t } = useI18n();
const { format } = useMoney();
const api = useApi();

const tab = ref('wallets');
const wallets = ref({ data: [] });
const topups = ref({ data: [] });
const commissions = ref({ data: [] });

async function load() {
    wallets.value = await api.get('/admin/wallets', { params: { type: 'tradesperson' } });
    topups.value = await api.get('/admin/topups', { params: { status: 'pending' } });
    commissions.value = await api.get('/admin/commissions');
}
async function confirmTopup(id) { await api.post(`/admin/topups/${id}/confirm`, {}); await load(); }
async function rejectTopup(id) { await api.post(`/admin/topups/${id}/reject`, { note: 'Not matched' }); await load(); }
onMounted(load);
</script>

<template>
    <Head :title="t('admin.wallets')" />
    <AdminLayout>
        <template #title>{{ t('admin.wallets') }}</template>

        <div class="mb-4 flex gap-2">
            <button v-for="x in ['wallets','topups','commissions']" :key="x" class="chip" :class="tab === x ? 'bg-crimson text-white' : 'bg-obsidian-100'" @click="tab = x">{{ x }}</button>
        </div>

        <div v-show="tab === 'wallets'" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-offwhite text-xs text-obsidian-900/50"><tr>
                    <th class="p-3 text-start">Owner</th><th class="p-3 text-start">Available</th>
                    <th class="p-3 text-start">Commission due</th><th class="p-3 text-start">Lifetime</th>
                </tr></thead>
                <tbody>
                    <tr v-for="w in wallets.data" :key="w.id" class="border-t border-obsidian-100">
                        <td class="p-3">{{ w.owner?.display_name || `#${w.owner_id}` }}</td>
                        <td class="p-3">{{ format(w.available_balance) }}</td>
                        <td class="p-3 text-crimson">{{ format(w.commission_due) }}</td>
                        <td class="p-3">{{ format(w.lifetime_earnings) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-show="tab === 'topups'" class="card divide-y divide-obsidian-100">
            <div v-for="tp in topups.data" :key="tp.id" class="flex items-center justify-between p-4 text-sm">
                <div>
                    <p class="font-bold">{{ tp.profile?.display_name }} · {{ format(tp.amount) }}</p>
                    <p class="text-xs text-obsidian-900/50">{{ tp.method }} · {{ tp.reference_code || '—' }}</p>
                </div>
                <div class="flex gap-1">
                    <button class="chip bg-emerald-50 text-emerald-700" @click="confirmTopup(tp.id)">Confirm</button>
                    <button class="chip bg-crimson-50 text-crimson-700" @click="rejectTopup(tp.id)">Reject</button>
                </div>
            </div>
            <p v-if="!topups.data.length" class="p-4 text-sm text-obsidian-900/40">—</p>
        </div>

        <div v-show="tab === 'commissions'" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-offwhite text-xs text-obsidian-900/50"><tr>
                    <th class="p-3 text-start">Order</th><th class="p-3 text-start">Pro</th>
                    <th class="p-3 text-start">Amount</th><th class="p-3 text-start">Status</th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in commissions.data" :key="c.id" class="border-t border-obsidian-100">
                        <td class="p-3">{{ c.order?.reference }}</td>
                        <td class="p-3">{{ c.profile?.display_name }}</td>
                        <td class="p-3">{{ format(c.commission_amount) }} ({{ c.commission_percent }}%)</td>
                        <td class="p-3"><span class="chip bg-obsidian-100">{{ c.status }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
