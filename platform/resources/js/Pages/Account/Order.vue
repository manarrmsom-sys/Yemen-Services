<script setup>
import { reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PriceBreakdown from '@/Components/PriceBreakdown.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({ order: { type: Object, required: true } });
const { t, locale } = useI18n();
const { format } = useMoney();
const api = useApi();

const rating = reactive({ rating: 5, comment: '' });
const busy = ref(false);
const done = ref(!!props.order.review);

async function cancel() {
    busy.value = true;
    try { await api.post(`/orders/${props.order.reference}/cancel`, {}); window.location.reload(); }
    finally { busy.value = false; }
}
async function submitReview() {
    busy.value = true;
    try { await api.post(`/orders/${props.order.reference}/review`, rating); done.value = true; }
    finally { busy.value = false; }
}
</script>

<template>
    <Head :title="order.reference" />
    <PublicLayout>
        <div class="mx-auto max-w-2xl px-4 py-10">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-extrabold">{{ order.reference }}</h1>
                <span class="chip bg-crimson-50 text-crimson-700">{{ t(`order.status.${order.status}`) }}</span>
            </div>

            <p class="mt-2 text-sm text-obsidian-900/70">
                {{ locale === 'ar' ? order.service?.name_ar : order.service?.name_en }} —
                {{ locale === 'ar' ? order.governorate?.name_ar : order.governorate?.name_en }}
            </p>

            <div class="mt-5"><PriceBreakdown :quote="order.price_breakdown" /></div>

            <div v-if="order.tradesperson" class="card mt-4 p-4">
                <p class="text-sm font-bold">{{ order.tradesperson.display_name }}</p>
                <p class="text-sm text-obsidian-900/60">{{ order.tradesperson.user?.phone }}</p>
            </div>

            <button
                v-if="!['completed','closed','cancelled','expired'].includes(order.status)"
                class="btn-ghost mt-4 w-full" :disabled="busy" @click="cancel"
            >{{ t('order.cancel') }}</button>

            <div v-if="['completed','closed'].includes(order.status) && !done" class="card mt-4 p-4">
                <p class="font-bold">{{ t('order.rate') }}</p>
                <div class="mt-2 flex gap-1 text-2xl">
                    <button v-for="n in 5" :key="n" @click="rating.rating = n">{{ n <= rating.rating ? '★' : '☆' }}</button>
                </div>
                <textarea v-model="rating.comment" rows="2" class="field mt-2" />
                <button class="btn-primary mt-2 w-full" :disabled="busy" @click="submitReview">{{ t('common.save') }}</button>
            </div>
        </div>
    </PublicLayout>
</template>
