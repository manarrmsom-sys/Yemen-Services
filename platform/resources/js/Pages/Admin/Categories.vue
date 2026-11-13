<script setup>
import { ref, computed } from 'vue';
import { Head, usePage, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t, locale } = useI18n();
const page = usePage();
const categories = computed(() => page.props.catalog || []);

// التحكم في النافذة المنبثقة
const showModal = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    slug: '',
    image_url: ''
});

const openCreateModal = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    showModal.value = true;
};

const openEditModal = (category) => {
    isEditing.value = true;
    editingId.value = category.id;
    form.name = category.name;
    form.slug = category.slug || '';
    form.image_url = category.image_url || '';
    showModal.value = true;
};

const submitForm = () => {
    if (isEditing.value) {
        form.put(`/api/v1/admin/categories/${editingId.value}`, {
            onSuccess: () => closeModal()
        });
    } else {
        form.post('/api/v1/admin/categories', {
            onSuccess: () => closeModal()
        });
    }
};

const deleteCategory = (id) => {
    if (confirm('هل أنت تأكد من رغبتك في حذف هذا القسم؟')) {
        form.delete(`/api/v1/admin/categories/${id}`);
    }
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
};
</script>

<template>
    <Head :title="t('admin.categories')" />
    <AdminLayout>
        <template #title>
            <div class="flex items-center justify-between w-full">
                <span>{{ t('admin.categories') }}</span>
                <button @click="openCreateModal" class="px-4 py-2 text-sm font-bold text-white bg-crimson-600 rounded hover:bg-crimson-700">
                    + إضافة قسم جديد
                </button>
            </div>
        </template>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="category in categories" :key="category.id" class="overflow-hidden border border-slate-200 bg-white rounded shadow-sm relative group">
                <img v-if="category.image_url" :src="category.image_url" :alt="category.name" class="h-36 w-full object-cover" loading="lazy" />
                <div class="p-4">
                    <h2 class="font-bold text-obsidian-900">{{ category.name }}</h2>
                    <p class="mt-1 text-xs text-obsidian-900/55">{{ category.services?.length || 0 }} {{ locale === 'ar' ? 'خدمة' : 'services' }}</p>
                    
                    <!-- أزرار التحكم -->
                    <div class="mt-4 pt-3 border-t border-slate-100 flex justify-end gap-2">
                        <button @click="openEditModal(category)" class="px-3 py-1 text-xs text-blue-600 bg-blue-50 rounded hover:bg-blue-100">
                            تعديل
                        </button>
                        <button @click="deleteCategory(category.id)" class="px-3 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                            حذف
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <!-- نافذة الإضافة والتعديل Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
                <h3 class="text-lg font-bold mb-4">{{ isEditing ? 'تعديل قسم' : 'إضافة قسم جديد' }}</h3>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">اسم القسم</label>
                        <input v-model="form.name" type="text" class="w-full border border-slate-300 rounded p-2 text-sm" required />
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">الرابط اللطيف (Slug)</label>
                        <input v-model="form.slug" type="text" class="w-full border border-slate-300 rounded p-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">رابط الصورة (Image URL)</label>
                        <input v-model="form.image_url" type="text" class="w-full border border-slate-300 rounded p-2 text-sm" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
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