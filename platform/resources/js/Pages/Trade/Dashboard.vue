<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Badge from '@/Components/Badge.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({ profile: { type: Object, required: true } });
const { t, locale } = useI18n();
const { format } = useMoney();
const api = useApi();

const wallet = ref(null);
const jobs = ref([]);

onMounted(async () => {
    try { wallet.value = (await api.get('/trade/wallet')).data; } catch (e) { /* not onboarded yet */ }
    if (props.profile.kyc_status === 'verified') {
        try { jobs.value = (await api.get('/trade/jobs')).data ?? []; } catch (e) {}
    }
});

async function accept(ref_) {
    await api.post(`/trade/orders/${ref_}/accept`, {});
    jobs.value = jobs.value.filter((j) => j.reference !== ref_);
}
</script>

<template>
    <Head title="Trade" />
    <PublicLayout>
        <div class="mx-auto max-w-3xl px-4 py-10">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-extrabold">{{ profile.display_name }}</h1>
                <span class="chip bg-obsidian-100">{{ profile.kyc_status }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <Badge v-for="b in (profile.badges || [])" :key="b" :type="b" />
            </div>

            <div v-if="wallet" class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="card p-3"><p class="text-xs text-obsidian-900/50">رصيد متاح</p><p class="font-bold">{{ format(wallet.available_balance) }}</p></div>
                <div class="card p-3"><p class="text-xs text-obsidian-900/50">عمولة مستحقة</p><p class="font-bold text-crimson">{{ format(wallet.commission_due) }}</p></div>
                <div class="card p-3"><p class="text-xs text-obsidian-900/50">محتجز</p><p class="font-bold">{{ format(wallet.pending_balance) }}</p></div>
                <div class="card p-3"><p class="text-xs text-obsidian-900/50">إجمالي الأرباح</p><p class="font-bold">{{ format(wallet.lifetime_earnings) }}</p></div>
            </div>

            <h2 class="mt-8 text-lg font-bold">{{ locale === 'ar' ? 'طلبات متاحة' : 'Available jobs' }}</h2>
            <div class="mt-3 space-y-3">
                <div v-for="j in jobs" :key="j.id" class="card flex items-center justify-between p-4">
                    <div>
                        <p class="font-bold">{{ j.reference }}</p>
                        <p class="text-sm text-obsidian-900/60">
                            {{ locale === 'ar' ? j.service?.name_ar : j.service?.name_en }} ·
                            {{ locale === 'ar' ? j.governorate?.name_ar : j.governorate?.name_en }}
                        </p>
                    </div>
                    <button class="btn-primary !py-2 !px-4 text-xs" @click="accept(j.reference)">
                        {{ locale === 'ar' ? 'قبول' : 'Accept' }}
                    </button>
                </div>
                <p v-if="!jobs.length" class="text-sm text-obsidian-900/50">—</p>
            </div>
        </div>
    </PublicLayout>
</template>
