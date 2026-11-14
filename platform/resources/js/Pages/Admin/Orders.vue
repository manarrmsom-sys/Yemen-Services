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
    if (confirm(`هل أنت تأكد من تغيير حالة الطلب إلى (${status})؟`)) {
        await api.post(`/admin/orders/${ref_}/transition`, { status });
        await open(ref_); 
        await load();
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('admin.orders')" />
    <AdminLayout>
        <template #title>{{ t('admin.orders') }}</template>

        <!-- فلترة الطلبات -->
        <div class="flex flex-wrap gap-2 mb-4">
            <input v-model="filters.q" class="border border-slate-300 rounded px-3 py-2 text-sm max-w-xs" :placeholder="t('common.search')" @keyup.enter="load" />
            <select v-model="filters.status" class="border border-slate-300 rounded px-3 py-2 text-sm min-w-[12rem]" @change="load">
                <option value="">{{ t('common.all') }}</option>
                <option v-for="s in ['pending_dispatch','dispatched','accepted','en_route','in_progress','completed','closed','cancelled']" :key="s" :value="s">
                    {{ t(`order.status.${s}`) }}
                </option>
            </select>
            <button @click="load" class="px-4 py-2 bg-slate-800 text-white text-xs rounded hover:bg-slate-900">تطبيق الفلترة</button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <!-- جدول الطلبات -->
            <div class="border border-slate-200 bg-white rounded-lg shadow-sm overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="p-3 text-start">المرجع</th>
                            <th class="p-3 text-start">الخدمة</th>
                            <th class="p-3 text-start">الإجمالي</th>
                            <th class="p-3 text-start">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in page.data" :key="o.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" :class="{ 'bg-slate-100': selected?.reference === o.reference }" @click="open(o.reference)">
                            <td class="p-3 font-semibold font-mono text-xs">{{ o.reference }}</td>
                            <td class="p-3">{{ locale === 'ar' ? o.service?.name_ar : o.service?.name_en }}</td>
                            <td class="p-3 font-bold">{{ format(o.total_estimate) }}</td>
                            <td class="p-3">
                                <span class="px-2 py-1 text-xs rounded bg-red-50 text-red-700 font-medium">
                                    {{ t(`order.status.${o.status}`) }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!page.data || !page.data.length">
                            <td colspan="4" class="p-6 text-center text-slate-400">لا توجد طلبات تطابق معايير البحث</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- لوحة تحكم الطلب المختار -->
            <div v-if="selected" class="border border-slate-200 bg-white rounded-lg shadow-sm p-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div>
                        <p class="font-bold text-base font-mono">{{ selected.reference }}</p>
                        <p class="text-xs text-slate-500">{{ selected.contact_name }} · {{ selected.contact_phone }}</p>
                    </div>
                    <span class="px-2 py-1 text-xs rounded bg-slate-100 font-semibold">{{ t(`order.status.${selected.status}`) }}</span>
                </div>
                
                <div class="my-3 text-sm">
                    <p><strong>الإجمالي:</strong> {{ format(selected.total_estimate) }}</p>
                    <p><strong>طريقة الدفع:</strong> {{ selected.payment_method }}</p>
                </div>

                <!-- أزرار الإجراءات على الطلب -->
                <div class="mt-4 pt-3 border-t border-slate-100">
                    <p class="text-xs font-bold text-slate-600 mb-2">إجراءات التحكم بالطلب:</p>
                    <div class="flex flex-wrap gap-2">
                        <button class="px-3 py-1.5 text-xs bg-blue-600 text-white rounded hover:bg-blue-700" @click="dispatch(selected.reference)">إعادة توجيه (Re-dispatch)</button>
                        <button class="px-3 py-1.5 text-xs bg-crimson-700 text-white rounded hover:bg-crimson-700" @click="transition(selected.reference, 'in_progress')">تعيين كقيد التنفيذ</button>
                        <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700" @click="transition(selected.reference, 'completed')">إكمال الطلب</button>
                        <button class="px-3 py-1.5 text-xs bg-red-600 text-white rounded hover:bg-red-700" @click="transition(selected.reference, 'cancelled')">إلغاء الطلب</button>
                    </div>
                </div>

                <a v-if="selected.dispatch_jobs?.length" :href="selected.dispatch_jobs.at(-1).wa_deeplink" target="_blank" class="mt-4 block w-full text-center py-2 text-xs bg-emerald-600 text-white font-bold rounded hover:bg-emerald-700">
                    فتح رابط التوزيع عبر WhatsApp
                </a>

                <!-- سجل الأحداث والتغييرات -->
                <div class="mt-4 pt-3 border-t border-slate-100">
                    <p class="text-xs font-bold text-slate-600 mb-2">سجل أحداث الطلب:</p>
                    <ol class="space-y-2 border-s-2 border-slate-200 ps-3 text-xs">
                        <li v-for="e in selected.events" :key="e.id">
                            <strong>{{ e.to_status || e.type }}</strong> · {{ new Date(e.created_at).toLocaleString() }}
                            <span v-if="e.message" class="text-slate-500"> — {{ e.message }}</span>
                        </li>
                    </ol>
                </div>
            </div>
            
            <div v-else class="border border-dashed border-slate-300 rounded-lg p-8 text-center text-slate-400">
                اختر طلباً من الجدول لعرض تفاصيله والتحكم به
            </div>
        </div>
    </AdminLayout>
</template>