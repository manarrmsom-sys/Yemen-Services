<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { prefersReducedMotion } from '@/lib/motion';

/**
 * Counts up to `value` the first time it scrolls into view.
 * Renders the final number immediately when motion is reduced or
 * IntersectionObserver is unavailable, so the figure is never wrong.
 */
const props = defineProps({
    value: { type: Number, default: 0 },
    duration: { type: Number, default: 1400 },
});

const { locale } = useI18n();
const el = ref(null);
const shown = ref(props.value);
let observer = null;
let frame = null;

const format = (n) => new Intl.NumberFormat(locale.value === 'ar' ? 'ar-YE' : 'en-US', { maximumFractionDigits: 0 }).format(n);

function run() {
    const target = props.value;
    const started = performance.now();
    const step = (now) => {
        const p = Math.min(1, (now - started) / props.duration);
        shown.value = Math.round(target * (1 - Math.pow(1 - p, 3))); // easeOutCubic
        if (p < 1) frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
}

onMounted(() => {
    if (prefersReducedMotion() || !('IntersectionObserver' in window) || props.value <= 0) return;

    shown.value = 0;
    observer = new IntersectionObserver(([entry]) => {
        if (!entry.isIntersecting) return;
        observer.disconnect();
        run();
    }, { threshold: 0.4 });
    observer.observe(el.value);
});

watch(() => props.value, (v) => {
    cancelAnimationFrame(frame);
    shown.value = v;
});

onBeforeUnmount(() => {
    observer?.disconnect();
    cancelAnimationFrame(frame);
});
</script>

<template>
    <span ref="el" class="tabular-nums">{{ format(shown) }}</span>
</template>
