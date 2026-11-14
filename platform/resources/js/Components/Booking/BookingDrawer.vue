<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { watchDebounced } from '@vueuse/core';
import { useApi } from '@/composables/useApi';
import PriceBreakdown from '@/Components/PriceBreakdown.vue';
import WhatsAppButton from '@/Components/WhatsAppButton.vue';
import ZoneWaitlist from '@/Components/Booking/ZoneWaitlist.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    governorates: { type: Array, default: () => [] },
    preselectServiceSlug: { type: String, default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const api = useApi();
const page = usePage();

const catalog = computed(() => page.props.catalog || []);
const step = ref(1);
const submitting = ref(false);
const estimateLoading = ref(false);
const quote = ref(null);
const result = ref(null);
const errors = reactive({});

const form = reactive({
    category_id: null,
    service_id: null,
    quantity: 1,
    hours: 2,
    governorate_id: props.governorates[0]?.id ?? null,
    district_id: null,
    district: '',
    directions: '',
    urgency: 'today',
    contact_name: page.props.auth?.user?.name ?? '',
    contact_phone: page.props.auth?.user?.phone ?? '',
    payment_method: 'cod',
    notes: '',
});

const selectedCategory = computed(() => catalog.value.find((c) => c.id === form.category_id));
const services = computed(() => selectedCategory.value?.services ?? []);
const selectedService = computed(() => services.value.find((s) => s.id === form.service_id));
const needsQuantity = computed(() => selectedService.value?.pricing_model === 'per_unit');
const needsHours = computed(() => selectedService.value?.pricing_model === 'hourly');
// Digital categories are delivered online: no district or directions needed.
const isRemote = computed(() => selectedCategory.value?.is_remote === true);

// Launch zones: a governorate that lists districts is only served in its
// active ones. Anyone elsewhere is offered the expansion waitlist instead.
const governorate = computed(() => props.governorates.find((g) => g.id === form.governorate_id));
const activeDistricts = computed(() => (governorate.value?.districts || []).filter((d) => d.status === 'active'));
const needsDistrict = computed(() => !isRemote.value && (governorate.value?.districts || []).length > 0);
const waitlistOpen = ref(false);
watch(() => form.governorate_id, () => { form.district_id = null; });

// Preselect a service passed from the route.
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        step.value = 1;
        result.value = null;
        waitlistOpen.value = false;
        if (props.preselectServiceSlug) {
            for (const c of catalog.value) {
                const s = c.services.find((x) => x.slug === props.preselectServiceSlug);
                if (s) {
                    form.category_id = c.id;
                    form.service_id = s.id;
                }
            }
        }
    },
);

// Live estimate whenever the pricing inputs change.
watchDebounced(
    () => [form.service_id, form.governorate_id, form.urgency, form.quantity, form.hours],
    async () => {
        if (!form.service_id || !form.governorate_id) return;
        estimateLoading.value = true;
        try {
            const { data } = await api.post('/estimate', {
                service_id: form.service_id,
                governorate_id: form.governorate_id,
                urgency: form.urgency,
                quantity: form.quantity,
                hours: form.hours,
            });
            quote.value = data;
        } catch (e) {
            quote.value = null;
        } finally {
            estimateLoading.value = false;
        }
    },
    { debounce: 350, immediate: false },
);

const canNext = computed(() => {
    if (step.value === 1) return !!form.service_id;
    if (step.value === 2) return !!form.governorate_id && !!form.urgency && (!needsDistrict.value || !!form.district_id);
    return true;
});

function next() {
    if (step.value < 3 && canNext.value) step.value += 1;
}
function back() {
    if (step.value > 1) step.value -= 1;
}

async function submit() {
    submitting.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);

    const payload = {
        service_id: Number(form.service_id),
        governorate_id: Number(form.governorate_id),
        quantity: Number(form.quantity),
        hours: Number(form.hours),
        district_id: needsDistrict.value ? form.district_id : null,
        district: needsDistrict.value ? '' : form.district.trim(),
        directions: form.directions.trim(),
        urgency: form.urgency,
        contact_name: form.contact_name.trim(),
        contact_phone: form.contact_phone.trim(),
        payment_method: form.payment_method,
        notes: form.notes.trim(),
        channel: 'pwa',
    };

    if (!payload.contact_name) errors.contact_name = [t('booking.name_required')];
    if (!payload.contact_phone) errors.contact_phone = [t('booking.phone_required')];
    if (Object.keys(errors).length) {
        submitting.value = false;
        return;
    }

    try {
        const { data } = await api.post('/bookings', payload);
        result.value = data;
    } catch (e) {
        if (e.response?.status === 422) {
            Object.assign(errors, e.response.data.errors || {});
            // A zone problem is fixed on the location step, so take them back there.
            if (errors.district_id || errors.governorate_id) step.value = 2;
        } else {
            errors._ = e.response?.data?.message || t('common.error');
        }
    } finally {
        submitting.value = false;
    }
}

