<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/composables/useApi';

/**
 * Quote request for a recurring maintenance contract (hotels, restaurants,
 * clinics, compounds...). Posts to the throttled public endpoint
 * POST /api/v1/b2b-requests. Businesses anywhere can ask, so the city list is
 * the full zone tree, not only the districts live for household bookings.
 */
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const { t } = useI18n();
const api = useApi();

const types = ['hotel', 'restaurant', 'clinic', 'residential_compound', 'office', 'retail', 'school', 'other'];
const zones = ref([]);
const submitting = ref(false);
const done = ref(null);
const errors = ref({});

const form = reactive({
    business_name: '', business_type: '', governorate_id: null, district_id: '', district_name: '',
    facility_size: '', contact_name: '', phone: '', notes: '',
});

const districts = computed(() => zones.value.find((g) => g.id === form.governorate_id)?.districts || []);
const typing = computed(() => form.district_id === 'other' || !districts.value.length);
watch(() => form.governorate_id, () => { form.district_id = ''; form.district_name = ''; });

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;
    done.value = null;
    errors.value = {};
    if (!zones.value.length) {
        try {
            zones.value = (await api.get('/zones')).data;
            form.governorate_id = zones.value[0]?.id ?? null;
        } catch (e) { /* the form still works with a typed district */ }
    }
});

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const { data } = await api.post('/b2b-requests', {
            ...form,
            district_id: typing.value ? null : form.district_id,
            district_name: typing.value ? form.district_name : null,
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

const err = (key) => errors.value[key]?.[0];
</script>

<template>
    <teleport to="body">
        <transition enter-active-class="transition duration-200" leave-active-class="transition duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" data-lenis-prevent class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 backdrop-blur-[2px] sm:items-center" @click.self="emit('close')" @keydown.esc="emit('close')">
                <div class="w-full max-w-lg animate-sheet-up rounded-t-3xl bg-white pb-safe shadow-sheet sm:rounded-3xl" role="dialog" aria-modal="true" aria-labelledby="b2b-title">
                    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4">
                        <h2 id="b2b-title" class="text-base font-extrabold text-ink">{{ t('b2b.form_title') }}</h2>
                        <button type="button" class="chip bg-ink-100" :aria-label="t('common.close')" @click="emit('close')">✕</button>
                    </div>

                    <div class="max-h-[75vh] overflow-y-auto px-5 py-4">
                        <div v-if="done" class="space-y-3 py-6 text-center" aria-live="polite">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
                            <h3 class="text-lg font-extrabold text-ink">{{ t('b2b.done_title') }}</h3>
                            <p class="text-sm text-ink-600">{{ t('b2b.done_sub') }}</p>
                            <p class="chip mx-auto bg-ink-100">{{ t('b2b.reference') }}: {{ done.reference }}</p>
                            <button type="button" class="btn-ghost w-full" @click="emit('close')">{{ t('common.close') }}</button>
                        </div>

                        <form v-else class="space-y-4" @submit.prevent="submit">
                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ t('b2b.business_name') }} <span class="text-crimson">*</span></span>
                                <input v-model="form.business_name" class="field" maxlength="160" autocomplete="organization" required />
                                <span v-if="err('business_name')" class="mt-1 block text-xs text-crimson">{{ err('business_name') }}</span>
                            </label>

                            <fieldset>
                                <legend class="mb-1 block text-sm font-semibold">{{ t('b2b.business_type') }} <span class="text-crimson">*</span></legend>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <label v-for="type in types" :key="type" class="cursor-pointer rounded-xl border px-3 py-2 text-sm font-semibold transition" :class="form.business_type === type ? 'border-crimson bg-crimson-50 text-crimson-700' : 'border-ink-200 text-ink-600'">
                                        <input v-model="form.business_type" type="radio" name="b2b-type" :value="type" class="sr-only" required />
                                        {{ t(`b2b.types.${type}`) }}
                                    </label>
                                </div>
                            </fieldset>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <label v-if="zones.length" class="block">
                                    <span class="mb-1 block text-sm font-semibold">{{ t('b2b.city') }}</span>
                                    <select v-model.number="form.governorate_id" class="field">
                                        <option v-for="g in zones" :key="g.id" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </label>
                                <label v-if="districts.length" class="block">
                                    <span class="mb-1 block text-sm font-semibold">{{ t('b2b.district') }} <span class="text-crimson">*</span></span>
                                    <select v-model="form.district_id" class="field" required>
                                        <option value="" disabled>—</option>
                                        <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        <option value="other">{{ t('b2b.district_other') }}</option>
                                    </select>
                                </label>
                                <label v-if="typing" class="block" :class="districts.length ? 'sm:col-span-2' : ''">
                                    <span class="mb-1 block text-sm font-semibold">{{ t('b2b.district_name') }} <span class="text-crimson">*</span></span>
                                    <input v-model="form.district_name" class="field" maxlength="80" required />
                                    <span v-if="err('district_name')" class="mt-1 block text-xs text-crimson">{{ err('district_name') }}</span>
                                </label>
                            </div>

                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ t('b2b.facility_size') }} <span class="text-crimson">*</span></span>
                                <input v-model="form.facility_size" class="field" maxlength="255" :placeholder="t('b2b.facility_hint')" required />
                            </label>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-sm font-semibold">{{ t('b2b.phone') }} <span class="text-crimson">*</span></span>
                                    <input v-model="form.phone" dir="ltr" type="tel" inputmode="tel" autocomplete="tel" class="field" placeholder="+967 7XX XXX XXX" required />
                                    <span v-if="err('phone')" class="mt-1 block text-xs text-crimson">{{ err('phone') }}</span>
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-semibold">{{ t('b2b.contact_name') }}</span>
                                    <input v-model="form.contact_name" class="field" maxlength="120" autocomplete="name" />
                                </label>
                            </div>

                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ t('b2b.notes') }}</span>
                                <textarea v-model="form.notes" rows="2" class="field" maxlength="1000" />
                            </label>

                            <p v-if="err('_') || err('business_type') || err('district_id')" class="text-xs font-semibold text-crimson" role="alert">{{ err('_') || err('business_type') || err('district_id') }}</p>
                            <button type="submit" class="btn-primary w-full !py-3.5 text-base" :disabled="submitting">{{ submitting ? t('booking.sending') : t('b2b.submit') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
