<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/composables/useApi';

/**
 * Expansion waitlist. Shown from the booking flow when the visitor's district
 * (or whole city) is not served yet. Posts to the throttled public endpoint
 * POST /api/v1/waitlist.
 */
const props = defineProps({
    governorateId: { type: Number, default: null },
    categoryId: { type: Number, default: null },
    phone: { type: String, default: '' },
});
const emit = defineEmits(['back']);

const { t } = useI18n();
const api = useApi();

const zones = ref([]);
const loading = ref(true);
const submitting = ref(false);
const done = ref(null); // { already: bool }
const errors = ref({});

const form = reactive({
    governorate_id: props.governorateId,
    district_id: '',
    district_name: '',
    phone: props.phone,
});

const governorate = computed(() => zones.value.find((g) => g.id === form.governorate_id));
// Only zones that are not live yet can be waited for.
const pending = computed(() => (governorate.value?.districts || []).filter((d) => d.status !== 'active'));
const typing = computed(() => form.district_id === 'other' || !pending.value.length);

watch(() => form.governorate_id, () => {
    form.district_id = '';
    form.district_name = '';
});

onMounted(async () => {
    try {
        zones.value = (await api.get('/zones')).data;
        if (!governorate.value) form.governorate_id = zones.value[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
});

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const { data } = await api.post('/waitlist', {
            governorate_id: form.governorate_id,
            district_id: typing.value ? null : form.district_id,
            district_name: typing.value ? form.district_name.trim() : null,
            service_category_id: props.categoryId,
            phone: form.phone.trim(),
        });
        done.value = data;
    } catch (e) {
        errors.value = e.response?.status === 429
            ? { _: [t('common.error')] }
            : e.response?.data?.errors || { _: [e.response?.data?.message || t('common.error')] };
    } finally {
        submitting.value = false;
    }
}

const firstError = computed(() => Object.values(errors.value).flat()[0]);
</script>

<template>
    <div class="space-y-4">
        <div v-if="done" class="space-y-4 py-4 text-center" aria-live="polite">
            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
            <p class="text-sm font-semibold text-obsidian-900">{{ done.already ? t('booking.waitlist_already') : t('booking.waitlist_done') }}</p>
            <button type="button" class="btn-ghost w-full" @click="emit('back')">{{ t('booking.waitlist_back') }}</button>
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div>
                <h3 class="text-lg font-extrabold text-obsidian-900">{{ t('booking.waitlist_title') }}</h3>
                <p class="mt-1 text-sm text-obsidian-900/60">{{ t('booking.waitlist_sub') }}</p>
            </div>

            <p v-if="loading" class="text-sm text-obsidian-900/50">{{ t('common.loading') }}</p>
            <template v-else>
                <label class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_city') }}</span>
                    <select v-model.number="form.governorate_id" class="field">
                        <option v-for="g in zones" :key="g.id" :value="g.id">{{ g.name }}<template v-if="g.status !== 'active'"> — {{ t('booking.coming_soon') }}</template></option>
                    </select>
                </label>

                <label v-if="pending.length" class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_district') }}</span>
                    <select v-model="form.district_id" class="field" required>
                        <option value="" disabled>—</option>
                        <option v-for="d in pending" :key="d.id" :value="d.id">{{ d.name }}</option>
                        <option value="other">{{ t('booking.waitlist_other') }}</option>
                    </select>
                </label>

                <label v-if="typing" class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_district_name') }}</span>
                    <input v-model="form.district_name" class="field" maxlength="80" :required="governorate?.status === 'active'" />
                </label>

                <label class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.phone') }}</span>
                    <input v-model="form.phone" dir="ltr" type="tel" inputmode="tel" autocomplete="tel" class="field" placeholder="+967 7XX XXX XXX" required />
                </label>

                <p v-if="firstError" class="text-xs font-semibold text-crimson" role="alert">{{ firstError }}</p>

                <div class="flex gap-2">
                    <button type="button" class="btn-ghost flex-1" @click="emit('back')">{{ t('booking.back') }}</button>
                    <button type="submit" class="btn-primary flex-[2]" :disabled="submitting">{{ t('booking.waitlist_submit') }}</button>
                </div>
            </template>
        </form>
    </div>
</template>
