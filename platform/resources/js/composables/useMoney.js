import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

/** Format an integer-YER amount for display in the active locale. */
export function useMoney() {
    const page = usePage();
    const { locale } = useI18n();

    const currency = () => page.props?.app?.currency || 'YER';

    const format = (amount) => {
        const n = Number(amount || 0);
        const loc = locale.value === 'ar' ? 'ar-YE' : 'en-US';
        const formatted = new Intl.NumberFormat(loc, { maximumFractionDigits: 0 }).format(n);
        const unit = locale.value === 'ar' ? 'ريال' : currency();
        return locale.value === 'ar' ? `${formatted} ${unit}` : `${formatted} ${unit}`;
    };

    return { format, currency };
}
