import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { loadMotion, prefersReducedMotion } from '@/lib/motion';

/**
 * Lenis smooth scrolling, driven by GSAP's ticker and kept in sync with
 * ScrollTrigger. One instance is shared by every layout that asks for it.
 *
 * Inertia swaps page components without a document reload, so layouts mount
 * and unmount on each visit. The instance is reference-counted: it survives a
 * public → public navigation and is torn down when the visitor moves to a
 * layout that does not use it (the admin dashboard).
 *
 * Touch devices keep native scrolling (Lenis `syncTouch` is off); only wheel
 * and trackpad input is smoothed, which is what keeps phones responsive.
 */
let lenis = null;
let motion = null;
let users = 0;
let starting = null;
let tick = null;
let offNavigate = null;
let destroyTimer = null;

async function start() {
    if (lenis || starting || prefersReducedMotion()) return starting;

    starting = (async () => {
        const [{ default: Lenis }, loaded] = await Promise.all([import('lenis'), loadMotion()]);
        if (users === 0 || lenis) return; // unmounted while loading

        motion = loaded;
        lenis = new Lenis({
            duration: 1.05,
            smoothWheel: true,
            syncTouch: false,
            autoRaf: false,
            anchors: true,
        });

        lenis.on('scroll', motion.ScrollTrigger.update);
        tick = (time) => lenis?.raf(time * 1000);
        motion.gsap.ticker.add(tick);
        motion.gsap.ticker.lagSmoothing(0);

        // Inertia has already reset (or deliberately preserved) the scroll
        // position by the time `navigate` fires. Cancel any in-flight glide and
        // adopt that position, then let ScrollTrigger re-measure the new page.
        offNavigate = router.on('navigate', () => {
            requestAnimationFrame(() => {
                lenis?.scrollTo(window.scrollY, { immediate: true, force: true });
                motion?.ScrollTrigger.refresh();
            });
        });
    })().finally(() => {
        starting = null;
    });

    return starting;
}

function destroy() {
    offNavigate?.();
    offNavigate = null;
    if (tick) motion?.gsap.ticker.remove(tick);
    tick = null;
    lenis?.destroy();
    lenis = null;
}

/** Pause / resume smoothing, e.g. while a modal owns the scroll. */
export function setScrollLocked(locked) {
    if (!lenis) return;
    if (locked) lenis.stop();
    else lenis.start();
}

/** Glide to an element, selector or offset. Falls back to native scrolling. */
export function scrollToTarget(target, options = {}) {
    if (lenis) {
        lenis.scrollTo(target, { offset: -72, ...options });
        return;
    }
    const el = typeof target === 'string' ? document.querySelector(target) : target;
    if (el?.scrollIntoView) el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
}

export function useSmoothScroll() {
    onMounted(() => {
        users += 1;
        clearTimeout(destroyTimer);
        start();
    });

    onBeforeUnmount(() => {
        users -= 1;
        // The next page's layout mounts in the same tick; only tear down if
        // nothing claimed the instance.
        destroyTimer = setTimeout(() => {
            if (users === 0) destroy();
        }, 80);
    });

    return { scrollToTarget, setScrollLocked };
}
