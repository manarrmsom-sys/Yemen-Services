<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Badge from '@/Components/Badge.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    reference: { type: String, default: null },
    order: { type: Object, default: null },
});
const { t, locale } = useI18n();
const { format } = useMoney();
const ref_ = ref(props.reference || '');

function lookup() {
    if (ref_.value) router.get(`/track/${ref_.value.trim().toUpperCase()}`);
}
</script>

<template>
    <Head :title="t('order.track_title')" />
    <PublicLayout>
        <div class="mx-auto max-w-2xl px-4 py-10">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('order.track_title') }}</h1>

            <div class="mt-4 flex gap-2">
                <input v-model="ref_" dir="ltr" class="field" placeholder="YS-XXXXXX" @keyup.enter="lookup" />
                <button class="btn-primary" @click="lookup">{{ t('common.search') }}</button>
            </div>

            <div v-if="order" class="card mt-6 p-5">
                <div class="flex items-center justify-between">
                    <p class="font-bold">{{ order.reference }}</p>
                    <span class="chip bg-crimson-50 text-crimson-700">{{ t(`order.status.${order.status}`) }}</span>
                </div>
                <p class="mt-2 text-sm text-obsidian-900/70">
                    {{ locale === 'ar' ? order.service?.name_ar : order.service?.name_en }} —
                    {{ locale === 'ar' ? order.governorate?.name_ar : order.governorate?.name_en }}
                </p>
                <p class="mt-1 text-sm">{{ t('estimate.total') }}: <strong>{{ format(order.total_estimate) }}</strong></p>

                <div v-if="order.tradesperson" class="mt-4 rounded-xl bg-offwhite p-3">
                    <p class="text-sm font-bold">{{ order.tradesperson.display_name }}</p>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        <Badge v-for="b in (order.tradesperson.badges || [])" :key="b" :type="b" />
                    </div>
                </div>

                <ol class="mt-5 space-y-3 border-s border-obsidian-100 ps-4">
                    <li v-for="e in order.events" :key="e.id" class="text-sm">
                        <span class="font-semibold">{{ e.to_status ? t(`order.status.${e.to_status}`) : e.type }}</span>
                        <span class="text-obsidian-900/50"> · {{ new Date(e.created_at).toLocaleString() }}</span>
                        <p v-if="e.message" class="text-obsidian-900/60">{{ e.message }}</p>
                    </li>
                </ol>
            </div>

            <p v-else-if="reference" class="mt-6 text-sm text-obsidian-900/60">{{ t('common.error') }}</p>
        </div>
    </PublicLayout>
</template>
