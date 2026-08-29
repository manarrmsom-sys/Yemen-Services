import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { createI18n } from 'vue-i18n';
import ar from './i18n/ar.json';
import en from './i18n/en.json';

const appName = import.meta.env.VITE_APP_NAME || 'خدمات اليمن';

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
