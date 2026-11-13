import { getCurrentInstance } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

export function useLocale() {
    const { locale } = useI18n();
    const page = usePage();
    const app = getCurrentInstance()?.appContext.config.globalProperties;

    const isRtl = () => locale.value === 'ar';

    const setLocale = (next) => {
        if (next === locale.value) return;
        app?.$applyLocale?.(next);

        // Persist server-side (cookie + user preference) and re-fetch shared props.
        router.reload({
            data: { lang: next },
            // Every server-localised prop must be listed, or it keeps the old language.
            only: ['locale', 'dir', 'catalog', 'auth', 'announcement', 'banners', 'featuredServices', 'governorates'],
            preserveScroll: true,
            preserveState: true,
        });
    };

    return { locale, isRtl, setLocale, supported: page.props?.supported_locales || ['ar', 'en'] };
}
