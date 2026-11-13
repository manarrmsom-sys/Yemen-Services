<script setup>
import { useSmoothScroll } from '@/composables/useSmoothScroll';

/**
 * Wrap a layout in this to opt its pages into Lenis smooth scrolling synced
 * with GSAP ScrollTrigger. Renders no extra box of its own beyond the wrapper;
 * all behaviour lives in the shared composable.
 */
useSmoothScroll();
</script>

<template>
    <div data-smooth-scroll>
        <slot />
    </div>
</template>
