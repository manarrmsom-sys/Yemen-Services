<script setup>
import { ref, computed } from 'vue';
import { Head, usePage, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t, locale } = useI18n();
const page = usePage();

// جلب الأقسام والخدمات من Catalog
const categories = computed(() => page.props.catalog || []);
const services = computed(() => 
    categories.value.flatMap((category) => 
        (category.services || []).map((service) => ({ 
            ...service, 
            category_name: category.name,
            category_id: category.id 
        }))
    )
);

// التحكم في النافذة المنبثقة Modal
const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const form = useForm({
    category_id: '',
    name: '',
    slug: '',
    pricing_model: 'fixed',
    base_price: 0,
});

const openCreateModal = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    if (categories.value.length > 0) {
        form.category_id = categories.value[0].id;
    }
    showModal.value = true;
};

const openEditModal = (service) => {
    isEditing.value = true;
    editingId.value = service.id;
    form.category_id = service.category_id;
    form.name = service.name;
    form.slug = service.slug || '';
    form.pricing_model = service.pricing_model || 'fixed';
    form.base_price = service.base_price || 0;
    showModal.value = true;
};

const submitForm = () => {
    if (isEditing.value) {
        form.put(`/api/v1/admin/services/${editingId.value}`, {
            onSuccess: () => closeModal()
        });
    } else {
        form.post('/api/v1/admin/services', {
            onSuccess: () => closeModal()
        });
    }
};

const deleteService = (id) => {
    if (confirm('هل أنت متأكد من رغبتك في حذف هذه الخدمة؟')) {
        form.delete(`/api/v1/admin/services/${id}`);
    }
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
};
</script>

<template>
    <Head :title="t('admin.services')" />
    <AdminLayout>
        <template #title>
            <div class="flex items-center justify-between w-full">
                <span>{{ t('admin.services') }}</span>
                <button @click="openCreateModal" class="px-4 py-2 text-sm font-bold text-white bg-crimson-600 rounded hover:bg-crimson-700">
                    + إضافة خدمة جديدة
                </button>
            </div>
        </template>

        <div class="overflow-x-auto border border-slate-200 bg-white rounded shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-start text-xs text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="p-3 text-start">{{ locale === 'ar' ? 'الخدمة' : 'Service' }}</th>
                        <th class="p-3 text-start">{{ locale === 'ar' ? 'القسم' : 'Category' }}</th>
                        <th class="p-3 text-start">{{ locale === 'ar' ? 'النموذج' : 'Pricing model' }}</th>
                        <th class="p-3 text-end">{{ locale === 'ar' ? 'الإجراءات' : 'Actions' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="service in services" :key="service.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="p-3 font-bold text-obsidian-900">{{ service.name }}</td>
                        <td class="p-3 text-slate-600">{{ service.category_name }}</td>
                        <td class="p-3 text-slate-500">
                            <span class="px-2 py-0.5 text-xs rounded bg-slate-100 font-mono">{{ service.pricing_model }}</span>
                        </td>
                        <td class="p-3 text-end space-x-2 space-x-reverse">
                            <button @click="openEditModal(service)" class="px-3 py-1 text-xs text-blue-600 bg-blue-50 rounded hover:bg-blue-100">
                                تعديل
                            </button>
                            <button @click="deleteService(service.id)" class="px-3 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                                حذف
                            </button>
                        </td>
                    </tr>
                    <tr v-if="services.length === 0">
                        <td colspan="4" class="p-6 text-center text-slate-400">لا توجد خدمات متاحة حالياً</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal الإضافة والتعديل -->
        <div v-if="showModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
                <h3 class="text-lg font-bold mb-4">{{ isEditing ? 'تعديل خدمة' : 'إضافة خدمة جديدة' }}</h3>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">القسم التابع له</label>
                        <select v-model="form.category_id" class="w-full border border-slate-300 rounded p-2 text-sm" required>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">اسم الخدمة</label>
                        <input v-model="form.name" type="text" class="w-full border border-slate-300 rounded p-2 text-sm" required />
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">الرابط اللطيف (Slug)</label>
                        <input v-model="form.slug" type="text" class="w-full border border-slate-300 rounded p-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">نموذج التسعير</label>
                        <select v-model="form.pricing_model" class="w-full border border-slate-300 rounded p-2 text-sm">
                            <option value="fixed">ثابت (Fixed)</option>
                            <option value="hourly">بالساعة (Hourly)</option>
                            <option value="quote">تسعير حسب الطلب (Quote)</option>
                            <option value="per_unit">بالوحدة (Per Unit)</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                        <button type="button" @click="closeModal" class="px-4 py-2 text-xs bg-slate-100 rounded">إلغاء</button>
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 text-xs text-white bg-crimson-600 rounded">
                            {{ isEditing ? 'حفظ التعديلات' : 'إنشاء' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>