import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { createI18n } from 'vue-i18n';
import { registerSW } from 'virtual:pwa-register';
import ar from './i18n/ar.json';
import en from './i18n/en.json';

const appName = import.meta.env.VITE_APP_NAME || 'خدمات اليمن';

// Keep the development server free from stale service-worker caches.
if (import.meta.env.PROD) {
    registerSW({ immediate: true });
} else if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => registration.unregister());
    });
    if ('caches' in window) {
        caches.keys().then((keys) => keys.forEach((key) => caches.delete(key)));
    }
}

router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        window.alert('انتهت جلسة الصفحة. سيتم تحديثها الآن.');
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        const locale = props.initialPage.props.locale || 'ar';

        const i18n = createI18n({
            legacy: false,
            locale,
            fallbackLocale: 'en',
            messages: { ar, en },
        });

        // Keep <html dir/lang> in sync on every client-side navigation.
        const applyDir = (loc) => {
            const dir = loc === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.setAttribute('dir', dir);
            document.documentElement.setAttribute('lang', loc);
        };
        applyDir(locale);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n);

        app.config.globalProperties.$applyLocale = (loc) => {
            i18n.global.locale.value = loc;
            applyDir(loc);
        };

        app.mount(el);
    },

    progress: { color: '#CE1126', showSpinner: false },
});
