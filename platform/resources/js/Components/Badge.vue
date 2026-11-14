<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ type: { type: String, required: true } });
const { t } = useI18n();

const meta = computed(() => ({
    verified: { icon: '✅', cls: 'bg-crimson-50 text-crimson-700 ring-1 ring-crimson-200' },
    background_checked: { icon: '🛡️', cls: 'bg-obsidian-100 text-obsidian-900' },
    top_rated: { icon: '⭐', cls: 'bg-gold-50 text-gold-700 ring-1 ring-gold/40' },
    experienced: { icon: '🔧', cls: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' },
}[props.type] || { icon: '•', cls: 'bg-obsidian-100 text-obsidian-900' }));
</script>

<template>
    <span class="chip" :class="meta.cls">
        <span aria-hidden="true">{{ meta.icon }}</span>
        {{ t(`badges.${type}`) }}
    </span>
</template>
