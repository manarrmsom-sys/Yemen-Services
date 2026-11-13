/**
 * Lazy loader for the animation stack.
 *
 * GSAP + ScrollTrigger are imported on demand so they never block first paint
 * on slow connections, and are skipped entirely for visitors who ask the OS
 * for reduced motion. The chunk is precached by the service worker, so
 * animations also work offline after the first visit.
 */
let motionPromise = null;

export function prefersReducedMotion() {
    return typeof window !== 'undefined'
        && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

export function loadMotion() {
    motionPromise ??= Promise.all([import('gsap'), import('gsap/ScrollTrigger')]).then(
        ([{ gsap }, { ScrollTrigger }]) => {
            gsap.registerPlugin(ScrollTrigger);
            // Mobile browsers resize the viewport when the URL bar hides; do not
            // rebuild every trigger for that.
            ScrollTrigger.config({ ignoreMobileResize: true });

            return { gsap, ScrollTrigger };
        },
    );

    return motionPromise;
}
