<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

const { t } = useI18n();
const api = useApi();
const board = ref({});
let timer;

const columns = ['queued', 'sent', 'delivered', 'accepted'];

async function load() { board.value = (await api.get('/admin/dispatch/board')).data || {}; }
onMounted(() => { load(); timer = setInterval(load, 10000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head :title="t('admin.dispatch')" />
    <AdminLayout>
        <template #title>{{ t('admin.dispatch') }}</template>

        <div class="grid gap-3 md:grid-cols-4">
            <div v-for="col in columns" :key="col" class="rounded-2xl bg-white p-3 ring-1 ring-obsidian-900/5">
                <p class="mb-2 text-xs font-bold uppercase text-obsidian-900/50">{{ col }} ({{ (board[col] || []).length }})</p>
                <div class="space-y-2">
                    <div v-for="j in (board[col] || [])" :key="j.id" class="rounded-xl border border-obsidian-100 p-3 text-sm">
                        <p class="font-semibold">{{ j.order?.reference }}</p>
                        <p class="text-xs text-obsidian-900/60">{{ j.order?.service?.name_en }} · {{ j.order?.governorate?.code }}</p>
                        <p class="text-xs text-obsidian-900/40">{{ j.geo_tags?.geo_tag }}</p>
                        <a v-if="j.wa_deeplink" :href="j.wa_deeplink" target="_blank" class="mt-1 inline-block text-xs text-whatsapp">WhatsApp →</a>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
