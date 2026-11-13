import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

/**
 * Thin axios wrapper for the JSON API. Uses the session cookie (Sanctum SPA mode)
 * so no token juggling is needed inside the Inertia app; sends the current locale.
 */
const client = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    withCredentials: true,
});

const csrfCookie = axios.get('/sanctum/csrf-cookie', { withCredentials: true });

function recoverFromExpiredSession() {
    window.alert('انتهت جلسة الصفحة. سيتم تحديثها الآن.');
    window.location.reload();
}

client.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response?.status === 419) {
            recoverFromExpiredSession();
        }

        return Promise.reject(error);
    },
);

export function useApi() {
    const page = usePage();

    client.defaults.headers.common['X-Locale'] = page.props?.locale || 'ar';

    return {
        get: (url, config) => client.get(url, config).then((r) => r.data),
        post: (url, data, config) => csrfCookie.then(() => client.post(url, data, config)).then((r) => r.data),
        patch: (url, data, config) => client.patch(url, data, config).then((r) => r.data),
        delete: (url, config) => client.delete(url, config).then((r) => r.data),
        raw: client,
    };
}
