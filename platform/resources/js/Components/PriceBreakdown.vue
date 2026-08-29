<script setup>
import { useMoney } from '@/composables/useMoney';
import { useI18n } from 'vue-i18n';

defineProps({
    quote: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

const { format } = useMoney();
const { t, locale } = useI18n();

const label = (line) => (locale.value === 'ar' ? line.label_ar : line.label_en);
</script>

<template>
    <div class="card p-4">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-bold text-obsidian-900">{{ t('estimate.title') }}</h3>
            <span v-if="loading" class="text-xs text-obsidian-900/50">{{ t('common.loading') }}</span>
        </div>

        <template v-if="quote && !quote.requires_quote">
            <ul class="space-y-1.5 text-sm">
                <li v-for="(line, i) in quote.lines" :key="i" class="flex justify-between">
                    <span class="text-obsidian-900/70">{{ label(line) }}</span>
                    <span :class="line.amount < 0 ? 'text-emerald-600' : 'text-obsidian-900'">
                        {{ format(line.amount) }}
                    </span>
                </li>
            </ul>
            <div class="mt-3 flex items-center justify-between border-t border-obsidian-100 pt-3">
                <span class="font-bold">{{ t('estimate.total') }}</span>
                <span class="text-lg font-extrabold text-crimson">{{ format(quote.total) }}</span>
            </div>
            <p class="mt-2 text-xs text-obsidian-900/50">{{ t('estimate.note') }}</p>
        </template>

        <p v-else-if="quote && quote.requires_quote" class="text-sm text-obsidian-900/70">
            {{ t('estimate.requires_quote') }}
        </p>

        <p v-else class="text-sm text-obsidian-900/40">—</p>
    </div>
</template>
