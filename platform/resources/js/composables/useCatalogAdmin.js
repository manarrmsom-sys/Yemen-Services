import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/composables/useApi';

/**
 * Shared state for the admin catalogue pages (categories and services):
 * loads the full catalogue and runs a write, surfacing the first server error.
 */
export function useCatalogAdmin() {
    const api = useApi();
    const { t } = useI18n();

    const categories = ref([]);
    const loading = ref(true);
    const error = ref('');
    const fieldErrors = ref({});
    const saving = ref(false);

    async function load() {
        try {
            categories.value = (await api.get('/admin/catalog')).data;
        } catch (e) {
            error.value = t('common.error');
        } finally {
            loading.value = false;
        }
    }

    /** Run a write, then reload. Returns true on success. */
    async function run(action) {
        saving.value = true;
        error.value = '';
        fieldErrors.value = {};
        try {
            await action(api);
            await load();
            return true;
        } catch (e) {
            fieldErrors.value = e.response?.data?.errors || {};
            error.value = Object.values(fieldErrors.value).flat()[0] || e.response?.data?.message || t('common.error');
            return false;
        } finally {
            saving.value = false;
        }
    }

    const fieldError = (key) => fieldErrors.value[key]?.[0];

    return { categories, loading, error, saving, load, run, fieldError };
}
