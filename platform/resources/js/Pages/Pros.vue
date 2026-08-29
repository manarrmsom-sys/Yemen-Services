<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Badge from '@/Components/Badge.vue';
import { useApi } from '@/composables/useApi';

const { t } = useI18n();
const api = useApi();
const pros = ref([]);
const loading = ref(true);

onMounted(async () => {
    try {
        const res = await api.get('/tradespeople');
        pros.value = res.data ?? res.data?.data ?? res;
    } finally { loading.value = false; }
});
</script>

<template>
    <Head :title="t('nav.pros')" />
    <PublicLayout>
        <div class="mx-auto max-w-5xl px-4 py-10">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('nav.pros') }}</h1>
            <p v-if="loading" class="mt-4 text-sm text-obsidian-900/50">{{ t('common.loading') }}</p>
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="p in (pros.data || pros)" :key="p.id" class="card p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-bold">{{ p.display_name }}</p>
                        <span class="text-sm">⭐ {{ Number(p.rating_avg).toFixed(1) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-obsidian-900/60">{{ p.headline }}</p>
                    <p class="mt-1 text-xs text-obsidian-900/40">{{ p.category }} · {{ p.governorate }}</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <Badge v-for="b in (p.badges || [])" :key="b" :type="b" />
                    </div>
                </article>
            </div>
        </div>
    </PublicLayout>
</template>
