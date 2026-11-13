<script setup>
/**
 * Decorative hero background: a fine ink grid on ivory with two slow crimson
 * glows and the three flag bands as a thin edge accent.
 * Pure CSS (GPU transforms only), so it costs nothing on low-end phones and
 * stops under prefers-reduced-motion.
 */
</script>

<template>
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <!-- grid, fading out toward the bottom -->
        <div
            class="absolute inset-0 opacity-[0.55] [background-image:linear-gradient(rgba(17,19,21,.055)_1px,transparent_1px),linear-gradient(90deg,rgba(17,19,21,.055)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_80%_70%_at_50%_0%,#000_35%,transparent_100%)]"
        />
        <!-- crimson glows: the 10% accent, kept soft -->
        <div class="absolute -top-32 end-[-10%] h-[28rem] w-[28rem] animate-drift rounded-full bg-crimson/[0.13] blur-3xl" />
        <div class="absolute bottom-[-12rem] start-[-8%] h-[24rem] w-[24rem] animate-drift rounded-full bg-crimson/[0.07] blur-3xl [animation-delay:-7s]" />
        <!-- flag bands -->
        <div class="absolute inset-y-0 start-0 flex w-1.5 flex-col">
            <span class="flex-1 bg-crimson" />
            <span class="flex-1 bg-white" />
            <span class="flex-1 bg-ink" />
        </div>
    </div>
</template>
