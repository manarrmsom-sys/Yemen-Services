<script setup>
import { ref } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Card with a soft crimson spotlight that follows the pointer
 * (Inspira UI "Card Spotlight" pattern). On touch devices there is no hover,
 * so it behaves as a plain card with a press state.
 */
const props = defineProps({
    as: { type: String, default: 'div' },
    class: { type: String, default: '' },
});

const x = ref(50);
const y = ref(50);
const active = ref(false);

function move(event) {
    if (event.pointerType === 'touch') return;
    const rect = event.currentTarget.getBoundingClientRect();
    x.value = event.clientX - rect.left;
    y.value = event.clientY - rect.top;
    active.value = true;
}
</script>

<template>
    <component
        :is="as"
        :class="cn('group relative overflow-hidden rounded-2xl border border-ink-200/80 bg-white transition duration-300 hover:-translate-y-1 hover:border-crimson/40 hover:shadow-lift active:scale-[0.99]', props.class)"
        @pointermove="move"
        @pointerleave="active = false"
    >
        <span
            class="pointer-events-none absolute inset-0 transition-opacity duration-300"
            :class="active ? 'opacity-100' : 'opacity-0'"
            :style="{ background: `radial-gradient(260px circle at ${x}px ${y}px, rgba(206,17,38,.10), transparent 70%)` }"
            aria-hidden="true"
        />
        <slot />
    </component>
</template>