function close() {
    emit('close');
}
</script>

<template>
    <teleport to="body">
        <transition
            enter-active-class="transition duration-200"
            leave-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="open" data-lenis-prevent class="fixed inset-0 z-50 flex items-end justify-center bg-obsidian-900/50 backdrop-blur-[2px] sm:items-center" @click.self="close">
                <div class="w-full max-w-lg animate-sheet-up rounded-t-3xl bg-white pb-safe shadow-sheet sm:rounded-3xl">
                    <!-- header -->
                    <div class="flex items-center justify-between border-b border-obsidian-100 px-5 py-4">
                        <h2 class="text-base font-extrabold text-obsidian-900">{{ t('booking.title') }}</h2>
                        <button class="chip bg-obsidian-100" @click="close">✕</button>
                    </div>

                    <!-- stepper -->
                    <div v-if="!result && !waitlistOpen" class="flex gap-2 px-5 pt-4">
                        <div
                            v-for="(labelKey, i) in ['booking.step_service', 'booking.step_where', 'booking.step_contact']"
                            :key="i"
                            class="h-1.5 flex-1 rounded-full"
                            :class="step >= i + 1 ? 'bg-crimson' : 'bg-obsidian-100'"
                        />
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                        <!-- WAITLIST — zone not served yet -->
                        <ZoneWaitlist
                            v-if="waitlistOpen"
                            :governorate-id="form.governorate_id"
                            :category-id="form.category_id"
                            :phone="form.contact_phone"
                            @back="waitlistOpen = false"
                        />

                        <!-- SUCCESS -->
                        <div v-else-if="result" class="space-y-4 text-center">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
                            <h3 class="text-lg font-extrabold">{{ t('booking.success_title') }}</h3>
                            <p class="text-sm text-obsidian-900/60">{{ t('booking.success_sub') }}</p>
                            <p class="chip mx-auto bg-obsidian-100">{{ t('order.reference') }}: {{ result.order.reference }}</p>
                            <p v-if="result.dispatch?.error" class="border border-crimson-200 bg-crimson-50 px-3 py-2 text-xs leading-6 text-crimson-700">{{ result.dispatch.error }}</p>
                            <PriceBreakdown :quote="result.price_breakdown" />
                            <WhatsAppButton v-if="result.dispatch?.wa_deeplink" :href="result.dispatch.wa_deeplink" />
                            <a :href="`/track/${result.order.reference}`" class="btn-ghost w-full">{{ t('order.track_title') }}</a>
                        </div>

                        <!-- STEP 1 — service -->
                        <div v-else-if="step === 1" class="space-y-4">
                            <label class="block text-sm font-semibold">{{ t('booking.choose_category') }}</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="c in catalog"
                                    :key="c.id"
                                    class="rounded-xl border px-3 py-3 text-start text-sm font-semibold transition"
                                    :class="form.category_id === c.id ? 'border-crimson bg-crimson-50 text-crimson-700' : 'border-obsidian-100'"
                                    @click="form.category_id = c.id; form.service_id = null"
                                >
                                    {{ c.name }}
                                </button>
                            </div>

                            <template v-if="selectedCategory">
                                <label class="block text-sm font-semibold">{{ t('booking.choose_service') }}</label>
                                <select v-model="form.service_id" class="field">
                                    <option :value="null" disabled>—</option>
                                    <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </template>

                            <div v-if="needsQuantity">
                                <label class="block text-sm font-semibold">{{ t('booking.quantity') }} ({{ selectedService.unit_label }})</label>
                                <input v-model.number="form.quantity" type="number" min="1" class="field" />
                            </div>
                            <div v-if="needsHours">
                                <label class="block text-sm font-semibold">{{ t('booking.hours') }}</label>
                                <input v-model.number="form.hours" type="number" min="1" step="0.5" class="field" />
                            </div>
                        </div>

                        <!-- STEP 2 — where & when -->
                        <div v-else-if="step === 2" class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.governorate') }}</label>
                                <select v-model.number="form.governorate_id" class="field">
                                    <option v-for="g in governorates" :key="g.id" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <button v-if="!isRemote" type="button" class="text-sm font-semibold text-crimson underline-offset-4 hover:underline" @click="waitlistOpen = true">{{ t('booking.zone_missing') }}</button>
                            <p v-if="isRemote" class="rounded-xl bg-obsidian-100 px-3 py-2 text-xs font-semibold text-obsidian-900/70">{{ t('booking.remote_note') }}</p>
                            <template v-else>
                                <div v-if="needsDistrict">
                                    <label class="block text-sm font-semibold" for="booking-district">{{ t('booking.choose_district') }}</label>
                                    <select id="booking-district" v-model="form.district_id" class="field">
                                        <option :value="null" disabled>—</option>
                                        <option v-for="d in activeDistricts" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </select>
                                    <p v-if="errors.district_id" class="mt-1 text-xs text-crimson">{{ errors.district_id[0] }}</p>
                                </div>
                                <div v-else>
                                    <label class="block text-sm font-semibold">{{ t('booking.district') }}</label>
                                    <input v-model="form.district" class="field" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold">{{ t('booking.directions') }}</label>
                                    <textarea v-model="form.directions" rows="2" class="field" />
                                </div>
                            </template>
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.urgency') }}</label>
                                <div class="mt-1 grid grid-cols-3 gap-2">
                                    <button
                                        v-for="u in ['flexible', 'today', 'urgent']"
                                        :key="u"
                                        class="rounded-xl border px-2 py-2 text-xs font-semibold"
                                        :class="form.urgency === u ? 'border-crimson bg-crimson-50 text-crimson-700' : 'border-obsidian-100'"
                                        @click="form.urgency = u"
                                    >
                                        {{ t(`booking.urgency_${u}`) }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 3 — contact -->
                        <div v-else class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.name') }} <span class="text-crimson">*</span></label>
                                <input v-model="form.contact_name" class="field" />
                                <p v-if="errors.contact_name" class="mt-1 text-xs text-crimson">{{ errors.contact_name[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.phone') }}</label>
                                <input v-model="form.contact_phone" inputmode="tel" dir="ltr" class="field" placeholder="+9677XXXXXXXX" />
                                <p v-if="errors.contact_phone" class="mt-1 text-xs text-crimson">{{ errors.contact_phone[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.payment') }}</label>
                                <div class="mt-1 grid grid-cols-3 gap-2">
                                    <button
                                        v-for="p in [['cod', 'payment_cod'], ['local_wallet', 'payment_wallet'], ['bank_transfer', 'payment_bank']]"
                                        :key="p[0]"
                                        class="rounded-xl border px-2 py-2 text-xs font-semibold"
                                        :class="form.payment_method === p[0] ? 'border-crimson bg-crimson-50 text-crimson-700' : 'border-obsidian-100'"
                                        @click="form.payment_method = p[0]"
                                    >
                                        {{ t(`booking.${p[1]}`) }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold">{{ t('booking.notes') }}</label>
                                <textarea v-model="form.notes" rows="2" class="field" />
                            </div>
                            <p v-if="errors._" class="text-xs text-crimson">{{ errors._ }}</p>
                            <div v-if="Object.keys(errors).some((key) => key !== '_')" class="space-y-1 text-xs text-crimson">
                                <p v-for="(messages, field) in errors" :key="field">
                                    <span v-if="field !== '_'">{{ messages[0] }}</span>
                                </p>
                            </div>
                        </div>

                        <!-- live estimate on steps 2 & 3 -->
                        <div v-if="!result && !waitlistOpen && step >= 2" class="mt-4">
                            <PriceBreakdown :quote="quote" :loading="estimateLoading" />
                        </div>
                    </div>

                    <!-- footer -->
                    <div v-if="!result && !waitlistOpen" class="flex gap-2 border-t border-obsidian-100 px-5 py-4">
                        <button v-if="step > 1" class="btn-ghost flex-1" @click="back">{{ t('booking.back') }}</button>
                        <button v-if="step < 3" class="btn-primary flex-[2]" :disabled="!canNext" @click="next">{{ t('booking.next') }}</button>
                        <button v-else class="btn-primary flex-[2]" :disabled="submitting" @click="submit">
                            {{ submitting ? t('booking.sending') : t('booking.confirm') }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
