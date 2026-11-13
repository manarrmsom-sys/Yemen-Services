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
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        wallets.value = await api.get('/admin/wallets', { params: { type: 'tradesperson' } });
        topups.value = await api.get('/admin/topups', { params: { status: 'pending' } });
        commissions.value = await api.get('/admin/commissions');
    } finally {
        loading.value = false;
    }
}

async function confirmTopup(id) { 
    if (confirm('هل أنت تأكد من إيداع هذا المبلغ في محفظة الفني؟')) {
        await api.post(`/admin/topups/${id}/confirm`, {}); 
        await load(); 
    }
}

async function rejectTopup(id) { 
    const note = prompt('سبب رفض عملية الشحن:', 'المبلغ لم يصل للحساب البنكي');
    if (note !== null) {
        await api.post(`/admin/topups/${id}/reject`, { note }); 
        await load(); 
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('admin.wallets')" />
    <AdminLayout>
        <template #title>{{ t('admin.wallets') }}</template>

        <!-- التنقل بين الأقسام -->
        <div class="mb-4 flex gap-2 border-b border-slate-200 pb-2">
            <button 
                v-for="x in ['wallets','topups','commissions']" 
                :key="x" 
                class="px-4 py-2 text-xs font-bold rounded-lg transition" 
                :class="tab === x ? 'bg-crimson-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" 
                @click="tab = x"
            >
                {{ x === 'wallets' ? 'المحافظ المالية' : x === 'topups' ? 'طلبات الشحن المعلقة' : 'سجل العمولات' }}
            </button>
        </div>

        <!-- تبويب المحافظ المالية -->
        <div v-show="tab === 'wallets'" class="border border-slate-200 bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="p-3 text-start">صاحب المحفظة</th>
                        <th class="p-3 text-start">الرصيد المتاح</th>
                        <th class="p-3 text-start">العمولات المستحقة</th>
                        <th class="p-3 text-start">إجمالي الأرباح</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in wallets.data" :key="w.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="p-3 font-semibold">{{ w.owner?.display_name || `#${w.owner_id}` }}</td>
                        <td class="p-3 font-bold text-emerald-600">{{ format(w.available_balance) }}</td>
                        <td class="p-3 font-bold text-red-600">{{ format(w.commission_due) }}</td>
                        <td class="p-3 text-slate-600">{{ format(w.lifetime_earnings) }}</td>
                    </tr>
                    <tr v-if="!wallets.data || !wallets.data.length">
                        <td colspan="4" class="p-6 text-center text-slate-400">لا توجد محافظ مسجلة حالياً</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- تبويب طلبات الشحن المعلقة -->
        <div v-show="tab === 'topups'" class="border border-slate-200 bg-white rounded-lg shadow-sm divide-y divide-slate-100">
            <div v-for="tp in topups.data" :key="tp.id" class="flex items-center justify-between p-4 text-sm hover:bg-slate-50">
                <div>
                    <p class="font-bold text-slate-900">{{ tp.profile?.display_name }} · <span class="text-emerald-600">{{ format(tp.amount) }}</span></p>
                    <p class="text-xs text-slate-500 mt-0.5">الطريقة: {{ tp.method }} · رقم المرجع: <span class="font-mono bg-slate-100 px-1 rounded">{{ tp.reference_code || '—' }}</span></p>
                </div>
                <div class="flex gap-2">
                    <button class="px-3 py-1.5 text-xs font-bold text-white bg-emerald-600 rounded hover:bg-emerald-700" @click="confirmTopup(tp.id)">تأكيد الشحن ✓</button>
                    <button class="px-3 py-1.5 text-xs font-bold text-white bg-red-600 rounded hover:bg-red-700" @click="rejectTopup(tp.id)">رفض ✕</button>
                </div>
            </div>
            <p v-if="!topups.data || !topups.data.length" class="p-6 text-center text-sm text-slate-400">لا توجد طلبات شحن معلقة بانتظار التأكيد</p>
        </div>

        <!-- تبويب سجل العمولات -->
        <div v-show="tab === 'commissions'" class="border border-slate-200 bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="p-3 text-start">رقم الطلب</th>
                        <th class="p-3 text-start">الفني</th>
                        <th class="p-3 text-start">مبلغ النسبة</th>
                        <th class="p-3 text-start">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in commissions.data" :key="c.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="p-3 font-mono font-bold">{{ c.order?.reference || `#${c.order_id}` }}</td>
                        <td class="p-3">{{ c.profile?.display_name }}</td>
                        <td class="p-3 font-bold text-red-600">{{ format(c.commission_amount) }} ({{ c.commission_percent }}%)</td>
                        <td class="p-3">
                            <span class="px-2 py-0.5 text-xs rounded bg-slate-100 font-medium">{{ c.status }}</span>
                        </td>
                    </tr>
                    <tr v-if="!commissions.data || !commissions.data.length">
                        <td colspan="4" class="p-6 text-center text-slate-400">لا توجد سجلات عمولات حتى الآن</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>