import axios from 'axios';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Saves one field without re-rendering the page.
 *
 * Inertia's router re-renders on every response, which throws away DOM state
 * the framework does not own — every expanded accordion collapses. Recording
 * losses means working down a nested list, so that is unusable.
 *
 * The caller gets the response back and updates whatever the server
 * recalculated. On failure the previous value is restored, so the field never
 * shows something the server did not accept.
 *
 * @param {string} url
 * @param {object} data
 * @param {{ onRevert?: () => void }} options
 * @returns {Promise<object|null>} response payload, or null when it failed
 */
export async function patchField(url, data, { onRevert } = {}) {
    try {
        const response = await axios.patch(url, data);

        return response.data ?? {};
    } catch (error) {
        onRevert?.();

        notify(messageFrom(error));

        return null;
    }
}

function messageFrom(error) {
    const data = error.response?.data;

    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }

    if (data?.message) {
        return data.message;
    }

    // No response at all: the server is unreachable or the request never left.
    return t('errors.save_failed');
}
