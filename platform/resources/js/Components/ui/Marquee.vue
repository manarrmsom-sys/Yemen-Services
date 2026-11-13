<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

/**
 * Infinite horizontal ticker (Inspira UI "Marquee" pattern, CSS only).
 *
 * The slot is rendered twice and the track slides by half its width, so the
 * loop is seamless. In RTL the track is anchored to the right edge and slides
 * right, so text enters from the reading-start side in both directions.
 * Pauses on hover and keyboard focus; static under prefers-reduced-motion.
 */
const props = defineProps({
    /** Seconds for one full loop. */
    duration: { type: Number, default: 30 },
    class: { type: String, default: '' },
});

const page = usePage();
const rtl = computed(() => (page.props.dir || 'rtl') === 'rtl');
</script>

<template>
    <!-- The moving geometry is always LTR; each copy restores the text direction. -->
    <div :class="cn('marquee flex overflow-hidden', rtl ? 'justify-end' : 'justify-start', props.class)" dir="ltr">
        <div
            class="marquee-track flex w-max shrink-0 will-change-transform"
            :class="rtl ? 'animate-marquee-rtl' : 'animate-marquee'"
            :style="{ '--marquee-duration': `${duration}s` }"
        >
            <div class="flex shrink-0 items-center" :dir="rtl ? 'rtl' : 'ltr'"><slot /></div>
            <div class="flex shrink-0 items-center" :dir="rtl ? 'rtl' : 'ltr'" aria-hidden="true"><slot /></div>
        </div>
    </div>
</template>
