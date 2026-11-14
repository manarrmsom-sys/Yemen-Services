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
const loading = ref(false);

async function load() { 
    queue.value = await api.get('/admin/kyc/queue'); 
}

async function open(id) { 
    detail.value = (await api.get(`/admin/kyc/${id}`)).data; 
    reason.value = ''; 
}

async function reviewDoc(docId, approved) {
    await api.post(`/admin/kyc/documents/${docId}/review`, { approved });
    await open(detail.value.id);
}

async function decide(verified) {
    if (!verified && !reason.value.trim()) {
        alert('يرجى كتابة سبب الرفض قبل التأكيد');
        return;
    }
    loading.value = true;
    try {
        await api.post(`/admin/kyc/${detail.value.id}/decide`, { 
            verified, 
            reason: reason.value, 
            background_checked: verified 
        });
        detail.value = null; 
        await load();
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('admin.kyc')" />
    <AdminLayout>
        <template #title>{{ t('admin.kyc') }}</template>

        <div class="grid gap-4 lg:grid-cols-[1fr_1.3fr]">
            <!-- القائمة المنسدلة للطلبات -->
            <div class="border border-slate-200 bg-white rounded-lg shadow-sm divide-y divide-slate-100 overflow-hidden">
                <div class="p-3 bg-slate-50 border-b border-slate-200 font-bold text-xs text-slate-600">
                    قائمة طلبات التوثيق المعلقة
                </div>
                <button
                    v-for="p in queue.data" :key="p.id"
                    class="flex w-full items-center justify-between p-4 text-start hover:bg-slate-50 transition"
                    :class="{ 'bg-slate-100': detail?.id === p.id }"
                    @click="open(p.id)"
                >
                    <div>
                        <p class="font-bold text-obsidian-900">{{ p.display_name }}</p>
                        <p class="text-xs text-slate-500">{{ p.user?.phone }} · {{ p.kyc_documents_count }} مستندات</p>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-crimson-50 text-crimson-700 border border-crimson-200">
                        {{ p.kyc_status }}
                    </span>
                </button>
                <p v-if="!queue.data || !queue.data.length" class="p-6 text-center text-sm text-slate-400">لا توجد طلبات توثيق بانتظار المراجعة حالياً</p>
            </div>

            <!-- تفاصيل الطلب والمستندات -->
            <div v-if="detail" class="border border-slate-200 bg-white rounded-lg shadow-sm p-5">
                <div class="border-b border-slate-100 pb-3 mb-4">
                    <p class="font-bold text-lg text-obsidian-900">{{ detail.display_name }}</p>
                    <p class="text-xs text-slate-500">
                        {{ locale === 'ar' ? detail.primary_category?.name_ar : detail.primary_category?.name_en }} ·
                        خبرة {{ detail.years_experience }} سنوات
                    </p>
                </div>

                <div class="space-y-3">
                    <p class="text-xs font-bold text-slate-600">المستندات المرفقة:</p>
                    <div v-for="d in detail.kyc_documents" :key="d.id" class="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-sm">
                        <div>
                            <p class="font-semibold text-xs">{{ d.type }}</p>
                            <a :href="`/api/v1/admin/kyc/documents/${d.id}/file`" target="_blank" class="text-xs text-blue-600 underline font-medium">عرض الملف ←</a>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-xs rounded bg-slate-100 font-mono">{{ d.review_status }}</span>
                            <button class="px-3 py-1 text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 rounded hover:bg-emerald-100" @click="reviewDoc(d.id, true)">قبول ✓</button>
                            <button class="px-3 py-1 text-xs bg-red-50 text-red-700 border border-red-200 rounded hover:bg-red-100" @click="reviewDoc(d.id, false)">رفض ✕</button>
                        </div>
                    </div>
                </div>

                <div class="mt-5 pt-4 border-t border-slate-100">
                    <label class="block text-xs font-bold text-slate-600 mb-1">سبب الرفض (مطلوب عند قرار الرفض):</label>
                    <textarea v-model="reason" rows="2" class="w-full border border-slate-300 rounded p-2 text-sm" placeholder="اكتب سبب رفض التوثيق هنا..." />
                    
                    <div class="mt-4 flex gap-2">
                        <button :disabled="loading" class="flex-1 py-2 text-xs font-bold text-white bg-emerald-600 rounded hover:bg-emerald-700" @click="decide(true)">
                            اعتماد وتوثيق الحساب
                        </button>
                        <button :disabled="loading" class="flex-1 py-2 text-xs font-bold text-white bg-red-600 rounded hover:bg-red-700" @click="decide(false)">
                            رفض الطلب
                        </button>
                    </div>
                </div>
            </div>
            
            <div v-else class="border border-dashed border-slate-300 rounded-lg p-8 text-center text-slate-400">
                اختر فني من القائمة لعرض مستنداته واتخاذ القرار
            </div>
        </div>
    </AdminLayout>
</template>