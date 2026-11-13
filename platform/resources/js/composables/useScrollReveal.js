import { nextTick, onBeforeUnmount, onMounted } from 'vue';
import { loadMotion, prefersReducedMotion } from '@/lib/motion';

/**
 * Declarative scroll animations for everything inside `root`:
 *
 *   data-reveal            fade + rise when the element scrolls into view
 *   data-reveal="side"     slide in from the inline-start edge (RTL aware)
 *   data-reveal="scale"    fade + scale up
 *   data-reveal-delay=".1" extra delay in seconds
 *   data-reveal-group      stagger the element's direct children
 *   data-parallax="-12"    scrub the element by N percent while its parent passes
 *
 * Content is visible by default. Only elements still below the fold when GSAP
 * arrives are hidden and animated, so nothing flashes and the page stays fully
 * readable without JavaScript, offline, or with reduced motion.
 *
 * Everything is created inside a gsap.context scoped to `root` and reverted on
 * unmount, so triggers never leak across Inertia page visits.
 */
export function useScrollReveal(root) {
    let ctx = null;
    let disposed = false;
    let trigger = null;

    const build = async () => {
        if (prefersReducedMotion() || !root.value) return;

        const { gsap, ScrollTrigger } = await loadMotion();
        if (disposed || !root.value) return;
        trigger = ScrollTrigger;

        ctx?.revert();
        ctx = gsap.context(() => {
            const fold = window.innerHeight * 0.92;
            const belowFold = (el) => el.getBoundingClientRect().top > fold;
            const startSide = document.documentElement.dir === 'rtl' ? 1 : -1;

            const from = (kind) => {
                if (kind === 'side') return { autoAlpha: 0, x: 40 * startSide };
                if (kind === 'scale') return { autoAlpha: 0, scale: 0.94 };
                return { autoAlpha: 0, y: 28 };
            };

            gsap.utils.toArray('[data-reveal]').forEach((el) => {
                if (!belowFold(el)) return;
                gsap.from(el, {
                    ...from(el.dataset.reveal),
                    duration: 0.65,
                    ease: 'power3.out',
                    delay: Number(el.dataset.revealDelay || 0),
                    clearProps: 'transform,opacity,visibility',
                    scrollTrigger: { trigger: el, start: 'top 94%', once: true },
                });
            });

            gsap.utils.toArray('[data-reveal-group]').forEach((group) => {
                const items = Array.from(group.children);
                if (!items.length || !belowFold(group)) return;
                gsap.from(items, {
                    autoAlpha: 0,
                    y: 32,
                    duration: 0.6,
                    ease: 'power3.out',
                    stagger: 0.06,
                    clearProps: 'transform,opacity,visibility',
                    scrollTrigger: { trigger: group, start: 'top 92%', once: true },
                });
            });

            gsap.utils.toArray('[data-parallax]').forEach((el) => {
                gsap.to(el, {
                    yPercent: Number(el.dataset.parallax) || -12,
                    ease: 'none',
                    scrollTrigger: {
                        trigger: el.parentElement,
                        start: 'top bottom',
                        end: 'bottom top',
                        scrub: 0.6,
                    },
                });
            });
        }, root.value);
    };

    onMounted(build);

    onBeforeUnmount(() => {
        disposed = true;
        ctx?.revert();
        ctx = null;
    });

    return {
        /** Re-measure trigger positions after the layout changed (filtering, async content). */
        refresh: () => nextTick(() => trigger?.refresh()),
        rebuild: () => nextTick(build),
    };
}
