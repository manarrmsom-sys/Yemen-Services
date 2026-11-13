<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Marquee from '@/Components/ui/Marquee.vue';

/**
 * Moving announcement strip. Content, colours and speed come from
 * Admin → Appearance via the shared `announcement` Inertia prop; the bar is
 * not rendered at all when the admin disables it or leaves it empty.
 */
const page = usePage();
const bar = computed(() => page.props.announcement);

// Colours are validated server-side as #RRGGBB before they are stored.
const style = computed(() => ({ backgroundColor: bar.value.background, color: bar.value.color }));
// A marquee copy narrower than the screen would leave a visible gap, so short
// lists are repeated until there are enough entries to overflow any viewport.
const loop = computed(() => {
    const items = bar.value?.items || [];
    const times = items.length ? Math.max(1, Math.ceil(8 / items.length)) : 0;
    return Array.from({ length: times }, () => items).flat();
});
const internal = (href) => typeof href === 'string' && href.startsWith('/');
</script>

<template>
    <aside v-if="bar" class="relative z-50 text-xs font-semibold sm:text-[13px]" :style="style" role="region" :aria-label="page.props.locale === 'ar' ? 'إعلانات' : 'Announcements'">
        <Marquee :duration="bar.speed" class="py-2">
            <template v-for="(item, i) in loop" :key="i">
                <component
                    :is="item.href ? (internal(item.href) ? Link : 'a') : 'span'"
                    :href="item.href || undefined"
                    :rel="item.href && !internal(item.href) ? 'noopener noreferrer' : undefined"
                    class="whitespace-nowrap px-6"
                    :class="item.href ? 'underline-offset-4 hover:underline' : ''"
                >{{ item.text }}</component>
                <span class="opacity-50" aria-hidden="true">◆</span>
            </template>
        </Marquee>
    </aside>
</template>
