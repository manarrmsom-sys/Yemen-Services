<script setup>
import { onMounted, ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

const { t, locale } = useI18n();
const api = useApi();
const pros = ref([]);
const loading = ref(true);
const searchQuery = ref('');

async function loadPros() {
    loading.value = true;
    try {
        const response = await api.get('/tradespeople');
        pros.value = response.data || response || [];
    } finally {
        loading.value = false;
    }
}

const filteredPros = computed(() => {
    if (!searchQuery.value.trim()) return pros.value;
    const q = searchQuery.value.toLowerCase();
    return pros.value.filter(p => 
        (p.display_name && p.display_name.toLowerCase().includes(q)) ||
        (p.headline_ar && p.headline_ar.toLowerCase().includes(q)) ||
        (p.headline_en && p.headline_en.toLowerCase().includes(q))
    );
});

onMounted(loadPros);
</script>

<template>
    <Head :title="t('admin.pros')" />
    <AdminLayout>
        <template #title>
            <div class="flex items-center justify-between w-full">
                <span>{{ t('admin.pros') }}</span>
                <input 
                    v-model="searchQuery" 
                    type="text" 
                    :placeholder="t('common.search')" 
                    class="border border-slate-300 rounded px-3 py-1.5 text-xs w-64 bg-white"
                />
            </div>
        </template>

        <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
            {{ t('common.loading') }}
        </div>

        <div v-else-if="filteredPros.length === 0" class="border border-dashed border-slate-300 rounded-lg p-8 text-center text-slate-400">
            لا يوجد فنيين مطابقين لشروط البحث
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="pro in filteredPros" :key="pro.id" class="border border-slate-200 bg-white rounded-lg shadow-sm overflow-hidden flex flex-col justify-between">
                <div>
                    <div class="h-40 w-full bg-slate-100 relative">
                        <img v-if="pro.image_url" :src="pro.image_url" :alt="pro.display_name" class="h-40 w-full object-cover" loading="lazy" />
                        <div v-else class="h-full flex items-center justify-center text-slate-400 text-xs">لا توجد صورة</div>
                        <span v-if="pro.kyc_status" class="absolute top-2 right-2 px-2 py-0.5 text-xs font-bold rounded bg-white/90 backdrop-blur shadow-sm text-slate-700">
                            {{ pro.kyc_status }}
                        </span>
                    </div>

                    <div class="p-4">
                        <h2 class="font-bold text-obsidian-900 text-base">{{ pro.display_name }}</h2>
                        <p class="mt-1 text-xs text-slate-500 min-h-[32px]">
                            {{ locale === 'ar' ? pro.headline_ar : pro.headline_en }}
                        </p>

                        <div class="mt-3 flex items-center justify-between text-xs border-t border-slate-100 pt-3">
                            <span class="font-bold text-amber-600">★ {{ Number(pro.rating_avg || 0).toFixed(1) }}</span>
                            <span class="text-slate-500">{{ pro.rating_count || 0 }} {{ locale === 'ar' ? 'تقييماً' : 'reviews' }}</span>
                            <span class="text-slate-500">{{ pro.completed_orders || 0 }} {{ locale === 'ar' ? 'طلب مكتمل' : 'completed' }}</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 border-t border-slate-100 flex justify-end">
                    <a :href="`/admin/kyc`" class="px-3 py-1 text-xs text-blue-600 bg-blue-50 rounded border border-blue-200 hover:bg-blue-100">
                        مراجعة التوثيق
                    </a>
                </div>
            </article>
        </div>
    </AdminLayout>
</template>