<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/composables/useApi';

/**
 * Expansion waitlist / city vote.
 *
 * Any governorate or district that is not live yet can be requested: a whole
 * coming-soon city, a coming-soon district, or a district typed by name.
 * Used inside the booking drawer and, with `standalone`, on the public
 * /waitlist campaign page. Posts to the throttled POST /api/v1/waitlist.
 */
const props = defineProps({
    governorateId: { type: Number, default: null },
    /** Preselect a governorate by code, e.g. from /waitlist?city=aden. */
    cityCode: { type: String, default: null },
    categoryId: { type: Number, default: null },
    phone: { type: String, default: '' },
    /** Zone tree, when the page already has it; otherwise fetched. */
    zones: { type: Array, default: null },
    standalone: { type: Boolean, default: false },
});
const emit = defineEmits(['back', 'joined']);

const { t } = useI18n();
const api = useApi();

const tree = ref(props.zones || []);
const loading = ref(!props.zones);
const submitting = ref(false);
const done = ref(null); // { already: bool }
const errors = ref({});
const copied = ref(false);

const form = reactive({ governorate_id: null, district_id: '', district_name: '', phone: props.phone });

const governorate = computed(() => tree.value.find((g) => g.id === form.governorate_id));
const cityLive = computed(() => governorate.value?.status === 'active');
// Only zones that are not live yet can be voted for.
const pending = computed(() => (governorate.value?.districts || []).filter((d) => d.status !== 'active'));
const typing = computed(() => form.district_id === 'other' || !pending.value.length);

function preselect() {
    const byCode = props.cityCode && tree.value.find((g) => g.code === props.cityCode);
    const byId = tree.value.find((g) => g.id === props.governorateId);
    // On the campaign page default to the first city that is not live yet.
    const fallback = props.standalone ? tree.value.find((g) => g.status !== 'active') : null;
    form.governorate_id = (byCode || byId || fallback || tree.value[0])?.id ?? null;
}

watch(() => form.governorate_id, () => {
    form.district_id = '';
    form.district_name = '';
});

onMounted(async () => {
    if (!props.zones) {
        try {
            tree.value = (await api.get('/zones')).data;
        } finally {
            loading.value = false;
        }
    }
    preselect();
});

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const whole = form.district_id === 'whole';
        const { data } = await api.post('/waitlist', {
            governorate_id: form.governorate_id,
            district_id: typing.value || whole ? null : form.district_id,
            district_name: typing.value && !whole ? form.district_name.trim() || null : null,
            service_category_id: props.categoryId,
            phone: form.phone.trim(),
        });
        done.value = data;
        emit('joined', { governorate_id: form.governorate_id, already: data.already });
    } catch (e) {
        errors.value = e.response?.status === 429
            ? { _: [t('common.error')] }
            : e.response?.data?.errors || { _: [e.response?.data?.message || t('common.error')] };
    } finally {
        submitting.value = false;
    }
}

const firstError = computed(() => Object.values(errors.value).flat()[0]);

const shareUrl = computed(() => `${window.location.origin}/waitlist${governorate.value ? `?city=${governorate.value.code}` : ''}`);
async function share() {
    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch (e) { /* clipboard unavailable: the link is shown as text below */ }
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="done" class="space-y-4 py-4 text-center" aria-live="polite">
            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
            <p class="text-sm font-semibold text-ink">{{ done.already ? t('booking.waitlist_already') : (standalone ? t('booking.vote_done') : t('booking.waitlist_done')) }}</p>
            <button type="button" class="btn-primary w-full" @click="share">{{ copied ? t('booking.vote_copied') : t('booking.vote_share') }}</button>
            <p class="break-all text-xs text-ink-400" dir="ltr">{{ shareUrl }}</p>
            <button v-if="!standalone" type="button" class="btn-ghost w-full" @click="emit('back')">{{ t('booking.waitlist_back') }}</button>
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div v-if="!standalone">
                <h3 class="text-lg font-extrabold text-ink">{{ t('booking.waitlist_title') }}</h3>
                <p class="mt-1 text-sm text-ink-500">{{ t('booking.waitlist_sub') }}</p>
            </div>

            <p v-if="loading" class="text-sm text-ink-500">{{ t('common.loading') }}</p>
            <template v-else>
                <label class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_city') }}</span>
                    <select v-model.number="form.governorate_id" class="field">
                        <option v-for="g in tree" :key="g.id" :value="g.id">{{ g.name }} — {{ g.status === 'active' ? t('booking.vote_live') : t('booking.coming_soon') }}</option>
                    </select>
                </label>

                <label v-if="pending.length" class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_district') }}</span>
                    <select v-model="form.district_id" class="field" required>
                        <option value="" disabled>—</option>
                        <option v-if="!cityLive" value="whole">{{ t('booking.vote_whole_city') }}</option>
                        <option v-for="d in pending" :key="d.id" :value="d.id">{{ d.name }}</option>
                        <option value="other">{{ t('booking.waitlist_other') }}</option>
                    </select>
                </label>

                <!-- In a live city the district is what is being asked for, so it is required;
                     in a coming-soon city it is optional detail. -->
                <label v-if="typing" class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.waitlist_district_name') }}<template v-if="!cityLive"> ({{ t('booking.vote_whole_city') }})</template></span>
                    <input v-model="form.district_name" class="field" maxlength="80" :required="cityLive" />
                </label>

                <label class="block">
                    <span class="block text-sm font-semibold">{{ t('booking.phone') }}</span>
                    <input v-model="form.phone" dir="ltr" type="tel" inputmode="tel" autocomplete="tel" class="field" placeholder="+967 7XX XXX XXX" required />
                </label>

                <p v-if="firstError" class="text-xs font-semibold text-crimson" role="alert">{{ firstError }}</p>

                <div class="flex gap-2">
                    <button v-if="!standalone" type="button" class="btn-ghost flex-1" @click="emit('back')">{{ t('booking.back') }}</button>
                    <button type="submit" class="btn-primary flex-[2]" :disabled="submitting">{{ standalone ? t('booking.vote_submit') : t('booking.waitlist_submit') }}</button>
                </div>
            </template>
        </form>
    </div>
</template>
