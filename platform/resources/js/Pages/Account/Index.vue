<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useMoney } from '@/composables/useMoney';

defineProps({ orders: { type: Object, default: () => ({ data: [] }) } });
const { t, locale } = useI18n();
const { format } = useMoney();
</script>

<template>
    <Head :title="t('nav.account')" />
    <PublicLayout>
        <div class="mx-auto max-w-3xl px-4 py-10">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ t('nav.account') }}</h1>
            <div class="mt-6 space-y-3">
                <Link
                    v-for="o in orders.data" :key="o.id" :href="route('account.order', o.reference)"
                    class="card flex items-center justify-between p-4"
                >
                    <div>
                        <p class="font-bold">{{ o.reference }}</p>
                        <p class="text-sm text-obsidian-900/60">
                            {{ locale === 'ar' ? o.service?.name_ar : o.service?.name_en }} ·
                            {{ locale === 'ar' ? o.governorate?.name_ar : o.governorate?.name_en }}
                        </p>
                    </div>
                    <div class="text-end">
                        <span class="chip bg-crimson-50 text-crimson-700">{{ t(`order.status.${o.status}`) }}</span>
                        <p class="mt-1 text-sm font-bold">{{ format(o.total_estimate) }}</p>
                    </div>
                </Link>
                <p v-if="!orders.data?.length" class="text-sm text-obsidian-900/50">—</p>
            </div>
        </div>
    </PublicLayout>
</template>
