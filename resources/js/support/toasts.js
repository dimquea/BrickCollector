import { reactive } from 'vue';

/**
 * Transient notifications, rendered by ToastHost.
 *
 * Errors have to be visible without a page reload: a field that silently
 * refuses to save is worse than one that fails loudly.
 */
export const toasts = reactive([]);

let nextId = 1;

/**
 * @param {string} message
 * @param {'danger'|'success'|'warning'} variant
 * @param {number} timeout  milliseconds; 0 keeps it until dismissed
 */
export function notify(message, variant = 'danger', timeout = 6000) {
    const id = nextId++;

    toasts.push({ id, message, variant });

    if (timeout > 0) {
        setTimeout(() => dismiss(id), timeout);
    }

    return id;
}

export function dismiss(id) {
    const index = toasts.findIndex((toast) => toast.id === id);

    if (index !== -1) {
        toasts.splice(index, 1);
    }
}
