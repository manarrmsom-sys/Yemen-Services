<script setup>
import { useLocale } from '@/composables/useLocale';

const { locale, setLocale } = useLocale();
</script>

<template>
    <button
        type="button"
        class="chip bg-obsidian-100 text-obsidian-900 hover:bg-obsidian-100/70"
        :aria-label="locale === 'ar' ? 'Switch to English' : 'التبديل إلى العربية'"
        @click="setLocale(locale === 'ar' ? 'en' : 'ar')"
    >
        <span aria-hidden="true">🌐</span>
        {{ locale === 'ar' ? 'EN' : 'ع' }}
    </button>
</template>
