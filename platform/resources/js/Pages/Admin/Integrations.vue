<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useApi } from '@/composables/useApi';

const { t } = useI18n();
const api = useApi();

const endpoints = ref([]);
const events = ref([]);
const inbound = ref({ data: [] });
const secretShown = ref(null);
const form = reactive({ name: '', url: '', events: ['*'] });

async function load() {
    const res = await api.get('/admin/webhooks');
    endpoints.value = res.data;
    events.value = res.available_events;
    inbound.value = await api.get('/admin/inbound-events');
}
async function create() {
    const res = await api.post('/admin/webhooks', form);
    secretShown.value = res.data.secret;
    form.name = ''; form.url = '';
    await load();
}
async function test(id) { await api.post(`/admin/webhooks/${id}/test`); await load(); }
async function remove(id) { await api.delete(`/admin/webhooks/${id}`); await load(); }
onMounted(load);
</script>

<template>
    <Head :title="t('admin.integrations')" />
    <AdminLayout>
        <template #title>{{ t('admin.integrations') }}</template>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card p-4">
                <p class="font-bold">New webhook endpoint</p>
                <input v-model="form.name" class="field mt-2" placeholder="Name (e.g. n8n order pipeline)" />
                <input v-model="form.url" dir="ltr" class="field mt-2" placeholder="https://n8n.example/webhook/..." />
                <select v-model="form.events" multiple class="field mt-2 h-32">
                    <option value="*">* (all events)</option>
                    <option v-for="e in events" :key="e" :value="e">{{ e }}</option>
                </select>
                <button class="btn-primary mt-3 w-full" @click="create">Create</button>
                <p v-if="secretShown" class="mt-2 break-all rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                    Signing secret (shown once): <strong>{{ secretShown }}</strong>
                </p>
            </div>

            <div class="card divide-y divide-obsidian-100">
                <div v-for="e in endpoints" :key="e.id" class="p-4 text-sm">
                    <div class="flex items-center justify-between">
                        <p class="font-bold">{{ e.name }}</p>
                        <span class="chip" :class="e.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-obsidian-100'">
                            {{ e.is_active ? 'active' : 'disabled' }}
                        </span>
                    </div>
                    <p dir="ltr" class="truncate text-xs text-obsidian-900/50">{{ e.url }}</p>
                    <p class="text-xs text-obsidian-900/40">{{ (e.events || []).join(', ') }} · failures: {{ e.failed_deliveries }}</p>
                    <div class="mt-2 flex gap-1">
                        <button class="chip bg-obsidian-100" @click="test(e.id)">Send test</button>
                        <button class="chip bg-crimson-50 text-crimson-700" @click="remove(e.id)">Delete</button>
                    </div>
                </div>
                <p v-if="!endpoints.length" class="p-4 text-sm text-obsidian-900/40">—</p>
            </div>
        </div>

        <h2 class="mt-8 text-lg font-bold">Inbound events</h2>
        <div class="card mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-offwhite text-xs text-obsidian-900/50"><tr>
                    <th class="p-3 text-start">Source</th><th class="p-3 text-start">Handler</th>
                    <th class="p-3 text-start">Sig</th><th class="p-3 text-start">Status</th><th class="p-3 text-start">When</th>
                </tr></thead>
                <tbody>
                    <tr v-for="ev in inbound.data" :key="ev.id" class="border-t border-obsidian-100">
                        <td class="p-3">{{ ev.source }}</td>
                        <td class="p-3">{{ ev.handler || '—' }}</td>
                        <td class="p-3">{{ ev.signature_status }}</td>
                        <td class="p-3">{{ ev.status }}</td>
                        <td class="p-3">{{ new Date(ev.created_at).toLocaleString() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
