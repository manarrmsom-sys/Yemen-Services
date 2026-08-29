<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import BookingDrawer from '@/Components/Booking/BookingDrawer.vue';

const props = defineProps({ category: Object, governorates: { type: Array, default: () => [] } });
const { locale } = useI18n();
const drawer = ref(false);
const slug = ref(null);

const name = (o, base) => (locale.value === 'ar' ? o[`${base}_ar`] : o[`${base}_en`]);
function book(s) { slug.value = s.slug; drawer.value = true; }
</script>

<template>
    <Head :title="name(category, 'name')" />
    <PublicLayout>
        <div class="mx-auto max-w-4xl px-4 py-10">
            <h1 class="text-2xl font-extrabold text-obsidian-900">{{ name(category, 'name') }}</h1>
            <p class="mt-1 text-obsidian-900/60">{{ name(category, 'description') }}</p>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <button v-for="s in category.services" :key="s.id" class="card p-4 text-start" @click="book(s)">
                    <p class="font-bold text-obsidian-900">{{ name(s, 'name') }}</p>
                    <p class="text-sm text-obsidian-900/60">{{ name(s, 'description') }}</p>
                </button>
            </div>
        </div>
        <BookingDrawer :open="drawer" :governorates="governorates" :preselect-service-slug="slug" @close="drawer = false" />
    </PublicLayout>
</template>
