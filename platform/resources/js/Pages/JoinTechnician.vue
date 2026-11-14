<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useApi } from '@/composables/useApi';
import { useMoney } from '@/composables/useMoney';

/**
 * /join-technician — explains how jobs, the prepaid wallet and COD commission
 * work, then lets a signed-in tradesperson register and upload KYC documents.
 * Files go straight to the private `kyc` disk through the API and appear in
 * Admin → KYC for review.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    governorates: { type: Array, default: () => [] },
    terms: { type: Object, default: () => ({}) },
    application: { type: Object, default: null },
});

const { t, locale } = useI18n();
const { format } = useMoney();
const api = useApi();
const page = usePage();
const ar = computed(() => locale.value === 'ar');
const L = (a, e) => (ar.value ? a : e);
const user = computed(() => page.props.auth?.user);

// Worked example so the commission is concrete, not a percentage in the abstract.
const example = computed(() => {
    const job = 10000;
    const commission = Math.round((job * (props.terms.commission_percent || 0)) / 100);
    return { job, commission, keep: job - commission };
});

const how = computed(() => [
    { icon: '📲', title: L('يصلك الطلب على واتساب', 'Jobs reach you on WhatsApp'), body: L('عند حجز عميل في حيّك وتخصصك، تصلك رسالة بتفاصيل الخدمة والسعر التقديري. تردّ بـ«نعم» لقبول الطلب.', 'When a customer books in your district and trade, you get a message with the service and estimate. Reply “yes” to accept.') },
    { icon: '💳', title: L('محفظة مسبقة الدفع', 'A prepaid wallet'), body: L(`تشحن محفظتك برصيد لا يقل عن ${format(props.terms.min_float)} بحوالة أو إيداع، ونؤكده بعد مراجعة الإشعار.`, `Top up your wallet with at least ${format(props.terms.min_float)} by transfer or deposit; we confirm it after checking the receipt.`) },
    { icon: '🤝', title: L('العميل يدفع لك نقداً', 'The customer pays you in cash'), body: L('عند إنجاز الخدمة تستلم المبلغ كاملاً من العميل مباشرة (الدفع عند الاستلام).', 'When the job is done you collect the full amount from the customer directly (cash on delivery).') },
    { icon: '🧾', title: L(`عمولة ${props.terms.commission_percent}% تُخصم من المحفظة`, `${props.terms.commission_percent}% commission from the wallet`), body: L('بعد إغلاق الطلب تُخصم عمولة المنصة من رصيد محفظتك تلقائياً. إن لم يكفِ الرصيد يُسجَّل الباقي مستحقاً عليك حتى تشحن.', 'After the order closes, the platform commission is deducted from your wallet automatically. If the balance is short, the rest stays due until you top up.') },
]);

// ---- application form ---------------------------------------------------
const form = reactive({
    display_name: user.value?.name || '',
    primary_category_id: '',
    base_governorate_id: props.governorates[0]?.id ?? '',
    base_district_id: '',
    headline_ar: '',
    years_experience: '',
});
const files = reactive({ national_id_front: null, national_id_back: null, selfie: null, trade_certificate: null });
const documents = computed(() => [
    { key: 'national_id_front', label: L('الهوية — الوجه الأمامي', 'ID — front'), required: true },
    { key: 'national_id_back', label: L('الهوية — الوجه الخلفي', 'ID — back'), required: true },
    { key: 'selfie', label: L('صورة شخصية واضحة', 'Clear selfie'), required: true },
    { key: 'trade_certificate', label: L('شهادة مهنية أو خبرة (اختياري)', 'Trade certificate (optional)'), required: false },
]);

const governorate = computed(() => props.governorates.find((g) => g.id === form.base_governorate_id));
// Pros can sign up for coming-soon districts too: that is how supply is built before launch.
const districts = computed(() => governorate.value?.districts || []);
watch(() => form.base_governorate_id, () => { form.base_district_id = ''; });

const MAX_BYTES = 8 * 1024 * 1024;
const fileError = ref('');
function pick(key, event) {
    const file = event.target.files?.[0] || null;
    fileError.value = '';
    if (file && file.size > MAX_BYTES) {
        fileError.value = L('حجم الملف يتجاوز 8 ميجابايت.', 'File is larger than 8 MB.');
        event.target.value = '';
        return;
    }
    files[key] = file;
}

const state = ref(props.application ? 'done' : 'form'); // form | sending | done
const progress = ref('');
const errors = ref({});
const result = ref(props.application);
const firstError = computed(() => Object.values(errors.value).flat()[0]);

async function submit() {
    errors.value = {};
    state.value = 'sending';
    try {
        // 1. profile  2. each document to the private disk  3. hand over for review
        if (!result.value) {
            progress.value = L('إنشاء ملف الفني…', 'Creating your profile…');
            await api.post('/trade/register', {
                display_name: form.display_name.trim(),
                primary_category_id: form.primary_category_id,
                base_governorate_id: form.base_governorate_id,
                base_district_id: form.base_district_id || null,
                headline_ar: form.headline_ar.trim() || null,
                years_experience: form.years_experience === '' ? null : Number(form.years_experience),
            });
            result.value = { display_name: form.display_name, kyc_status: 'unverified', documents: 0 };
        }

        for (const doc of documents.value) {
            if (!files[doc.key]) continue;
            progress.value = L(`رفع: ${doc.label}…`, `Uploading: ${doc.label}…`);
            const body = new FormData();
            body.append('type', doc.key);
            body.append('file', files[doc.key]);
            await api.post('/trade/kyc/documents', body);
            files[doc.key] = null;
            result.value.documents += 1;
        }

        progress.value = L('إرسال للمراجعة…', 'Submitting for review…');
        await api.post('/trade/kyc/submit', {});
        result.value.kyc_status = 'pending';
        state.value = 'done';
    } catch (e) {
        errors.value = e.response?.data?.errors || { _: [e.response?.data?.message || t('common.error')] };
        state.value = 'form';
    }
}

const statusLabel = computed(() => ({
    unverified: L('لم تُرسَل المستندات بعد', 'Documents not submitted yet'),
    pending: L('بانتظار المراجعة', 'Waiting for review'),
    in_review: L('قيد المراجعة', 'In review'),
    verified: L('موثّق ✓', 'Verified ✓'),
    rejected: L('مرفوض — تواصل مع الدعم', 'Rejected — contact support'),
}[result.value?.kyc_status] || ''));
// A profile exists but the documents never made it: let them finish.
const needsDocuments = computed(() => result.value && result.value.kyc_status === 'unverified');
</script>

<template>
    <Head>
        <title>{{ L('انضم كفني | خدمات اليمن', 'Join as a technician | Yemen Services') }}</title>
        <meta name="description" :content="L('سجّل كفني في خدمات اليمن: استقبل الطلبات على واتساب، استلم أجرك نقداً، وادفع عمولة واضحة من محفظة مسبقة.', 'Register as a technician: get jobs on WhatsApp, get paid in cash, pay a clear commission from a prepaid wallet.')" />
    </Head>

    <PublicLayout>
        <!-- intro -->
        <section class="relative overflow-hidden bg-ink text-white">
            <div class="pointer-events-none absolute -top-24 end-0 h-80 w-80 rounded-full bg-crimson/25 blur-3xl" aria-hidden="true" />
            <div class="relative mx-auto max-w-5xl px-4 py-14 sm:py-20">
                <p class="text-sm font-bold text-crimson-300">{{ L('للفنيين والمحترفين', 'For technicians & professionals') }}</p>
                <h1 class="mt-3 max-w-2xl text-3xl font-black leading-tight sm:text-5xl">{{ L('استقبل طلبات في حيّك، واستلم أجرك نقداً', 'Get jobs in your district. Get paid in cash.') }}</h1>
                <p class="mt-4 max-w-xl text-base leading-8 text-white/70">{{ L('سجّل، وثّق هويتك، اشحن محفظتك، وابدأ. بدون اشتراك شهري — عمولة فقط عن كل طلب مُنجز.', 'Register, verify your identity, top up your wallet and start. No monthly fee — commission only on completed jobs.') }}</p>
                <a href="#apply" class="btn-primary mt-7 !px-7 !py-4 text-base">{{ L('سجّل الآن', 'Apply now') }}</a>
            </div>
        </section>

        <!-- how it works -->
        <section class="mx-auto max-w-5xl px-4 py-14">
            <h2 class="text-2xl font-black text-ink sm:text-3xl">{{ L('كيف تعمل المنصة مع الفني؟', 'How it works for you') }}</h2>
            <ol class="mt-6 grid gap-4 sm:grid-cols-2">
                <li v-for="(step, i) in how" :key="i" class="card p-5">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-ink-100 text-xl" aria-hidden="true">{{ step.icon }}</span>
                        <h3 class="font-extrabold text-ink">{{ i + 1 }}. {{ step.title }}</h3>
                    </div>
                    <p class="mt-3 text-sm leading-7 text-ink-600">{{ step.body }}</p>
                </li>
            </ol>

            <!-- worked example -->
            <div class="mt-6 rounded-3xl border border-ink-200 bg-white p-5 sm:p-6">
                <h3 class="font-extrabold text-ink">{{ L('مثال: طلب بقيمة', 'Example: a job worth') }} {{ format(example.job) }}</h3>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-2xl bg-ink-50 p-4"><dt class="text-ink-500">{{ L('تستلم من العميل نقداً', 'Cash from the customer') }}</dt><dd class="mt-1 text-xl font-black text-ink">{{ format(example.job) }}</dd></div>
                    <div class="rounded-2xl bg-crimson-50 p-4"><dt class="text-crimson-700">{{ L(`عمولة المنصة ${terms.commission_percent}% (من المحفظة)`, `Platform commission ${terms.commission_percent}% (from wallet)`) }}</dt><dd class="mt-1 text-xl font-black text-crimson-700">− {{ format(example.commission) }}</dd></div>
                    <div class="rounded-2xl bg-ink p-4 text-white"><dt class="text-white/60">{{ L('صافي ربحك', 'You keep') }}</dt><dd class="mt-1 text-xl font-black">{{ format(example.keep) }}</dd></div>
                </dl>
                <p class="mt-3 text-xs leading-6 text-ink-500">{{ L('العمولة تُحسب على قيمة الخدمة. رسوم الانتقال والاستعجال قد تغيّر الإجمالي الذي يدفعه العميل.', 'Commission is calculated on the service value. Call-out and urgency fees can change the total the customer pays.') }}</p>
            </div>
        </section>

        <!-- application -->
        <section id="apply" class="scroll-mt-24 border-t border-ink-200 bg-white">
            <div class="mx-auto max-w-2xl px-4 py-14">
                <h2 class="text-2xl font-black text-ink sm:text-3xl">{{ L('طلب الانضمام', 'Application') }}</h2>

                <!-- not signed in -->
                <div v-if="!user" class="card mt-6 p-6 text-center">
                    <p class="text-sm leading-7 text-ink-600">{{ L('سجّل الدخول برمز تحقق أولاً (بدون كلمة مرور)، ثم أكمل بياناتك وارفع مستنداتك.', 'Sign in with a one-time code first (no password), then complete your details and upload your documents.') }}</p>
                    <Link :href="route('login', { redirect: '/join-technician#apply' })" class="btn-primary mt-5 w-full">{{ t('nav.login') }}</Link>
                </div>

                <!-- submitted -->
                <div v-else-if="state === 'done' && !needsDocuments" class="card mt-6 p-6" aria-live="polite">
                    <div class="flex items-center gap-3">
                        <span class="grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-2xl" aria-hidden="true">✓</span>
                        <div>
                            <p class="font-extrabold text-ink">{{ result.display_name }}</p>
                            <p class="text-sm text-ink-600">{{ statusLabel }} · {{ result.documents }} {{ L('مستندات', 'documents') }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-ink-600">{{ L('استلمنا طلبك. بعد مراجعة المستندات نفعّل حسابك، ثم تشحن محفظتك لتبدأ استقبال الطلبات.', 'We have your application. Once the documents are reviewed we activate your account; then top up your wallet to start receiving jobs.') }}</p>
                    <Link :href="route('trade.dashboard')" class="btn-ghost mt-5 w-full">{{ L('لوحة الفني', 'Technician dashboard') }}</Link>
                </div>

                <!-- form -->
                <form v-else class="mt-6 space-y-5" @submit.prevent="submit">
                    <fieldset v-if="!result" class="card space-y-4 p-5" :disabled="state === 'sending'">
                        <legend class="px-1 text-sm font-extrabold text-ink">{{ L('١ — بياناتك', '1 — Your details') }}</legend>
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold">{{ L('الاسم كما يظهر للعملاء', 'Name shown to customers') }} <span class="text-crimson">*</span></span>
                            <input v-model="form.display_name" class="field" maxlength="120" autocomplete="name" required />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold">{{ L('التخصص', 'Trade') }} <span class="text-crimson">*</span></span>
                            <select v-model="form.primary_category_id" class="field" required>
                                <option value="" disabled>—</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ ar ? c.name_ar : c.name_en }}</option>
                            </select>
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ t('booking.governorate') }} <span class="text-crimson">*</span></span>
                                <select v-model="form.base_governorate_id" class="field" required>
                                    <option v-for="g in governorates" :key="g.id" :value="g.id">{{ g.name }}</option>
                                </select>
                            </label>
                            <label v-if="districts.length" class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ L('الحي الذي تعمل فيه', 'District you work in') }} <span class="text-crimson">*</span></span>
                                <select v-model="form.base_district_id" class="field" required>
                                    <option value="" disabled>—</option>
                                    <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}<template v-if="d.status !== 'active'"> — {{ t('booking.coming_soon') }}</template></option>
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ L('نبذة قصيرة (اختياري)', 'Short headline (optional)') }}</span>
                                <input v-model="form.headline_ar" class="field" maxlength="160" :placeholder="L('مثال: سبّاك خبرة ١٠ سنوات', 'e.g. Plumber, 10 years')" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-semibold">{{ L('سنوات الخبرة', 'Years') }}</span>
                                <input v-model="form.years_experience" type="number" min="0" max="60" inputmode="numeric" class="field" />
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="card space-y-4 p-5" :disabled="state === 'sending'">
                        <legend class="px-1 text-sm font-extrabold text-ink">{{ result ? L('أكمل رفع مستنداتك', 'Finish uploading your documents') : L('٢ — مستندات التوثيق (KYC)', '2 — Verification documents (KYC)') }}</legend>
                        <p class="text-xs leading-6 text-ink-500">🔒 {{ L('تُحفظ مستنداتك في تخزين خاص ولا يراها إلا فريق المراجعة. الصيغ: JPG أو PNG أو PDF، حتى 8 ميجابايت.', 'Your documents are kept in private storage and seen only by the review team. JPG, PNG or PDF, up to 8 MB.') }}</p>
                        <label v-for="doc in documents" :key="doc.key" class="block">
                            <span class="mb-1 block text-sm font-semibold">{{ doc.label }} <span v-if="doc.required" class="text-crimson">*</span></span>
                            <input type="file" accept="image/jpeg,image/png,application/pdf" class="block w-full rounded-xl border border-ink-200 bg-ink-50 text-sm file:me-3 file:border-0 file:bg-ink file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-white" :required="doc.required" @change="pick(doc.key, $event)" />
                        </label>
                        <p v-if="fileError" class="text-xs font-semibold text-crimson" role="alert">{{ fileError }}</p>
                    </fieldset>

                    <p v-if="firstError" class="rounded-xl border border-crimson-200 bg-crimson-50 p-3 text-sm font-semibold text-crimson-700" role="alert">{{ firstError }}</p>
                    <p v-if="state === 'sending'" class="text-sm font-semibold text-ink-600" aria-live="polite">{{ progress }}</p>

                    <button type="submit" class="btn-primary w-full !py-4 text-base" :disabled="state === 'sending'">{{ state === 'sending' ? t('booking.sending') : L('إرسال الطلب للمراجعة', 'Submit for review') }}</button>
                </form>
            </div>
        </section>
    </PublicLayout>
</template>
